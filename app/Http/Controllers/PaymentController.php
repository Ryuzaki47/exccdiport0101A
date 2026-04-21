<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\StudentAssessment;
use App\Models\StudentPaymentTerm;
use App\Models\Transaction;
use App\Enums\PaymentStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class PaymentController extends Controller
{
    private string $secretKey;
    private string $publicKey;
    private string $baseUrl = 'https://api.paymongo.com/v1';

    public function __construct()
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

            // Shown to student as information only — no longer blocks new payments.
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
        // Prevents double-charging for the same term within 10 minutes.
        // FIXED: In test mode PayMongo keeps session.status = "active" even after
        // payment completes. We now check paid_at and payments[0].status to detect
        // already-completed sessions instead of relying on status alone.
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

                if ($pmResponse->ok()) {
                    $pmData         = $pmResponse->json('data');
                    $pmStatus       = data_get($pmData, 'attributes.status');
                    $pmPaidAt       = data_get($pmData, 'attributes.paid_at');
                    $pmFirstPayment = data_get($pmData, 'attributes.payments.0.attributes.status');

                    // Session is "done" if: not active, OR has paid_at, OR first payment is "paid".
                    // Test mode keeps status="active" after payment — use paid_at as real signal.
                    $sessionDone = $pmStatus !== 'active'
                        || $pmPaidAt !== null
                        || $pmFirstPayment === 'paid';

                    if ($sessionDone) {
                        $stalePending->update(['status' => 'cancelled']);
                        Log::info('PayMongo stale session resolved, allowing new checkout', [
                            'user_id'        => $user->id,
                            'old_session_id' => $stalePending->paymongo_source_id,
                            'pm_status'      => $pmStatus,
                            'paid_at'        => $pmPaidAt,
                            'first_payment'  => $pmFirstPayment,
                        ]);
                    } else {
                        return response()->json([
                            'error' => 'You have an open payment session for this term. Please complete it or wait a few minutes before trying again.',
                        ], 422);
                    }
                } else {
                    $stalePending->update(['status' => 'cancelled']);
                    Log::warning('PayMongo API unreachable during session check, expiring locally', [
                        'user_id'        => $user->id,
                        'old_session_id' => $stalePending->paymongo_source_id,
                        'http_status'    => $pmResponse->status(),
                    ]);
                }
            }
        }

        // NOTE: The AWAITING_APPROVAL guard has been removed.
        // Approval is now observation-only for accounting — it does NOT block new payments.
        // This prevents students from being permanently locked out due to failed webhook/redirect flows.

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
            'paymongo_source_id'    => $session['id'],
            'meta'                  => [
                'payment_method'     => 'paymongo',
                'selected_term_id'   => $validated['selected_term_id'],
                'term_name'          => $termInfo?->term_name ?? 'Payment',
                'paymongo_checkout'  => true,
                'paymongo_intent_id' => $paymentIntentId,
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
            'session_id' => $sessionId,
            'auth_user'  => auth()->id(),
            'referrer'   => $request->header('referer'),
        ]);

        if (! $sessionId) {
            Log::warning('PayMongo success redirect missing session_id');
            return redirect()->route('student.account')
                ->with('flash.error', 'Payment session not found. Please check your payment history or contact accounting.');
        }

        if (! auth()->check()) {
            Log::warning('PayMongo success: unauthenticated user, redirecting to login', [
                'session_id' => $sessionId,
            ]);
            session()->put('url.intended', route('payment.success') . '?session_id=' . urlencode($sessionId));
            return redirect()->route('login')
                ->with('flash.info', 'Please log in to complete your payment confirmation.');
        }

        $user    = auth()->user();
        $payment = Payment::where('paymongo_source_id', $sessionId)->first();

        // ── VERIFY PAYMENT VIA PAYMONGO API ───────────────────────────────────
        // Always call the API — do not rely on local Payment row status alone.
        // This handles: missing Payment row, test mode "active" status quirk, race conditions.
        $apiResponse = Http::withBasicAuth($this->secretKey, '')
            ->get("{$this->baseUrl}/checkout_sessions/{$sessionId}");

        if (! $apiResponse->ok()) {
            Log::error('PayMongo session API verification failed', [
                'session_id'  => $sessionId,
                'http_status' => $apiResponse->status(),
                'has_payment' => $payment !== null,
            ]);
            return redirect()->route('student.account')
                ->with('flash.error', 'Could not verify your payment. If you were charged, please contact accounting with session ID: ' . $sessionId);
        }

        $session             = $apiResponse->json('data');
        $paymentIntentId     = data_get($session, 'attributes.payment_intent.id');
        $paymentIntentStatus = data_get($session, 'attributes.payment_intent.attributes.status');
        $firstPaymentStatus  = data_get($session, 'attributes.payments.0.attributes.status');
        $sessionPaidAt       = data_get($session, 'attributes.paid_at');

        // FIXED: Test mode keeps payment_intent.status = "processing" even after card charged.
        // Accept as paid if: intent succeeded, OR first payment is "paid", OR session has paid_at.
        $paymentPaid = $paymentIntentStatus === 'succeeded'
            || $firstPaymentStatus === 'paid'
            || $sessionPaidAt !== null;

        if (! $paymentPaid) {
            Log::warning('PayMongo success redirect: payment not confirmed as paid', [
                'session_id'     => $sessionId,
                'intent_status'  => $paymentIntentStatus,
                'payment_status' => $firstPaymentStatus,
                'paid_at'        => $sessionPaidAt,
            ]);

            if ($payment) {
                $payment->update(['status' => 'cancelled']);
            }

            return redirect()->route('student.account')
                ->with('flash.warning', 'Payment did not complete. No charges were made. You can try again.');
        }

        // ── IDEMPOTENCY GUARD ─────────────────────────────────────────────────
        $existingTransaction = $paymentIntentId
            ? Transaction::where('reference', "PAY-{$paymentIntentId}")->first()
            : null;

        if ($existingTransaction) {
            Log::info('PayMongo success: transaction already exists, skipping creation', [
                'session_id'     => $sessionId,
                'transaction_id' => $existingTransaction->id,
            ]);

            if ($payment && $payment->status !== 'completed') {
                $payment->update(['status' => 'completed', 'paymongo_intent_id' => $paymentIntentId]);
            }

            return redirect()->route('student.account', ['tab' => 'history'])
                ->with('flash.success', 'Payment received! Awaiting accounting verification.');
        }

        // ── ALSO CHECK IF PAYMENT ROW IS ALREADY COMPLETED ───────────────────
        if ($payment && $payment->status === 'completed') {
            Log::info('PayMongo success: payment row already completed', [
                'session_id' => $sessionId,
                'payment_id' => $payment->id,
            ]);
            return redirect()->route('student.account', ['tab' => 'history'])
                ->with('flash.success', 'Payment received! Awaiting accounting verification.');
        }

        // ── CREATE TRANSACTION RECORD ─────────────────────────────────────────
        $amountInPesos = data_get($session, 'attributes.amount') / 100;
        $termId        = $payment?->meta['selected_term_id'] ?? null;
        $termInfo      = $termId ? StudentPaymentTerm::find($termId) : null;
        $description   = data_get($session, 'attributes.description') ?? 'PayMongo Payment';

        $transaction = null;

        DB::transaction(function () use (
            $payment, $user, $paymentIntentId, $sessionId,
            $amountInPesos, $termId, $termInfo, $description, &$transaction
        ) {
            if ($payment) {
                $payment = Payment::lockForUpdate()->find($payment->id);
                $payment->update([
                    'status'             => 'completed',
                    'paymongo_intent_id' => $paymentIntentId,
                    'description'        => $description . ' — recorded on redirect',
                ]);
            } else {
                // No Payment row — createCheckout() failed to persist it.
                // Recover: create it now so the audit trail is complete.
                Log::warning('PayMongo success: no local Payment row, creating recovery record', [
                    'session_id'        => $sessionId,
                    'payment_intent_id' => $paymentIntentId,
                    'user_id'           => $user->id,
                    'amount'            => $amountInPesos,
                ]);

                Payment::create([
                    'user_id'            => $user->id,
                    'amount'             => $amountInPesos,
                    'description'        => $description . ' (recovered)',
                    'payment_method'     => 'paymongo_checkout',
                    'status'             => 'completed',
                    'paymongo_source_id' => $sessionId,
                    'paymongo_intent_id' => $paymentIntentId,
                    'meta'               => [
                        'payment_method'    => 'paymongo',
                        'paymongo_checkout' => true,
                        'recovered'         => true,
                        'selected_term_id'  => $termId,
                    ],
                ]);
            }

            $transaction = Transaction::create([
                'user_id'         => $user->id,
                'kind'            => 'payment',
                'status'          => PaymentStatus::AWAITING_APPROVAL->value,
                'payment_channel' => 'paymongo',
                'amount'          => $amountInPesos,
                'reference'       => "PAY-{$paymentIntentId}",
                'type'            => 'Payment',
                'paid_at'         => now(),
                'year'            => now()->year,
                'semester'        => $termInfo?->assessment?->semester ?? null,
                'meta'            => [
                    'description'         => $description,
                    'paymongo_session_id' => $sessionId,
                    'paymongo_intent_id'  => $paymentIntentId,
                    'term_name'           => $termInfo?->term_name ?? ($payment?->meta['term_name'] ?? 'Payment'),
                    'selected_term_id'    => $termId,
                    'payment_method'      => 'paymongo',
                    'assessment_id'       => $termInfo?->student_assessment_id ?? null,
                    'requires_approval'   => true,
                ],
            ]);

            Log::info('PayMongo payment recorded — awaiting accounting verification', [
                'user_id'        => $user->id,
                'transaction_id' => $transaction->id,
                'amount'         => $amountInPesos,
                'term_id'        => $termId,
                'session_id'     => $sessionId,
            ]);
        });

        if ($transaction) {
            $this->startPaymentApprovalWorkflow($transaction->id, $user->id);
        }

        return redirect()->route('student.account', ['tab' => 'history'])
            ->with('flash.success', 'Payment received! Your payment is awaiting accounting verification. You will be notified once confirmed.');
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
    //  BANK TRANSFER
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

            $paymentRecord = null;
            $transaction   = null;

            DB::transaction(function () use ($user, $assessment, $term, $validated, &$paymentRecord, &$transaction) {
                $paymentRecord = Payment::create([
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

                // Only fields in Transaction::$fillable:
                // user_id, account_id, fee_id, reference, payment_channel,
                // kind, type, amount, status, paid_at, meta, year, semester
                $transaction = Transaction::create([
                    'user_id'         => $user->id,
                    'kind'            => 'payment',
                    'type'            => 'payment',
                    'payment_channel' => 'bank_transfer',
                    'status'          => PaymentStatus::AWAITING_PROOF->value,
                    'amount'          => $validated['amount'],
                    'reference'       => 'BT-' . strtoupper($validated['reference_number']),
                    'year'            => now()->year,
                    'semester'        => $term?->assessment?->semester ?? null,
                    'meta'            => [
                        'reference_number' => $validated['reference_number'],
                        'selected_term_id' => $validated['selected_term_id'] ?? null,
                        'term_name'        => $term?->term_name ?? 'Payment',
                        'payment_id'       => $paymentRecord->id,
                        'requires_proof'   => true,
                    ],
                ]);
            });

            Log::info('Bank transfer submitted', [
                'payment_id'     => $paymentRecord->id,
                'transaction_id' => $transaction->id,
                'user_id'        => $user->id,
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
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        return response()->json(['status' => $payment->status]);
    }

    public function verifyBankTransfer(Request $request, Payment $payment)
    {
        $this->authorize('verifyPayment', $payment);

        $request->validate(['verified' => 'required|boolean']);

        $payment->update([
            'status' => $request->verified ? 'completed' : 'failed',
        ]);

        return response()->json(['message' => 'Payment verified successfully.']);
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
            $this->startPaymentApprovalWorkflow($transaction->id, $user->id);

            return redirect()->route('student.account', ['tab' => 'history'])
                ->with('success', 'Proof of payment uploaded. Your payment is now awaiting verification.');

        } catch (\Exception $e) {
            Log::error('Proof upload workflow failed (but proof saved)', [
                'transaction_id' => $transaction->id,
                'error'          => $e->getMessage(),
            ]);

            return redirect()->route('student.account', ['tab' => 'history'])
                ->with('info', 'Proof uploaded. Accounting will review your payment shortly.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    private function startPaymentApprovalWorkflow(int $transactionId, int $userId): void
    {
        try {
            $workflow = \App\Models\Workflow::active()
                ->where('type', 'payment_approval')
                ->first();

            if (! $workflow) {
                Log::warning('No active payment_approval workflow found. Transaction recorded without workflow.', [
                    'transaction_id' => $transactionId,
                    'user_id'        => $userId,
                ]);
                return;
            }

            $transaction = Transaction::findOrFail($transactionId);
            app(\App\Services\WorkflowService::class)->startWorkflow($workflow, $transaction, $userId);

        } catch (\Throwable $e) {
            // Workflow failure must NEVER affect the Transaction record.
            Log::error('Payment approval workflow start failed (transaction safe)', [
                'transaction_id' => $transactionId,
                'error'          => $e->getMessage(),
            ]);
        }
    }

    private function getPaymentMethodTypes(): array
    {
        $isLiveMode = str_starts_with($this->secretKey, 'sk_live_');

        return $isLiveMode
            ? ['gcash', 'card', 'paymaya']
            : ['card'];
    }
}