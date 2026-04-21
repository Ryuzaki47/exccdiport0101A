<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Payment;
use App\Models\StudentAssessment;
use App\Models\StudentPaymentTerm;
use App\Models\Transaction;
use App\Models\Workflow;
use App\Enums\PaymentStatus;
use App\Services\AccountService;
use App\Services\WorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PaymentController extends Controller
{
    private string $secretKey;
    private string $publicKey;
    private string $baseUrl = 'https://api.paymongo.com/v1';

    public function __construct(protected WorkflowService $workflowService)
    {
        $this->secretKey = config('services.paymongo.secret');
        $this->publicKey = config('services.paymongo.public');
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  PAYMENT CREATE PAGE
    // ─────────────────────────────────────────────────────────────────────────

    public function create(Request $request): Response
    {
        try {
            $user         = $request->user();
            $assessmentId = $request->query('assessment_id');

            $assessment = $assessmentId
                ? StudentAssessment::where('id', $assessmentId)
                    ->where('user_id', $user->id)
                    ->where('status', 'active')
                    ->first()
                : StudentAssessment::where('user_id', $user->id)
                    ->where('status', 'active')
                    ->latest()
                    ->first();

            $paymentTerms = $assessment
                ? StudentPaymentTerm::where('student_assessment_id', $assessment->id)
                    ->orderBy('term_order')
                    ->get()
                    ->map(fn ($term) => [
                        'id'         => $term->id,
                        'term_name'  => $term->term_name ?? 'Unknown Term',
                        'term_order' => $term->term_order ?? 0,
                        'percentage' => $term->percentage ?? 0,
                        'amount'     => (float) ($term->amount ?? 0),
                        'balance'    => (float) ($term->balance ?? 0),
                        'due_date'   => $term->due_date?->format('Y-m-d'),
                        'status'     => $term->status ?? 'unpaid',
                        'remarks'    => $term->remarks,
                    ])
                : collect();

            $pendingApprovalPayments = $assessment
                ? Transaction::where('user_id', $user->id)
                    ->where('kind', 'payment')
                    ->where('status', PaymentStatus::AWAITING_APPROVAL->value)
                    ->get()
                    ->map(fn ($txn) => [
                        'id'               => $txn->id,
                        'reference'        => $txn->reference ?? 'N/A',
                        'amount'           => (float) ($txn->amount ?? 0),
                        'selected_term_id' => data_get($txn->meta, 'selected_term_id'),
                        'term_name'        => data_get($txn->meta, 'term_name') ?? $txn->type ?? 'Payment',
                        'created_at'       => $txn->created_at?->toDateTimeString(),
                    ])
                : collect();

            $assessmentFormatted = $assessment ? [
                'id'                => $assessment->id,
                'assessment_number' => $assessment->assessment_number ?? 'N/A',
                'year_level'        => $assessment->year_level ?? 'Unknown',
                'semester'          => $assessment->semester ?? 'Unknown',
                'school_year'       => $assessment->school_year ?? 'Unknown',
                'total_assessment'  => (float) ($assessment->total_assessment ?? 0),
                'status'            => $assessment->status ?? 'active',
                'lec_units'         => $assessment->lec_units ?? 0,
                'lab_units'         => $assessment->lab_units ?? 0,
            ] : null;

            $isLiveMode = str_starts_with($this->secretKey, 'sk_live_');
            $availablePaymentMethods = $isLiveMode
                ? ['credit_card', 'debit_card', 'gcash', 'bank_transfer']
                : ['credit_card', 'debit_card', 'bank_transfer'];

            return Inertia::render('Payment/Create', [
                'student' => [
                    'id'         => $user->id,
                    'name'       => $user->name,
                    'account_id' => $user->account_id,
                    'course'     => $user->course,
                    'year_level' => $user->year_level,
                ],
                'assessment'              => $assessmentFormatted,
                'paymentTerms'            => $paymentTerms->values(),
                'pendingApprovalPayments' => $pendingApprovalPayments->values(),
                'preselectedTermId'       => $request->query('term_id') ? (int) $request->query('term_id') : null,
                'availablePaymentMethods' => $availablePaymentMethods,
            ]);
        } catch (\Throwable $e) {
            Log::error('PaymentController::create() failed', [
                'user_id'       => $request->user()?->id,
                'assessment_id' => $request->query('assessment_id'),
                'error'         => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  PAYMONGO — CREATE CHECKOUT SESSION
    // ─────────────────────────────────────────────────────────────────────────

    public function createCheckout(Request $request)
    {
        $validated = $request->validate([
            'amount'           => 'required|numeric|min:100',
            'description'      => 'required|string|max:255',
            'selected_term_id' => 'nullable|exists:student_payment_terms,id',
        ]);

        abort_if(empty($this->secretKey), 500, 'PayMongo secret key is not configured.');

        $user = $request->user();

        // ── DUPLICATE CHECKOUT SESSION GUARD ──────────────────────────────────
        if ($validated['selected_term_id']) {
            $stalePending = Payment::where('user_id', $user->id)
                ->where('status', 'pending')
                ->whereNotNull('paymongo_source_id')
                ->whereJsonContains('meta->selected_term_id', (int) $validated['selected_term_id'])
                ->where('created_at', '>=', now()->subMinutes(10))
                ->first();

            if ($stalePending) {
                $pmResponse = Http::withBasicAuth($this->secretKey, '')
                    ->get("{$this->baseUrl}/checkout_sessions/{$stalePending->paymongo_source_id}");

                $pmStatus = data_get($pmResponse->json(), 'data.attributes.status');

                if ($pmResponse->ok() && ! in_array($pmStatus, ['active'], true)) {
                    $stalePending->update(['status' => 'cancelled']);
                    Log::info('PayMongo session auto-expired, allowing new checkout', [
                        'user_id'         => $user->id,
                        'old_session_id'  => $stalePending->paymongo_source_id,
                        'paymongo_status' => $pmStatus,
                        'term_id'         => $validated['selected_term_id'],
                    ]);
                } elseif ($pmResponse->failed()) {
                    $stalePending->update(['status' => 'cancelled']);
                    Log::warning('PayMongo API unreachable during session check, expiring locally', [
                        'user_id'        => $user->id,
                        'old_session_id' => $stalePending->paymongo_source_id,
                        'http_status'    => $pmResponse->status(),
                    ]);
                } else {
                    return response()->json([
                        'error' => 'You have an open payment session for this term. Please complete it or wait a few minutes before trying again.',
                    ], 422);
                }
            }
        }

        // ── DUPLICATE AWAITING_APPROVAL GUARD ─────────────────────────────────
        if ($validated['selected_term_id']) {
            $alreadyPendingApproval = Transaction::where('user_id', $user->id)
                ->where('kind', 'payment')
                ->where('status', PaymentStatus::AWAITING_APPROVAL->value)
                ->whereJsonContains('meta->selected_term_id', (int) $validated['selected_term_id'])
                ->exists();

            if ($alreadyPendingApproval) {
                return response()->json([
                    'error' => 'A payment for this term is already awaiting accounting approval. Please wait for the current payment to be reviewed.',
                ], 422);
            }
        }

        $amountInCentavos = (int) round($validated['amount'] * 100);

        $termInfo = null;
        if ($validated['selected_term_id']) {
            $termInfo = StudentPaymentTerm::find($validated['selected_term_id']);

            if ($termInfo && $termInfo->assessment?->user_id !== $user->id) {
                return response()->json(['error' => 'Invalid payment term.'], 403);
            }
        }

        $response = Http::withBasicAuth($this->secretKey, '')
            ->post("{$this->baseUrl}/checkout_sessions", [
                'data' => [
                    'attributes' => [
                        'billing' => [
                            'name'  => $user->name,
                            'email' => $user->email,
                            'phone' => $user->phone ?? '09000000000',
                        ],
                        'line_items' => [[
                            'currency' => 'PHP',
                            'amount'   => $amountInCentavos,
                            'name'     => $validated['description'],
                            'quantity' => 1,
                        ]],
                        'payment_method_types' => $this->getPaymentMethodTypes(),
                        'success_url' => url('/payment/success') . '?session_id={CHECKOUT_SESSION_ID}',
                        'cancel_url'  => url('/payment/cancel')  . '?session_id={CHECKOUT_SESSION_ID}',
                        'description' => $validated['description'],
                    ],
                ],
            ]);

        if ($response->failed()) {
            Log::error('PayMongo checkout session failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
                'user'   => $user->id,
            ]);

            return response()->json([
                'error' => 'Payment session could not be created. Please try again.',
            ], 500);
        }

        $session         = $response->json('data');
        $paymentIntentId = data_get($session, 'attributes.payment_intent.id');

        Payment::create([
            'user_id'               => $user->id,
            'student_assessment_id' => $termInfo?->student_assessment_id ?? null,
            'amount'                => $validated['amount'],
            'description'           => $validated['description'],
            'payment_method'        => 'paymongo_checkout',
            'status'                => 'pending',
            'paymongo_source_id'    => $session['id'], // stores checkout_session id (cs_...)
            'meta'                  => [
                'payment_method'       => 'paymongo',
                'selected_term_id'     => $validated['selected_term_id'],
                'term_name'            => $termInfo?->term_name ?? 'Payment',
                'paymongo_checkout'    => true,
                'paymongo_intent_id'   => $paymentIntentId,
            ],
        ]);

        if ($termInfo && $paymentIntentId) {
            $termInfo->update(['payment_intent_id' => $paymentIntentId]);

            Log::info('PayMongo payment_intent_id stored for webhook linkage', [
                'payment_term_id'   => $termInfo->id,
                'user_id'           => $user->id,
                'payment_intent_id' => $paymentIntentId,
                'session_id'        => $session['id'],
            ]);
        }

        return response()->json([
            'checkout_url' => data_get($session, 'attributes.checkout_url'),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  PAYMONGO — SUCCESS REDIRECT
    // ─────────────────────────────────────────────────────────────────────────

    public function success(Request $request)
    {
        $sessionId = $request->query('session_id');

        Log::info('PayMongo success redirect received', [
            'session_id'  => $sessionId,
            'auth_user'   => auth()->id(),
            'referrer'    => $request->header('referer'),
        ]);

        if (! $sessionId) {
            Log::warning('PayMongo success redirect missing session_id');
            return redirect()->route('student.account')
                ->with('flash.error', 'Payment session not found. Please check your payment history or contact accounting.');
        }

        // Auth check — if unauthenticated, store session_id in the session and
        // redirect to login. After login, the intended URL will include session_id.
        if (! auth()->check()) {
            Log::warning('PayMongo success: unauthenticated user, redirecting to login', [
                'session_id' => $sessionId,
            ]);
            // Store the full return URL so after login it goes back here with query param
            session()->put('url.intended', route('payment.success') . '?session_id=' . urlencode($sessionId));
            return redirect()->route('login')
                ->with('flash.info', 'Please log in to complete your payment confirmation.');
        }

        $payment = Payment::where('paymongo_source_id', $sessionId)->first();

        if (! $payment) {
            // The webhook may have already processed this and linked it differently.
            // Try to find via the PayMongo API to get the real session ID.
            Log::warning('PayMongo success: no local payment record for session_id', [
                'session_id' => $sessionId,
                'user_id'    => auth()->id(),
            ]);

            // Attempt to verify via API — if payment is legit, create the record
            $response = Http::withBasicAuth($this->secretKey, '')
                ->get("{$this->baseUrl}/checkout_sessions/{$sessionId}");

            if (! $response->ok()) {
                Log::error('PayMongo success: API verification failed and no local record', [
                    'session_id'  => $sessionId,
                    'http_status' => $response->status(),
                ]);
                return redirect()->route('student.account')
                    ->with('flash.error', 'Payment record not found. If you were charged, please contact the accounting office with session ID: ' . $sessionId);
            }

            $session             = $response->json('data');
            $paymentIntentStatus = data_get($session, 'attributes.payment_intent.attributes.status');
            $paymentIntentId     = data_get($session, 'attributes.payment_intent.id');

            if ($paymentIntentStatus !== 'succeeded') {
                return redirect()->route('student.account')
                    ->with('flash.warning', 'Payment did not complete. No charges were made.');
            }

            // Check if webhook already created the transaction
            $existingTxn = Transaction::where('reference', "PAY-{$paymentIntentId}")->first();
            if ($existingTxn) {
                return redirect()->route('student.account', ['tab' => 'history'])
                    ->with('flash.info', 'Your payment has been received and is awaiting accounting review.');
            }

            // Payment succeeded on PayMongo but we have no local record — log critical
            Log::critical('PayMongo success: payment succeeded but no local Payment row exists', [
                'session_id'        => $sessionId,
                'payment_intent_id' => $paymentIntentId,
                'user_id'           => auth()->id(),
                'amount'            => data_get($session, 'attributes.amount'),
            ]);

            return redirect()->route('student.account')
                ->with('flash.error', 'Payment received but could not be matched to your account. Please contact accounting with reference: ' . $paymentIntentId);
        }

        if ($payment->status === 'completed') {
            Log::info('PayMongo success: session already processed, skipping', [
                'session_id' => $sessionId,
                'payment_id' => $payment->id,
            ]);
            return redirect()->route('student.account', ['tab' => 'history'])
                ->with('flash.info', 'This payment was already recorded and is awaiting accounting review.');
        }

        $response = Http::withBasicAuth($this->secretKey, '')
            ->get("{$this->baseUrl}/checkout_sessions/{$sessionId}");

        if (! $response->ok()) {
            Log::error('PayMongo session verification failed', [
                'session_id'  => $sessionId,
                'http_status' => $response->status(),
                'body'        => $response->body(),
            ]);

            $payment->update([
                'status' => 'pending',
                'meta'   => array_merge($payment->meta ?? [], [
                    'verification_failed' => true,
                    'verification_error'  => "HTTP {$response->status()}",
                    'verify_attempted_at' => now()->toISOString(),
                ]),
            ]);

            return redirect()->route('student.account', ['tab' => 'history'])
                ->with('flash.warning',
                    'Your payment may have been processed but we could not verify it automatically. ' .
                    'Please contact the accounting office with your reference and they will confirm it manually.'
                );
        }

        $session             = $response->json('data');
        $paymentIntentStatus = data_get($session, 'attributes.payment_intent.attributes.status');
        $paymentIntentId     = data_get($session, 'attributes.payment_intent.id');

        if ($paymentIntentStatus !== 'succeeded') {
            Log::warning('PayMongo payment not succeeded on redirect', [
                'session_id' => $sessionId,
                'status'     => $paymentIntentStatus,
            ]);
            $payment->update(['status' => 'cancelled']);
            return redirect()->route('student.account')
                ->with('flash.warning', 'Payment did not complete. No charges were made. You can try again.');
        }

        $existingTransaction = Transaction::where('reference', "PAY-{$paymentIntentId}")->first();

        if ($existingTransaction) {
            Log::warning('PayMongo success: transaction already exists for intent', [
                'session_id'     => $sessionId,
                'payment_id'     => $payment->id,
                'transaction_id' => $existingTransaction->id,
            ]);
            $payment->update(['status' => 'completed', 'paymongo_intent_id' => $paymentIntentId]);
            return redirect()->route('student.account', ['tab' => 'history'])
                ->with('flash.info', 'Payment already recorded. Awaiting accounting review.');
        }

        $user = auth()->user();

        if (! $user) {
            Log::error('PayMongo success: no authenticated user on redirect', [
                'session_id' => $sessionId,
                'payment_id' => $payment->id,
            ]);
            return redirect()->route('login')
                ->with('flash.error', 'Your session expired. Please log in and check your payment history.');
        }

        $transaction = null;

        DB::transaction(function () use ($payment, $user, $paymentIntentId, $sessionId, &$transaction) {
            // Re-fetch payment inside transaction to avoid stale meta merge
            $payment = Payment::lockForUpdate()->find($payment->id);

            $payment->update([
                'status'             => 'completed',
                'description'        => 'PayMongo payment — awaiting accounting review',
                'paymongo_intent_id' => $paymentIntentId,
            ]);

            $termId   = $payment->meta['selected_term_id'] ?? null;
            $termInfo = $termId ? StudentPaymentTerm::find($termId) : null;

            $transaction = Transaction::create([
                'user_id'         => $user->id,
                'kind'            => 'payment',
                'status'          => PaymentStatus::AWAITING_APPROVAL->value,
                'payment_channel' => 'paymongo',
                'amount'          => $payment->amount,
                'reference'       => "PAY-{$paymentIntentId}",
                'type'            => 'Payment',
                'paid_at'         => now(),
                'year'            => now()->year,
                'semester'        => $termInfo?->assessment?->semester ?? null,
                'meta'            => [
                    'description'         => $payment->description,
                    'paymongo_session_id' => $sessionId,
                    'paymongo_intent_id'  => $paymentIntentId,
                    'term_name'           => $payment->meta['term_name'] ?? 'Payment',
                    'selected_term_id'    => $termId,
                    'payment_method'      => 'paymongo',
                    'assessment_id'       => $termInfo?->assessment?->id ?? null,
                    'requires_approval'   => true,
                ],
            ]);

            Log::info('PayMongo payment submitted for accounting review', [
                'user_id'        => $user->id,
                'transaction_id' => $transaction->id,
                'amount'         => $payment->amount,
                'term_id'        => $termId,
                'session_id'     => $sessionId,
            ]);
        });

        // ✅ FIX: Workflow started AFTER transaction commits — failure here doesn't rollback payment record
        if ($transaction) {
            $this->startPaymentApprovalWorkflow($transaction->id, $user->id);
        }

        return redirect()->route('student.account', ['tab' => 'history'])
            ->with('flash.success', 'Payment received! Your payment is now awaiting verification by accounting. You will be notified once it is approved.');
    }

    public function cancel(Request $request)
    {
        $sessionId = $request->query('session_id');

        if ($sessionId) {
            $cancelled = Payment::where('paymongo_source_id', $sessionId)
                ->where('status', 'pending')
                ->update(['status' => 'cancelled']);

            Log::info('PayMongo checkout cancelled by student', [
                'session_id'     => $sessionId,
                'rows_cancelled' => $cancelled,
                'user_id'        => auth()->id(),
            ]);
        }

        return redirect()->route('student.account')
            ->with('flash.warning', 'Payment was cancelled. No charges were made. You can try again.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  PROOF OF PAYMENT UPLOAD
    // ─────────────────────────────────────────────────────────────────────────

    public function showProofForm(Request $request, Transaction $transaction): Response
    {
        $user = $request->user();

        if ($transaction->user_id !== $user->id) {
            abort(403, 'Unauthorized access to this transaction.');
        }

        if ($transaction->status !== PaymentStatus::PENDING->value) {
            return redirect()->route('student.account')
                ->with('error', 'This payment is not waiting for proof.');
        }

        return Inertia::render('Payment/ProofUpload', [
            'transaction' => [
                'id'             => $transaction->id,
                'amount'         => (float) $transaction->amount,
                'payment_method' => $transaction->payment_channel,
                'term_name'      => $transaction->meta['term_name'] ?? 'Payment',
                'description'    => $transaction->meta['description'] ?? null,
                'created_at'     => $transaction->created_at,
            ],
        ]);
    }

    public function uploadProof(Request $request, Transaction $transaction)
    {
        $user = $request->user();

        if ($transaction->user_id !== $user->id) {
            abort(403, 'Unauthorized access to this transaction.');
        }

        $validated = $request->validate([
            'proof_of_payment' => 'required|file|mimes:pdf,jpg,jpeg,png,webp|max:5120',
        ]);

        $file     = $validated['proof_of_payment'];
        $filename = 'proof_' . $transaction->id . '_' . time() . '.' . $file->getClientOriginalExtension();
        $filepath = $file->storeAs('payment_proofs', $filename, 'public');

        $transaction->update([
            'status' => PaymentStatus::AWAITING_APPROVAL->value,
            'meta'   => array_merge($transaction->meta ?? [], [
                'proof_of_payment'  => $filepath,
                'proof_uploaded_at' => now()->toIso8601String(),
            ]),
        ]);

        try {
            // ✅ FIX: Workflow started AFTER transaction update — failure here doesn't lose proof record
            $this->startPaymentApprovalWorkflow($transaction->id, $user->id);

            return redirect()->route('student.account', ['tab' => 'history'])
                ->with('success', 'Proof of payment uploaded. Your payment is now awaiting verification.');

        } catch (\Exception $e) {
            Log::error('Proof upload workflow failed (but proof saved)', [
                'transaction_id' => $transaction->id,
                'error'          => $e->getMessage(),
            ]);

            return redirect()->route('student.account', ['tab' => 'history'])
                ->with('info', 'Proof uploaded. Workflow setup had an issue, but accounting will review your payment.');
        }
    }

    private function startPaymentApprovalWorkflow(int $transactionId, int $userId): void
    {
        $workflow = Workflow::active()
            ->where('type', 'payment_approval')
            ->first();

        if (! $workflow) {
            // ✅ FIX: Log the error but DO NOT throw — this must not rollback the payment transaction.
            // The payment is real money already charged. The transaction record must survive.
            Log::critical('No active payment_approval workflow found. Transaction recorded but workflow NOT started.', [
                'transaction_id' => $transactionId,
                'user_id'        => $userId,
                'action_required' => 'Run: php artisan db:seed --class=PaymentApprovalWorkflowSeeder',
            ]);
            return; // ← do not throw
        }

        $transaction = Transaction::findOrFail($transactionId);
        $this->workflowService->startWorkflow($workflow, $transaction, $userId);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  BANK TRANSFER — FIX Bug #3 (auth + user_id) and Bug #4 (authorization)
    // ─────────────────────────────────────────────────────────────────────────

    public function getBankDetails()
    {
        return response()->json([
            'bank_details' => [
                'account_name'   => config('services.bank.account_name', 'CCDI School'),
                'account_number' => config('services.bank.account_number', '1234-5678-9012'),
                'bank_name'      => config('services.bank.bank_name', 'PNB'),
            ],
        ]);
    }

    /**
     * Submit a manual bank transfer payment for the authenticated student.
     */
    public function submitBankTransfer(Request $request)
    {
        try {
            $user = $request->user();

            if (! $user) {
                Log::error('Bank transfer: user not authenticated');
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $validated = $request->validate([
                'amount'           => 'required|numeric|min:100',
                'reference_number' => 'required|string|max:100',
                'selected_term_id' => 'nullable|exists:student_payment_terms,id',
            ]);

            $term = null;

            if ($validated['selected_term_id']) {
                $term = StudentPaymentTerm::find($validated['selected_term_id']);
                if (! $term || $term->assessment?->user_id !== $user->id) {
                    Log::warning('Bank transfer: invalid term access', [
                        'user_id' => $user->id,
                        'term_id' => $validated['selected_term_id'],
                    ]);
                    abort(403, 'Invalid payment term.');
                }
            }

            $assessment = $term?->assessment
                ?? StudentAssessment::where('user_id', $user->id)
                    ->where('status', 'active')
                    ->latest()
                    ->first();

            Log::info('Bank transfer: creating records', [
                'user_id'          => $user->id,
                'assessment_id'    => $assessment?->id,
                'amount'           => $validated['amount'],
                'reference_number' => $validated['reference_number'],
            ]);

            $payment     = null;
            $transaction = null;

            DB::transaction(function () use ($user, $assessment, $term, $validated, &$payment, &$transaction) {
                $payment = Payment::create([
                    'user_id'               => $user->id,
                    'student_assessment_id' => $assessment?->id,
                    'amount'                => $validated['amount'],
                    'payment_method'        => 'bank_transfer',
                    'status'                => 'pending',
                    'description'           => 'Bank Transfer - Ref: ' . $validated['reference_number'],
                    'meta'                  => [
                        'reference_number' => $validated['reference_number'],
                        'selected_term_id' => $validated['selected_term_id'] ?? null,
                        'term_name'        => $term?->term_name ?? 'Payment',
                    ],
                ]);

                // ── IMPORTANT: Only use fields in Transaction::$fillable ──────────
                // Fillable: user_id, account_id, fee_id, reference, payment_channel,
                //           kind, type, amount, status, paid_at, meta, year, semester
                // NOT fillable: student_assessment_id, payment_method
                $transaction = Transaction::create([
                    'user_id'         => $user->id,
                    'kind'            => 'payment',
                    'type'            => 'payment',
                    'payment_channel' => 'bank_transfer',
                    'status'          => PaymentStatus::AWAITING_PROOF->value, // 'awaiting_proof'
                    'amount'          => $validated['amount'],
                    'reference'       => 'BT-' . strtoupper($validated['reference_number']),
                    'year'            => now()->year,
                    'semester'        => $term?->assessment?->semester ?? null,
                    'meta'            => [
                        'reference_number' => $validated['reference_number'],
                        'selected_term_id' => $validated['selected_term_id'] ?? null,
                        'term_name'        => $term?->term_name ?? 'Payment',
                        'payment_id'       => $payment->id,
                        'requires_proof'   => true,
                    ],
                ]);
            });

            Log::info('Bank transfer: success', [
                'payment_id'     => $payment->id,
                'transaction_id' => $transaction->id,
            ]);

            return response()->json([
                'message'        => 'Bank transfer submitted successfully. Please upload your proof of payment.',
                'transaction_id' => $transaction->id,
            ]);

        } catch (\Exception $e) {
            Log::error('Bank transfer error', [
                'user_id' => $request->user()?->id,
                'error'   => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);

            return response()->json([
                'error' => 'Failed to submit bank transfer: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function checkStatus(Request $request)
    {
        $request->validate(['payment_id' => 'required|exists:payments,id']);

        $payment = Payment::where('id', $request->payment_id)
            ->where('user_id', $request->user()->id) // ownership check
            ->firstOrFail();

        return response()->json(['status' => $payment->status]);
    }

    /**
     * FIX Bug #4: Admin-only authorization added.
     */
    public function verifyBankTransfer(Request $request, Payment $payment)
    {
        $this->authorize('verifyPayment', $payment); // requires PaymentPolicy::verifyPayment() — admin only

        $request->validate(['verified' => 'required|boolean']);

        $payment->update([
            'status' => $request->verified ? 'completed' : 'failed',
        ]);

        return response()->json(['message' => 'Payment verified successfully.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Live mode: GCash, Card, PayMaya
     * Test mode: Card only (PayMongo restriction)
     */
    private function getPaymentMethodTypes(): array
    {
        $isLiveMode = str_starts_with($this->secretKey, 'sk_live_');

        return $isLiveMode
            ? ['gcash', 'card', 'paymaya']
            : ['card'];
    }
}