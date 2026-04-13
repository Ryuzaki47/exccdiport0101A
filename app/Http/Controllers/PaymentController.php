<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Payment;
use App\Models\StudentAssessment;
use App\Models\StudentPaymentTerm;
use App\Models\Transaction;
use App\Models\Workflow;
use App\Enums\PaymentStatus;
use App\Services\WorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
    //  INTERNAL PAYMENT FLOW — renders Payment/Create.vue
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Show the internal payment creation form.
     *
     * Route: GET /student/payment  (student.payment.create or payment.create)
     *
     * Accepts optional query params:
     *   - assessment_id: pre-select a specific assessment
     *   - term_id: pre-select a specific payment term
     */
    public function create(Request $request): Response
    {
        $user = $request->user();

        // Resolve assessment — use query param if supplied, else latest active
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

        // Payment terms for the resolved assessment
        $paymentTerms = $assessment
            ? StudentPaymentTerm::where('student_assessment_id', $assessment->id)
                ->orderBy('term_order')
                ->get()
            : collect();

        // Pending approval payments — prevent duplicate submissions per term
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
            'assessment'             => $assessment,
            'paymentTerms'           => $paymentTerms->values(),
            'pendingApprovalPayments'=> $pendingApprovalPayments->values(),
            'preselectedTermId'      => $request->query('term_id') ? (int) $request->query('term_id') : null,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  EXTERNAL PAYMONGO FLOW
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Create a PayMongo checkout session.
     * Called by Payment/Create.vue (GCash/Maya/Card path).
     */
    public function createCheckout(Request $request)
    {
        $validated = $request->validate([
            'amount'             => 'required|numeric|min:100',
            'description'        => 'required|string|max:255',
            'selected_term_id'   => 'nullable|exists:student_payment_terms,id',
            'paid_at'            => 'required|date|before_or_equal:today',
            'payment_method'     => 'required|in:gcash,credit_card,debit_card,paymaya,card',
        ]);

        abort_if(empty($this->secretKey), 500, 'PayMongo secret key is not configured.');

        $user = $request->user();
        $amountInCentavos = (int) round($validated['amount'] * 100);

        // Fetch term information if provided
        $termInfo = null;
        if ($validated['selected_term_id']) {
            $termInfo = StudentPaymentTerm::find($validated['selected_term_id']);
        }

        // Map payment method to PayMongo types
        // credit_card and debit_card both map to 'card' for PayMongo
        $paymentMethodTypes = ['gcash', 'paymaya'];
        if (in_array($validated['payment_method'], ['credit_card', 'debit_card'])) {
            $paymentMethodTypes[] = 'card';
        } elseif ($validated['payment_method'] === 'gcash') {
            $paymentMethodTypes = ['gcash', 'paymaya'];
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
                        'payment_method_types' => $paymentMethodTypes,
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

        Payment::create([
            'user_id'            => $user->id,
            'amount'             => $validated['amount'],
            'description'        => $validated['description'],
            'payment_method'     => 'paymongo_checkout',
            'status'             => 'pending',
            'paymongo_source_id' => $session['id'],
            'meta'               => [
                'paid_at'           => $validated['paid_at'],
                'payment_method'    => $validated['payment_method'],
                'selected_term_id'  => $validated['selected_term_id'],
                'term_name'         => $termInfo?->term_name ?? 'Payment',
                'paymongo_checkout' => true,
            ],
        ]);

        return response()->json([
            'checkout_url' => data_get($session, 'attributes.checkout_url'),
        ]);
    }

    /**
     * Handle PayMongo redirect success.
     * Called after customer completes payment on PayMongo's hosted checkout.
     */
    public function success(Request $request)
    {
        $sessionId = $request->query('session_id');

        if (!$sessionId) {
            Log::warning('Payment success missing session_id', ['request' => $request->query()]);
            return redirect()->route('student.dashboard', ['payment' => 'error']);
        }

        // Query PayMongo to verify session
        $response = Http::withBasicAuth($this->secretKey, '')
            ->get("{$this->baseUrl}/checkout_sessions/{$sessionId}");

        if (!$response->ok()) {
            Log::error('PayMongo session verification failed', [
                'session_id' => $sessionId,
                'status'     => $response->status(),
            ]);
            return redirect()->route('student.dashboard', ['payment' => 'error']);
        }

        $session = $response->json('data');
        $paymentIntentStatus = data_get($session, 'attributes.payment_intent.attributes.status');
        $paymentIntentId = data_get($session, 'attributes.payment_intent.id');

        if ($paymentIntentStatus !== 'succeeded') {
            Log::warning('PayMongo payment not succeeded', [
                'session_id' => $sessionId,
                'status'     => $paymentIntentStatus,
            ]);
            return redirect()->route('student.dashboard', ['payment' => 'pending']);
        }

        // Find the Payment record (temporary placeholder we created during checkout)
        $payment = Payment::where('paymongo_source_id', $sessionId)->first();

        if (!$payment) {
            Log::warning('Payment record not found for session', ['session_id' => $sessionId]);
            return redirect()->route('student.dashboard', ['payment' => 'error']);
        }

        $user = auth()->user();

        // Mark Payment as completed (for audit trail)
        $payment->update([
            'status'              => 'completed',
            'description'         => 'PayMongo GCash/Card/Maya payment',
            'paymongo_intent_id'  => $paymentIntentId,
        ]);

        // Create Transaction record (actual payment record in system)
        $transaction = Transaction::create([
            'user_id'         => $user->id,
            'kind'            => 'payment',
            'status'          => PaymentStatus::PAID->value,
            'payment_channel' => 'paymongo',
            'amount'          => $payment->amount,
            'reference'       => "PAY-{$paymentIntentId}",
            'type'            => 'Payment',
            'meta'            => [
                'description'        => $payment->description,
                'paymongo_session_id'=> $sessionId,
                'paymongo_intent_id' => $paymentIntentId,
                'term_name'          => $payment->meta['term_name'] ?? 'Payment',
                'selected_term_id'   => $payment->meta['selected_term_id'] ?? null,
            ],
        ]);

        // If a term was selected, update its balance
        if ($payment->meta['selected_term_id'] ?? null) {
            $term = StudentPaymentTerm::find($payment->meta['selected_term_id']);
            if ($term) {
                $term->decrement('balance', $payment->amount);
                $transaction->meta = array_merge($transaction->meta, [
                    'term_name'        => $term->term_name,
                    'selected_term_id' => $term->id,
                ]);
                $transaction->save();
            }
        }

        Log::info('Payment completed successfully', [
            'user_id'    => $user->id,
            'amount'     => $payment->amount,
            'session_id' => $sessionId,
        ]);

        return redirect()->route('student.dashboard', ['payment' => 'success']);
    }

    /**
     * Handle PayMongo redirect cancel.
     */
    public function cancel()
    {
        return redirect()->route('student.dashboard', ['payment' => 'cancelled']);
    }

    /**
     * Handle PayMongo webhook for asynchronous payment notifications.
     * PayMongo sends webhooks to confirm payment completion.
     */
    public function webhook(Request $request)
    {
        $payload = $request->getContent();
        $signature = $request->header('X-Paymongo-Signature');

        // Verify webhook signature
        if (!$this->verifyWebhookSignature($payload, $signature)) {
            Log::warning('Invalid PayMongo webhook signature', ['signature' => $signature]);
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $data = json_decode($payload, true);
        $event = data_get($data, 'data.attributes.type');

        Log::info('PayMongo webhook received', ['event' => $event]);

        if ($event === 'payment.success' || $event === 'charge.success') {
            $this->handlePaymentSuccess($data);
        } elseif ($event === 'payment.failed' || $event === 'charge.failed') {
            $this->handlePaymentFailed($data);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Verify PayMongo webhook signature using SHA256 HMAC.
     */
    private function verifyWebhookSignature(string $payload, ?string $signature): bool
    {
        if (!$signature || !$this->secretKey) {
            return false;
        }

        $computed = hash_hmac('sha256', $payload, $this->secretKey, false);
        return hash_equals($computed, $signature);
    }

    /**
     * Handle successful payment from webhook.
     */
    private function handlePaymentSuccess(array $data): void
    {
        $reference = data_get($data, 'data.attributes.reference_number') ??
                     data_get($data, 'data.attributes.id');
        $amount = data_get($data, 'data.attributes.amount', 0) / 100; // Convert cents to pesos

        // Find transaction or payment to mark as paid
        $transaction = Transaction::where('reference', 'like', "%{$reference}%")->first();
        if ($transaction) {
            $transaction->update(['status' => PaymentStatus::PAID->value]);
        }

        Log::info('Webhook payment success processed', ['reference' => $reference, 'amount' => $amount]);
    }

    /**
     * Handle failed payment from webhook.
     */
    private function handlePaymentFailed(array $data): void
    {
        $reference = data_get($data, 'data.attributes.reference_number') ??
                     data_get($data, 'data.attributes.id');

        $transaction = Transaction::where('reference', 'like', "%{$reference}%")->first();
        if ($transaction) {
            $transaction->update(['status' => PaymentStatus::FAILED->value ?? 'failed']);
        }

        Log::warning('Webhook payment failed', ['reference' => $reference]);
    }

    /**
     * Return bank details for manual transfer instructions.
     */
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
     * Handle bank transfer submission.
     */
    public function submitBankTransfer(Request $request)
    {
        $validated = $request->validate([
            'student_id'       => 'required|exists:students,id',
            'amount'           => 'required|numeric|min:100',
            'reference_number' => 'required|string|max:100',
        ]);

        Payment::create([
            'student_id'    => $validated['student_id'],
            'amount'        => $validated['amount'],
            'payment_method' => 'bank_transfer',
            'status'        => 'pending',
            'description'   => 'Bank Transfer - PNB',
        ]);

        return response()->json(['message' => 'Bank transfer submitted successfully.']);
    }

    /**
     * Check payment status.
     */
    public function checkStatus(Request $request)
    {
        $request->validate(['payment_id' => 'required|exists:payments,id']);
        $payment = Payment::findOrFail($request->payment_id);

        return response()->json(['status' => $payment->status]);
    }

    /**
     * Verify a bank transfer (accounting only).
     */
    public function verifyBankTransfer(Request $request, Payment $payment)
    {
        $request->validate(['verified' => 'required|boolean']);

        $payment->update([
            'status' => $request->verified ? 'completed' : 'failed',
        ]);

        return response()->json(['message' => 'Payment verified successfully.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  PROOF OF PAYMENT UPLOAD FLOW
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Show the proof of payment upload form.
     * Called after student submits payment details but before final approval.
     */
    public function showProofForm(Request $request, Transaction $transaction): Response
    {
        $user = $request->user();

        // Authorize: only the transaction owner can view
        if ($transaction->user_id !== $user->id) {
            abort(403, 'Unauthorized access to this transaction.');
        }

        // Only pending transactions should show proof form
        if ($transaction->status !== PaymentStatus::PENDING->value) {
            return redirect()->route('student.account')
                ->with('error', 'This payment is not waiting for proof.');
        }

        return Inertia::render('Payment/ProofUpload', [
            'transaction' => [
                'id'                => $transaction->id,
                'amount'            => (float) $transaction->amount,
                'payment_method'    => $transaction->payment_channel,
                'term_name'         => $transaction->meta['term_name'] ?? 'Payment',
                'description'       => $transaction->meta['description'] ?? null,
                'created_at'        => $transaction->created_at,
            ],
        ]);
    }

    /**
     * Handle proof of payment file upload.
     * After upload, mark transaction as awaiting_approval and start workflow.
     */
    public function uploadProof(Request $request, Transaction $transaction)
    {
        $user = $request->user();

        // Authorize: only the transaction owner can upload
        if ($transaction->user_id !== $user->id) {
            abort(403, 'Unauthorized access to this transaction.');
        }

        // Validate file upload
        $validated = $request->validate([
            'proof_of_payment' => 'required|file|mimes:pdf,jpg,jpeg,png,webp|max:5120',
        ]);

        try {
            // Store the file
            $file = $validated['proof_of_payment'];
            $filename = 'proof_' . $transaction->id . '_' . time() . '.' . $file->getClientOriginalExtension();
            $filepath = $file->storeAs('payment_proofs', $filename, 'public');

            // Update transaction with proof and mark as awaiting approval
            $transaction->update([
                'status'            => PaymentStatus::AWAITING_APPROVAL->value,
                'meta'              => array_merge($transaction->meta ?? [], [
                    'proof_of_payment'  => $filepath,
                    'proof_uploaded_at' => now()->toIso8601String(),
                ]),
            ]);

            // Start approval workflow
            $this->startPaymentApprovalWorkflow($transaction->id, $user->id);

            return redirect()->route('student.account', ['tab' => 'history'])
                ->with('success', 'Proof of payment uploaded successfully. Your payment is now awaiting verification.');

        } catch (\Exception $e) {
            Log::error('Proof upload failed', [
                'transaction_id' => $transaction->id,
                'user_id'        => $user->id,
                'error'          => $e->getMessage(),
            ]);

            return back()->withErrors(['proof_of_payment' => 'Failed to upload proof. Please try again.']);
        }
    }

    /**
     * Start payment approval workflow.
     */
    private function startPaymentApprovalWorkflow(int $transactionId, int $userId): void
    {
        $workflow = Workflow::active()
            ->where('type', 'payment_approval')
            ->first();

        if (!$workflow) {
            throw new \Exception(
                'No active payment_approval workflow found. ' .
                'Please run: php artisan db:seed --class=PaymentApprovalWorkflowSeeder'
            );
        }

        $transaction = Transaction::findOrFail($transactionId);
        $this->workflowService->startWorkflow($workflow, $transaction, $userId);
    }
}