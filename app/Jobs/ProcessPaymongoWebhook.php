<?php

namespace App\Jobs;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\StudentPaymentTerm;
use App\Models\Transaction;
use App\Models\Workflow;
use App\Services\WorkflowService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessPaymongoWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of times the job may be attempted.
     * Keep low — if the DB is healthy, 1 attempt is enough.
     * 3 gives resilience against transient DB failures.
     */
    public int $tries = 3;

    /**
     * Seconds to wait before retrying after failure.
     */
    public int $backoff = 10;

    /**
     * Maximum seconds this job may run before timing out.
     */
    public int $timeout = 60;

    public function __construct(
        private readonly array  $payload,
        private readonly string $eventType,
    ) {}

    public function handle(): void
    {
        match ($this->eventType) {
            'checkout_session.payment.paid',
            'payment.paid'   => $this->handlePaymentPaid(),
            'payment.failed' => $this->handlePaymentFailed(),
            default          => Log::info('ProcessPaymongoWebhook: ignoring event type', [
                'type' => $this->eventType,
            ]),
        };
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function handlePaymentPaid(): void
    {
        $checkoutSession = data_get($this->payload, 'data.attributes.data');

        if (! $checkoutSession) {
            Log::error('ProcessPaymongoWebhook: no checkout_session data found', [
                'event_id'   => data_get($this->payload, 'data.id'),
                'event_type' => $this->eventType,
            ]);
            // Do NOT re-throw — this event is malformed and retrying won't fix it.
            return;
        }

        $paymentIntentId = data_get($checkoutSession, 'payment_intent.id')
            ?? data_get($checkoutSession, 'payments.0.attributes.payment_intent_id');

        if (! $paymentIntentId) {
            Log::error('ProcessPaymongoWebhook: missing payment_intent_id', [
                'event_id'   => data_get($this->payload, 'data.id'),
                'session_id' => data_get($checkoutSession, 'id'),
            ]);
            return;
        }

        // ── IDEMPOTENCY GUARD ─────────────────────────────────────────────────
        $reference = "PAY-{$paymentIntentId}";

        if (Transaction::where('reference', $reference)->exists()) {
            Log::info('ProcessPaymongoWebhook: transaction already exists, skipping', [
                'payment_intent_id' => $paymentIntentId,
                'reference'         => $reference,
            ]);
            return;
        }

        // ── FIND LOCAL PAYMENT ROW ────────────────────────────────────────────
        $payment = Payment::where('paymongo_source_id', data_get($checkoutSession, 'id'))
            ->orWhere(function ($q) use ($paymentIntentId) {
                $q->whereJsonContains('meta->payment_intent_id', $paymentIntentId);
            })
            ->first();

        if (! $payment) {
            Log::warning('ProcessPaymongoWebhook: no Payment row found for session', [
                'payment_intent_id' => $paymentIntentId,
                'session_id'        => data_get($checkoutSession, 'id'),
                'event_id'          => data_get($this->payload, 'data.id'),
            ]);
            // Do NOT throw — retrying won't create the missing row.
            return;
        }

        $user = \App\Models\User::find($payment->user_id);

        if (! $user) {
            Log::error('ProcessPaymongoWebhook: user not found', [
                'user_id'           => $payment->user_id,
                'payment_intent_id' => $paymentIntentId,
            ]);
            return;
        }

        $termId   = $payment->meta['selected_term_id'] ?? null;
        $termInfo = $termId ? StudentPaymentTerm::find($termId) : null;
        $amount   = (float) $payment->amount;

        DB::transaction(function () use (
            $payment, $user, $paymentIntentId, $termId, $termInfo, $amount, $checkoutSession
        ) {
            $payment->update([
                'status'             => 'completed',
                'paymongo_intent_id' => $paymentIntentId,
                'description'        => 'PayMongo payment — awaiting accounting review (via webhook)',
            ]);

            $transaction = Transaction::create([
                'user_id'         => $user->id,
                'kind'            => 'payment',
                'status'          => PaymentStatus::AWAITING_APPROVAL->value,
                'payment_channel' => 'paymongo',
                'amount'          => $amount,
                'reference'       => "PAY-{$paymentIntentId}",
                'type'            => 'Payment',
                'paid_at'         => now(),
                'year'            => now()->year,
                'semester'        => $termInfo?->assessment?->semester ?? null,
                'meta'            => [
                    'description'         => $payment->meta['term_name'] ?? 'PayMongo Payment',
                    'paymongo_session_id' => data_get($checkoutSession, 'id'),
                    'paymongo_intent_id'  => $paymentIntentId,
                    'term_name'           => $payment->meta['term_name'] ?? 'Payment',
                    'selected_term_id'    => $termId,
                    'payment_method'      => 'paymongo',
                    'assessment_id'       => $termInfo?->student_assessment_id ?? null,
                    'requires_approval'   => true,
                    'source'              => 'webhook',
                ],
            ]);

            // Start workflow INSIDE the transaction so if it fails,
            // the transaction row is rolled back too (atomic pair).
            $this->startPaymentApprovalWorkflow($transaction->id, $user->id);

            Log::info('ProcessPaymongoWebhook: payment submitted for accounting review', [
                'user_id'        => $user->id,
                'transaction_id' => $transaction->id,
                'amount'         => $amount,
                'term_id'        => $termId,
                'payment_intent' => $paymentIntentId,
            ]);
        });
    }

    private function handlePaymentFailed(): void
    {
        $checkoutSession = data_get($this->payload, 'data.attributes.data');

        if (! $checkoutSession) {
            Log::warning('ProcessPaymongoWebhook payment.failed: no checkout_session data');
            return;
        }

        $paymentIntentId = data_get($checkoutSession, 'payment_intent.id')
            ?? data_get($checkoutSession, 'payments.0.attributes.payment_intent_id');

        if (! $paymentIntentId) {
            Log::warning('ProcessPaymongoWebhook payment.failed: missing payment_intent_id');
            return;
        }

        $cancelled = Payment::where('paymongo_source_id', data_get($checkoutSession, 'id'))
            ->orWhere('paymongo_intent_id', $paymentIntentId)
            ->update(['status' => 'cancelled']);

        Transaction::where('reference', "PAY-{$paymentIntentId}")
            ->where('status', PaymentStatus::AWAITING_APPROVAL->value)
            ->update(['status' => PaymentStatus::FAILED->value]);

        Log::info('ProcessPaymongoWebhook: payment marked as failed', [
            'payment_intent_id' => $paymentIntentId,
            'rows_cancelled'    => $cancelled,
        ]);
    }

    private function startPaymentApprovalWorkflow(int $transactionId, int $userId): void
    {
        $workflow = Workflow::active()
            ->where('type', 'payment_approval')
            ->first();

        if (! $workflow) {
            Log::warning('ProcessPaymongoWebhook: no active payment_approval workflow found', [
                'transaction_id' => $transactionId,
            ]);
            return;
        }

        app(WorkflowService::class)->startWorkflow(
            $workflow,
            Transaction::find($transactionId),
            $userId,
        );

        Log::info('ProcessPaymongoWebhook: payment_approval workflow started', [
            'transaction_id' => $transactionId,
            'workflow_id'    => $workflow->id,
        ]);
    }

    /**
     * Handle a job failure after all retries are exhausted.
     */
    public function failed(\Throwable $e): void
    {
        Log::error('ProcessPaymongoWebhook job FAILED after all retries', [
            'event_type' => $this->eventType,
            'error'      => $e->getMessage(),
            'trace'      => $e->getTraceAsString(),
        ]);
    }
}