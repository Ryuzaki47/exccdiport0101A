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
            : collect();

        $pendingApprovalPayments = $assessment
            ? Transaction::where('user_id', $user->id)
                ->where('kind', 'payment')
                ->where('status', PaymentStatus::AWAITING_APPROVAL->value)
                ->get()
                ->map(fn ($txn) => [
                    'id'               => $txn->id,
                    'reference'        => $txn->reference,
                    'amount'           => (float) $txn->amount,
                    'selected_term_id' => $txn->meta['selected_term_id'] ?? null,
                    'term_name'        => $txn->meta['term_name'] ?? $txn->type ?? 'Payment',
                    'created_at'       => $txn->created_at,
                ])
            : collect();

        return Inertia::render('Payment/Create', [
            'student' => [
                'id'         => $user->id,
                'name'       => $user->name,
                'account_id' => $user->account_id,
                'course'     => $user->course,
                'year_level' => $user->year_level,
            ],
            'assessment'              => $assessment,
            'paymentTerms'            => $paymentTerms->values(),
            'pendingApprovalPayments' => $pendingApprovalPayments->values(),
            'preselectedTermId'       => $request->query('term_id') ? (int) $request->query('term_id') : null,
        ]);
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
        // If a pending Payment row already exists for this term + user, block
        // the creation of a second checkout session. "Pending" means the
        // PayMongo session was opened but the success redirect hasn't fired yet.
        if ($validated['selected_term_id']) {
            $pendingPaymongoPayment = Payment::where('user_id', $user->id)
                ->where('status', 'pending')
                ->whereNotNull('paymongo_source_id')
                ->whereJsonContains('meta->selected_term_id', (int) $validated['selected_term_id'])
                ->where('created_at', '>=', now()->subMinutes(30)) // 30-min TTL for stale sessions
                ->exists();

            if ($pendingPaymongoPayment) {
                return response()->json([
                    'error' => 'You already have an open payment session for this term. Please complete or wait 30 minutes before trying again.',
                ], 422);
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
                        'cancel_url'  => url('/payment/cancel'),
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

        // Store a pending Payment row so we can look it up on redirect success
        Payment::create([
            'user_id'            => $user->id,
            'amount'             => $validated['amount'],
            'description'        => $validated['description'],
            'payment_method'     => 'paymongo_checkout',
            'status'             => 'pending',
            'paymongo_source_id' => $session['id'],
            'meta'               => [
                'payment_method'    => 'paymongo',
                'selected_term_id'  => $validated['selected_term_id'],
                'term_name'         => $termInfo?->term_name ?? 'Payment',
                'paymongo_checkout' => true,
            ],
        ]);

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
        $sessionId = $request->query('session_id');

        if (! $sessionId) {
            Log::warning('PayMongo success redirect missing session_id');
            return redirect()->route('student.account')->with('flash.error', 'Payment session not found.');
        }

        // Verify the session with PayMongo
        $response = Http::withBasicAuth($this->secretKey, '')
            ->get("{$this->baseUrl}/checkout_sessions/{$sessionId}");

        if (! $response->ok()) {
            Log::error('PayMongo session verification failed', [
                'session_id' => $sessionId,
                'status'     => $response->status(),
            ]);
            return redirect()->route('student.account')->with('flash.error', 'Could not verify payment session.');
        }

        $session             = $response->json('data');
        $paymentIntentStatus = data_get($session, 'attributes.payment_intent.attributes.status');
        $paymentIntentId     = data_get($session, 'attributes.payment_intent.id');

        if ($paymentIntentStatus !== 'succeeded') {
            Log::warning('PayMongo payment not succeeded on redirect', [
                'session_id' => $sessionId,
                'status'     => $paymentIntentStatus,
            ]);
            return redirect()->route('student.account')->with('flash.warning', 'Payment did not complete. Please try again.');
        }

        $payment = Payment::where('paymongo_source_id', $sessionId)->first();

        if (! $payment) {
            Log::warning('PayMongo success: payment record not found for session', [
                'session_id' => $sessionId,
            ]);
            return redirect()->route('student.account')->with('flash.error', 'Payment record not found.');
        }

        // ── IDEMPOTENCY GUARD ───────────────────────────────────────────────
        // If we already processed this session, redirect without doing any work.
        if ($payment->status === 'completed') {
            Log::info('PayMongo success: session already processed, skipping', [
                'session_id' => $sessionId,
                'payment_id' => $payment->id,
            ]);
            return redirect()->route('student.account', ['tab' => 'history'])
                ->with('flash.info', 'This payment was already recorded and is awaiting accounting review.');
        }

        // ── DUPLICATE TRANSACTION GUARD ─────────────────────────────────────
        // Check if a Transaction row already exists for this payment intent.
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

        // ── PROCESS PAYMENT INSIDE A TRANSACTION ────────────────────────────
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

    public function cancel()
    {
        return redirect()->route('student.account')->with('flash.warning', 'Payment was cancelled. No charges were made.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  PAYMONGO WEBHOOK (fallback for missed redirects)
    // ─────────────────────────────────────────────────────────────────────────

    public function webhook(Request $request)
    {
        $payload   = $request->getContent();
        $signature = $request->header('X-Paymongo-Signature');

        if (! $this->verifyWebhookSignature($payload, $signature)) {
            Log::warning('Invalid PayMongo webhook signature');
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $data  = json_decode($payload, true);
        $event = data_get($data, 'data.attributes.type');

        Log::info('PayMongo webhook received', ['event' => $event]);

        if ($event === 'payment.success' || $event === 'charge.success') {
            $this->handleWebhookPaymentSuccess($data);
        } elseif ($event === 'payment.failed' || $event === 'charge.failed') {
            $this->handlePaymentFailed($data);
        }

        return response()->json(['success' => true]);
    }

    private function verifyWebhookSignature(string $payload, ?string $signature): bool
    {
        if (! $signature || ! $this->secretKey) {
            return false;
        }

        $computed = hash_hmac('sha256', $payload, $this->secretKey, false);
        return hash_equals($computed, $signature);
    }

    /**
     * Webhook fallback: if the student's browser closed before the success redirect
     * fired, this webhook will still create the awaiting_approval transaction.
     */
    private function handleWebhookPaymentSuccess(array $data): void
    {
        $paymentIntentId = data_get($data, 'data.attributes.id');
        $reference       = "PAY-{$paymentIntentId}";

        // If a transaction already exists for this intent (redirect already ran), skip
        if (Transaction::where('reference', $reference)->exists()) {
            Log::info('Webhook: transaction already exists, skipping', ['reference' => $reference]);
            return;
        }

        // Find the pending Payment row by session/source id match
        $payment = Payment::where('paymongo_source_id', 'like', "%{$paymentIntentId}%")
            ->orWhere('paymongo_intent_id', $paymentIntentId)
            ->first();

        if (! $payment) {
            Log::warning('Webhook payment success: no Payment row found', ['intent_id' => $paymentIntentId]);
            return;
        }

        $user   = \App\Models\User::find($payment->user_id);
        $termId = $payment->meta['selected_term_id'] ?? null;

        if (! $user) {
            Log::error('Webhook payment success: user not found', ['user_id' => $payment->user_id]);
            return;
        }

        $termInfo = $termId ? StudentPaymentTerm::find($termId) : null;

        DB::transaction(function () use ($payment, $user, $paymentIntentId, $termId, $termInfo) {
            $payment->update(['status' => 'completed', 'paymongo_intent_id' => $paymentIntentId]);

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
                    'description'       => 'PayMongo payment (webhook)',
                    'paymongo_intent_id' => $paymentIntentId,
                    'term_name'          => $payment->meta['term_name'] ?? 'Payment',
                    'selected_term_id'   => $termId,
                    'payment_method'     => 'paymongo',
                    'assessment_id'      => $termInfo?->assessment?->id ?? null,
                    'requires_approval'  => true,
                ],
            ]);

            $this->startPaymentApprovalWorkflow($transaction->id, $user->id);

            Log::info('Webhook: created awaiting_approval transaction', [
                'user_id'        => $user->id,
                'transaction_id' => $transaction->id,
            ]);
        });
    }

    private function handlePaymentFailed(array $data): void
    {
        $reference = data_get($data, 'data.attributes.reference_number') ??
                     data_get($data, 'data.attributes.id');

        $transaction = Transaction::where('reference', 'like', "%{$reference}%")->first();
        if ($transaction) {
            $transaction->update(['status' => PaymentStatus::FAILED->value]);
        }

        Log::warning('Webhook payment failed', ['reference' => $reference]);
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