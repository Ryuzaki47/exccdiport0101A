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

            // Format assessment data for Inertia serialization
            $assessmentFormatted = $assessment ? [
                'id'                 => $assessment->id,
                'assessment_number'  => $assessment->assessment_number ?? 'N/A',
                'year_level'         => $assessment->year_level ?? 'Unknown',
                'semester'           => $assessment->semester ?? 'Unknown',
                'school_year'        => $assessment->school_year ?? 'Unknown',
                'total_assessment'   => (float) ($assessment->total_assessment ?? 0),
                'status'             => $assessment->status ?? 'active',
                'lec_units'          => $assessment->lec_units ?? 0,
                'lab_units'          => $assessment->lab_units ?? 0,
            ] : null;

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
    //
    //  GUARD: Duplicate PayMongo session prevention.
    //  Before opening a new checkout session, check if the student already has
    //  a pending (incomplete) PayMongo payment for the same term. If yes, block
    //  the request. This prevents double-sessions from two open browser tabs.
    //
    //  NOTE: paid_at and payment_method are NOT required here — PayMongo
    //  handles the payment method selection on its own checkout page. We only
    //  need amount, description, and the term reference for accounting.
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

        // ── DUPLICATE PAYMONGO SESSION GUARD ───────────────────────────────
        // If a pending Payment row already exists for this term + user within
        // the TTL window, check whether the PayMongo session is actually still
        // active before blocking. This handles the case where the student
        // closed the PayMongo tab without hitting the cancel URL — the pending
        // row exists but the session is already expired on PayMongo's end.
        if ($validated['selected_term_id']) {
            $stalePending = Payment::where('user_id', $user->id)
                ->where('status', 'pending')
                ->whereNotNull('paymongo_source_id')
                ->whereJsonContains('meta->selected_term_id', (int) $validated['selected_term_id'])
                ->where('created_at', '>=', now()->subMinutes(10)) // 10-min TTL for stale sessions
                ->first();

            if ($stalePending) {
                // Verify the session with PayMongo — if it's no longer 'active',
                // expire it locally and allow the student to create a new one.
                $pmResponse = Http::withBasicAuth($this->secretKey, '')
                    ->get("{$this->baseUrl}/checkout_sessions/{$stalePending->paymongo_source_id}");

                $pmStatus = data_get($pmResponse->json(), 'data.attributes.status');

                // PayMongo statuses: 'active', 'paid', 'expired', 'cancelled'
                if ($pmResponse->ok() && ! in_array($pmStatus, ['active'])) {
                    // Session is no longer active — safe to expire locally and proceed
                    $stalePending->update(['status' => 'cancelled']);
                    Log::info('PayMongo session auto-expired, allowing new checkout', [
                        'user_id'          => $user->id,
                        'old_session_id'   => $stalePending->paymongo_source_id,
                        'paymongo_status'  => $pmStatus,
                        'term_id'          => $validated['selected_term_id'],
                    ]);
                } elseif ($pmResponse->failed()) {
                    // PayMongo API unreachable — fail open (allow new checkout).
                    // Better UX than permanently locking the student out.
                    $stalePending->update(['status' => 'cancelled']);
                    Log::warning('PayMongo API unreachable during session check, expiring locally', [
                        'user_id'        => $user->id,
                        'old_session_id' => $stalePending->paymongo_source_id,
                        'http_status'    => $pmResponse->status(),
                    ]);
                } else {
                    // Session is genuinely still active — block the duplicate attempt
                    return response()->json([
                        'error' => 'You have an open payment session for this term. Please complete it or wait a few minutes before trying again.',
                    ], 422);
                }
            }
        }

        // ── DUPLICATE AWAITING_APPROVAL GUARD ─────────────────────────────
        // Also block if a transaction for this term is already awaiting accounting
        // approval. There is no reason to open a second PayMongo session for a
        // payment that hasn't been reviewed yet.
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

            // Verify the term actually belongs to this student
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
                        'payment_method_types' => ['gcash', 'card', 'paymaya'],
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

        $session = $response->json('data');

        // Extract payment_intent_id from PayMongo response for webhook linkage
        $paymentIntentId = data_get($session, 'attributes.payment_intent.id');

        // Store a pending Payment row so we can look it up on redirect success
        Payment::create([
            'user_id'               => $user->id,
            'student_assessment_id' => $termInfo?->student_assessment_id ?? null,
            'amount'                => $validated['amount'],
            'description'           => $validated['description'],
            'payment_method'        => 'paymongo_checkout',
            'status'                => 'pending',
            'paymongo_source_id'    => $session['id'],
            'meta'                  => [
                'payment_method'    => 'paymongo',
                'selected_term_id'  => $validated['selected_term_id'],
                'term_name'         => $termInfo?->term_name ?? 'Payment',
                'paymongo_checkout' => true,
                'payment_intent_id' => $paymentIntentId,
            ],
        ]);

        // ✅ NEW: Store payment_intent_id in StudentPaymentTerm so webhook can find it
        if ($termInfo && $paymentIntentId) {
            $termInfo->update([
                'payment_intent_id' => $paymentIntentId,
            ]);

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
    //
    //  LIFECYCLE: Student Payment → PayMongo → Accounting Review → Approval
    //
    //  When PayMongo confirms payment succeeded, we do NOT immediately mark the
    //  student's term as paid. Instead we:
    //    1. Verify the session with PayMongo API
    //    2. Create a Transaction with status = awaiting_approval
    //    3. Start the payment_approval workflow
    //    4. Redirect the student to their account page (awaiting_approval tab)
    //
    //  The term balance and accounts.balance are updated ONLY when accounting
    //  approves the payment via WorkflowApprovalController::approve(), which
    //  triggers WorkflowService::onWorkflowCompleted() →
    //  StudentPaymentService::finalizeApprovedPayment().
    //
    //  GUARD: Idempotency check prevents processing the same session twice.
    // ─────────────────────────────────────────────────────────────────────────

    public function success(Request $request)
    {
        Log::info('Session ID:', ['id' => $sessionId]);

        if (! $sessionId) {
            Log::warning('PayMongo success redirect missing session_id');
            return redirect()->route('student.account')->with('flash.error', 'Payment session not found.');
        }

        // ── FIND LOCAL PAYMENT RECORD FIRST ─────────────────────────────────
        // We created this row when the checkout session was opened, so it
        // must exist. If it doesn't, something went wrong before PayMongo.
        $payment = Payment::where('paymongo_source_id', $sessionId)->first();

        if (! $payment) {
            Log::warning('PayMongo success: no local payment record for session', [
                'session_id' => $sessionId,
            ]);
            return redirect()->route('student.account')
                ->with('flash.error', 'Payment record not found. Please contact the accounting office.');
        }

        // ── IDEMPOTENCY GUARD ────────────────────────────────────────────────
        // Already processed — skip all work and redirect cleanly.
        if ($payment->status === 'completed') {
            Log::info('PayMongo success: session already processed, skipping', [
                'session_id' => $sessionId,
                'payment_id' => $payment->id,
            ]);
            return redirect()->route('student.account', ['tab' => 'history'])
                ->with('flash.info', 'This payment was already recorded and is awaiting accounting review.');
        }

        // ── VERIFY WITH PAYMONGO ─────────────────────────────────────────────
        // Verify the session with PayMongo to get the payment intent ID and
        // confirm the payment actually succeeded on their end.
        $response = Http::withBasicAuth($this->secretKey, '')
            ->get("{$this->baseUrl}/checkout_sessions/{$sessionId}");

        if (! $response->ok()) {
            Log::error('PayMongo session verification failed', [
                'session_id' => $sessionId,
                'http_status' => $response->status(),
                'body'        => $response->body(),
            ]);

            // If the API is down or the key is misconfigured, do NOT hard-fail.
            // Mark the payment as needing manual review and still redirect the
            // student to their account so they can see their pending state.
            // Accounting can manually verify and process the payment.
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
            // Mark the local pending row as cancelled so it doesn't block future attempts
            $payment->update(['status' => 'cancelled']);
            return redirect()->route('student.account')
                ->with('flash.warning', 'Payment did not complete. No charges were made. You can try again.');
        }

        // ── DUPLICATE TRANSACTION GUARD ──────────────────────────────────────
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

        // ── AUTH GUARD ───────────────────────────────────────────────────────
        $user = auth()->user();

        if (! $user) {
            Log::error('PayMongo success: no authenticated user on redirect', [
                'session_id' => $sessionId,
                'payment_id' => $payment->id,
            ]);
            return redirect()->route('login')
                ->with('flash.error', 'Your session expired. Please log in and check your payment history.');
        }

        // ── PROCESS PAYMENT INSIDE A TRANSACTION ─────────────────────────────
        // PayMongo confirmed payment. Create the transaction as AWAITING_APPROVAL
        // so accounting staff can review and approve it before the term balance
        // is decremented. This ensures data integrity in the approval lifecycle.
        DB::transaction(function () use ($payment, $user, $paymentIntentId, $sessionId) {

            // 1. Mark the pending Payment row as completed (PayMongo has our money)
            $payment->update([
                'status'             => 'completed',
                'description'        => 'PayMongo payment — awaiting accounting review',
                'paymongo_intent_id' => $paymentIntentId,
            ]);

            $termId   = $payment->meta['selected_term_id'] ?? null;
            $termInfo = $termId ? StudentPaymentTerm::find($termId) : null;

            // 2. Create Transaction as AWAITING_APPROVAL — NOT 'paid'.
            //    Term balance is NOT decremented here. It is decremented only
            //    after accounting approves via finalizeApprovedPayment().
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

            // 3. Start the payment_approval workflow so accounting sees it
            $this->startPaymentApprovalWorkflow($transaction->id, $user->id);
        });

        // Redirect to account overview History tab with a clear status message
        return redirect()->route('student.account', ['tab' => 'history'])
            ->with('flash.success', 'Payment received! Your payment is now awaiting verification by accounting. You will be notified once it is approved.');
    }

    public function cancel(Request $request)
    {
        $sessionId = $request->query('session_id');

        if ($sessionId) {
            // Expire the pending Payment row immediately so the student can
            // retry without waiting for the TTL guard to drain.
            // We only cancel rows that are still 'pending' — never touch
            // rows that have already been marked 'completed' by a race-winning
            // success redirect.
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

        try {
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

            $this->startPaymentApprovalWorkflow($transaction->id, $user->id);

            return redirect()->route('student.account', ['tab' => 'history'])
                ->with('success', 'Proof of payment uploaded. Your payment is now awaiting verification.');

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Proof upload failed', [
                'transaction_id' => $transaction->id,
                'error'          => $e->getMessage(),
            ]);

            return back()->withErrors(['proof_of_payment' => 'Failed to upload proof. Please try again.']);
        }
    }

    private function startPaymentApprovalWorkflow(int $transactionId, int $userId): void
    {
        $workflow = Workflow::active()
            ->where('type', 'payment_approval')
            ->first();

        if (! $workflow) {
            throw new \Exception(
                'No active payment_approval workflow found. ' .
                'Please run: php artisan db:seed --class=PaymentApprovalWorkflowSeeder'
            );
        }

        $transaction = Transaction::findOrFail($transactionId);
        $this->workflowService->startWorkflow($workflow, $transaction, $userId);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  MISC HELPERS
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
        $validated = $request->validate([
            'student_id'       => 'required|exists:students,id',
            'amount'           => 'required|numeric|min:100',
            'reference_number' => 'required|string|max:100',
        ]);

        Payment::create([
            'student_id'     => $validated['student_id'],
            'amount'         => $validated['amount'],
            'payment_method' => 'bank_transfer',
            'status'         => 'pending',
            'description'    => 'Bank Transfer - PNB',
        ]);

        return response()->json(['message' => 'Bank transfer submitted successfully.']);
    }

    public function checkStatus(Request $request)
    {
        $request->validate(['payment_id' => 'required|exists:payments,id']);
        $payment = Payment::findOrFail($request->payment_id);

        return response()->json(['status' => $payment->status]);
    }

    public function verifyBankTransfer(Request $request, Payment $payment)
    {
        $request->validate(['verified' => 'required|boolean']);

        $payment->update([
            'status' => $request->verified ? 'completed' : 'failed',
        ]);

        return response()->json(['message' => 'Payment verified successfully.']);
    }
}