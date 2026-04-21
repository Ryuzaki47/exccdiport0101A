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
        // Expanded window to 30 minutes to catch longer payment sessions.
        // Also checks ANY pending session for the term, not just within 10 min.
        if ($validated['selected_term_id']) {
            $stalePending = Payment::where('user_id', $user->id)
                ->where('status', 'pending')
                ->whereNotNull('paymongo_source_id')
                ->whereJsonContains('meta->selected_term_id', (int) $validated['selected_term_id'])
                ->where('created_at', '>=', now()->subMinutes(30))
                ->latest()
                ->first();

            if ($stalePending) {
                $pmResponse = Http::withBasicAuth($this->secretKey, '')
                    ->timeout(10)
                    ->retry(2, 500)
                    ->get("{$this->baseUrl}/checkout_sessions/{$stalePending->paymongo_source_id}");

                if ($pmResponse->ok()) {
                    $pmData         = $pmResponse->json('data');
                    $pmStatus       = data_get($pmData, 'attributes.status');
                    $pmPaidAt       = data_get($pmData, 'attributes.paid_at');
                    $pmFirstPayment = data_get($pmData, 'attributes.payments.0.attributes.status');

                    $sessionDone = $pmStatus !== 'active'
                        || $pmPaidAt !== null
                        || $pmFirstPayment === 'paid';

                    if ($sessionDone) {
                        // Session is done — mark it completed/cancelled so we can proceed
                        $newStatus = ($pmPaidAt !== null || $pmFirstPayment === 'paid') ? 'completed' : 'cancelled';
                        $stalePending->update(['status' => $newStatus]);

                        Log::info('PayMongo stale session resolved, allowing new checkout', [
                            'user_id'        => $user->id,
                            'old_session_id' => $stalePending->paymongo_source_id,
                            'pm_status'      => $pmStatus,
                            'paid_at'        => $pmPaidAt,
                            'first_payment'  => $pmFirstPayment,
                            'new_local_status' => $newStatus,
                        ]);
                    } else {
                        return response()->json([
                            'error' => 'You have an open payment session for this term. Please complete it or wait a few minutes before trying again.',
                        ], 422);
                    }
                } else {
                    // PayMongo unreachable — expire locally and allow proceeding
                    $stalePending->update(['status' => 'cancelled']);
                    Log::warning('PayMongo API unreachable during session check, expiring locally', [
                        'user_id'        => $user->id,
                        'old_session_id' => $stalePending->paymongo_source_id,
                        'http_status'    => $pmResponse->status(),
                    ]);
                }
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
            ->timeout(15)
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
                        'send_email_receipt' => false,
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
        ]);

        // ── GUARD: missing or unsubstituted session ID ────────────────────────
        // If PayMongo didn't substitute the placeholder, we get the literal string.
        if (! $sessionId || $sessionId === '{CHECKOUT_SESSION_ID}') {
            Log::warning('PayMongo success redirect: missing or unsubstituted session_id', [
                'raw_session_id' => $sessionId,
            ]);
            return redirect()->route('student.account')
                ->with('flash.warning', 'Payment is being processed. Please check your Payment History tab in a few moments. If it doesn\'t appear, contact accounting.');
        }

        // ── GUARD: unauthenticated ────────────────────────────────────────────
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

        // ── FAST PATH: Already processed ──────────────────────────────────────
        // Check local state first to avoid unnecessary API calls.
        if ($payment && $payment->status === 'completed') {
            $existingTxn = $payment->meta['paymongo_intent_id']
                ? Transaction::where('reference', 'PAY-' . $payment->meta['paymongo_intent_id'])->first()
                : null;

            if ($existingTxn) {
                Log::info('PayMongo success: already fully processed (fast path)', [
                    'session_id'     => $sessionId,
                    'payment_id'     => $payment->id,
                    'transaction_id' => $existingTxn->id,
                ]);
                return redirect()->route('student.account', ['tab' => 'history'])
                    ->with('flash.success', 'Payment received! Awaiting accounting verification.');
            }
        }

        // ── VERIFY VIA PAYMONGO API ───────────────────────────────────────────
        // Retry up to 3 times with 1s delay to handle transient network issues.
        $apiResponse = Http::withBasicAuth($this->secretKey, '')
            ->timeout(15)
            ->retry(3, 1000, fn ($e) => true)
            ->get("{$this->baseUrl}/checkout_sessions/{$sessionId}");

        if (! $apiResponse->ok()) {
            Log::error('PayMongo session API verification failed after retries', [
                'session_id'  => $sessionId,
                'http_status' => $apiResponse->status(),
                'has_payment' => $payment !== null,
            ]);

            // ── FALLBACK: If we have a pending local payment, show a soft message ──
            // Don't show the scary "contact accounting" error yet — the webhook
            // may still come through and process it.
            if ($payment && $payment->status === 'pending') {
                Log::info('PayMongo API unreachable but local pending payment exists — showing soft message', [
                    'session_id' => $sessionId,
                    'payment_id' => $payment->id,
                ]);
                return redirect()->route('student.account', ['tab' => 'history'])
                    ->with('flash.info', 'Your payment is being processed. Please check your Payment History tab in a few minutes. If it doesn\'t appear after 10 minutes, contact accounting with reference: ' . $sessionId);
            }

            return redirect()->route('student.account')
                ->with('flash.error', 'Could not verify your payment. If you were charged, please contact accounting with session ID: ' . $sessionId);
        }

        $session             = $apiResponse->json('data');
        $paymentIntentId     = data_get($session, 'attributes.payment_intent.id');
        $paymentIntentStatus = data_get($session, 'attributes.payment_intent.attributes.status');
        $firstPaymentStatus  = data_get($session, 'attributes.payments.0.attributes.status');
        $sessionPaidAt       = data_get($session, 'attributes.paid_at');

        // FIXED: Test mode keeps payment_intent.status = "processing" even after card charged.
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

            // IMPORTANT FIX: Update the Payment row even if the Transaction already
            // exists (e.g., webhook beat the redirect). The Payment row may still be
            // stuck at "pending" if the webhook job updated the Transaction but not
            // the Payment row due to the early idempotency exit.
            if ($payment && $payment->status !== 'completed') {
                $payment->update([
                    'status'             => 'completed',
                    'paymongo_intent_id' => $paymentIntentId,
                ]);
            }

            return redirect()->route('student.account', ['tab' => 'history'])
                ->with('flash.success', 'Payment received! Awaiting accounting verification.');
        }

        // ── ALSO CHECK IF PAYMENT ROW IS ALREADY COMPLETED ───────────────────
        if ($payment && $payment->status === 'completed') {
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

        if ($sessionId && $sessionId !== '{CHECKOUT_SESSION_ID}') {
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

            return response()->json([
                'message'        => 'Bank transfer submitted successfully. Please upload your proof of payment.',
                'transaction_id' => $transaction->id,
            ]);

        } catch (\Exception $e) {
            Log::error('Bank transfer error', [
                'user_id' => $request->user()?->id,
                'error'   => $e->getMessage(),
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
                Log::warning('No active payment_approval workflow found.', [
                    'transaction_id' => $transactionId,
                ]);
                return;
            }

            $transaction = Transaction::findOrFail($transactionId);
            app(\App\Services\WorkflowService::class)->startWorkflow($workflow, $transaction, $userId);

        } catch (\Throwable $e) {
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