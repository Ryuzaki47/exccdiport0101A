<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\StudentPaymentTerm;
use App\Models\Transaction;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymongoWebhookController extends Controller
{
    /**
     * Handle incoming PayMongo webhook events.
     *
     * Webhook payload structure:
     * {
     *   "data": {
     *     "id": "evt_...",
     *     "type": "checkout_session.payment.paid",
     *     "attributes": {
     *       "data": { ...checkout_session... }
     *     }
     *   }
     * }
     *
     * IMPORTANT: Always return 2xx. PayMongo disables your webhook after
     * 3 consecutive events that exhaust 12 retry attempts each.
     */
    public function handle(Request $request): Response
    {
        if (! $this->isValidSignature($request)) {
            Log::warning('PayMongo webhook: invalid signature rejected.');
            // Return 200 — returning 4xx causes retries on a permanently bad request
            return response('Unauthorized', 200);
        }

        $payload   = $request->json()->all();
        $eventType = data_get($payload, 'data.type');

        Log::info('PayMongo webhook received', [
            'event'       => $eventType,
            'event_id'    => data_get($payload, 'data.id'),
            'resource_id' => data_get($payload, 'data.attributes.data.id'),
        ]);

        match ($eventType) {
            'checkout_session.payment.paid',
            'payment.paid'   => $this->handlePaymentPaid($payload),
            'payment.failed' => $this->handlePaymentFailed($payload),
            default          => Log::info('PayMongo webhook: ignoring event type', ['type' => $eventType]),
        };

        return response('OK', 200);
    }

    // -------------------------------------------------------------------------

    /**
     * Process a successful payment webhook.
     *
     * DESIGN DECISION: The webhook does NOT directly mark the StudentPaymentTerm
     * as paid. Instead it mirrors what the success-redirect does:
     *   1. Create a Transaction with status = awaiting_approval
     *   2. Start the payment_approval workflow
     *
     * The term balance is only decremented AFTER accounting approves via
     * WorkflowApprovalController → StudentPaymentService::finalizeApprovedPayment().
     *
     * This ensures: data integrity, accounting audit trail, and no double-deduction
     * when both the success redirect AND the webhook fire for the same session.
     */
    private function handlePaymentPaid(array $payload): void
    {
        $checkoutSession = data_get($payload, 'data.attributes.data');

        if (! $checkoutSession) {
            Log::error('PayMongo webhook: no checkout_session data found', [
                'event_id' => data_get($payload, 'data.id'),
            ]);
            return;
        }

        $paymentIntentId = data_get($checkoutSession, 'payment_intent.id')
            ?? data_get($checkoutSession, 'payments.0.attributes.payment_intent_id');

        if (! $paymentIntentId) {
            Log::error('PayMongo webhook: missing payment_intent_id', [
                'event_id'   => data_get($payload, 'data.id'),
                'session_id' => data_get($checkoutSession, 'id'),
            ]);
            return;
        }

        // ── IDEMPOTENCY GUARD ────────────────────────────────────────────────
        // If the success redirect already created a transaction for this intent,
        // skip entirely. This is the most common case — redirect fires first,
        // webhook fires seconds later.
        $reference = "PAY-{$paymentIntentId}";

        if (Transaction::where('reference', $reference)->exists()) {
            Log::info('PayMongo webhook: transaction already exists (redirect handled it), skipping', [
                'payment_intent_id' => $paymentIntentId,
                'reference'         => $reference,
            ]);
            return;
        }

        // ── FIND LOCAL PAYMENT ROW ───────────────────────────────────────────
        // This row was created in createCheckout(). If it doesn't exist,
        // we cannot safely attribute the payment to a student.
        $payment = Payment::where('paymongo_source_id', data_get($checkoutSession, 'id'))
            ->orWhere(function ($q) use ($paymentIntentId) {
                $q->whereJsonContains('meta->payment_intent_id', $paymentIntentId);
            })
            ->first();

        if (! $payment) {
            Log::warning('PayMongo webhook: no Payment row found for session', [
                'payment_intent_id' => $paymentIntentId,
                'session_id'        => data_get($checkoutSession, 'id'),
                'event_id'          => data_get($payload, 'data.id'),
            ]);
            return;
        }

        $user = \App\Models\User::find($payment->user_id);

        if (! $user) {
            Log::error('PayMongo webhook: user not found', [
                'user_id'           => $payment->user_id,
                'payment_intent_id' => $paymentIntentId,
            ]);
            return;
        }

        $termId   = $payment->meta['selected_term_id'] ?? null;
        $termInfo = $termId ? StudentPaymentTerm::find($termId) : null;

        // Amount is in centavos from PayMongo
        $amountPaid = (data_get($checkoutSession, 'payments.0.attributes.amount')
            ?? data_get($checkoutSession, 'line_items.0.amount', 0)) / 100;

        // Use the amount from our local Payment row as the authoritative source
        // (PayMongo amount is a cross-check, our record is what we charged)
        $amount = (float) $payment->amount;

        DB::transaction(function () use (
            $payment, $user, $paymentIntentId, $termId, $termInfo, $amount, $checkoutSession
        ) {
            // 1. Mark the pending Payment row as completed
            $payment->update([
                'status'             => 'completed',
                'paymongo_intent_id' => $paymentIntentId,
                'description'        => 'PayMongo payment — awaiting accounting review (via webhook)',
            ]);

            // 2. Create Transaction as AWAITING_APPROVAL — NOT paid.
            //    Term balance is NOT touched here. Only accounting approval does that.
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

            // 3. Start the payment_approval workflow
            $this->startPaymentApprovalWorkflow($transaction->id, $user->id);

            Log::info('PayMongo webhook: payment submitted for accounting review', [
                'user_id'        => $user->id,
                'transaction_id' => $transaction->id,
                'amount'         => $amount,
                'term_id'        => $termId,
                'payment_intent' => $paymentIntentId,
                'source'         => 'webhook',
            ]);
        });
    }

    /**
     * Process a failed payment webhook.
     * Marks the local pending Payment row as cancelled so the student can retry.
     */
    private function handlePaymentFailed(array $payload): void
    {
        $checkoutSession = data_get($payload, 'data.attributes.data');

        if (! $checkoutSession) {
            Log::warning('PayMongo webhook payment.failed: no checkout_session data');
            return;
        }

        $paymentIntentId = data_get($checkoutSession, 'payment_intent.id')
            ?? data_get($checkoutSession, 'payments.0.attributes.payment_intent_id');

        if (! $paymentIntentId) {
            Log::warning('PayMongo webhook payment.failed: missing payment_intent_id');
            return;
        }

        // Cancel the local Payment row so TTL guard doesn't block retry
        $cancelled = Payment::where('paymongo_source_id', data_get($checkoutSession, 'id'))
            ->orWhere('paymongo_intent_id', $paymentIntentId)
            ->update(['status' => 'cancelled']);

        // Cancel any transaction that may have been created in a race
        Transaction::where('reference', "PAY-{$paymentIntentId}")
            ->where('status', PaymentStatus::AWAITING_APPROVAL->value)
            ->update(['status' => PaymentStatus::FAILED->value]);

        Log::info('PayMongo webhook: payment marked as failed', [
            'payment_intent_id' => $paymentIntentId,
            'rows_cancelled'    => $cancelled,
            'reason'            => data_get($checkoutSession, 'payments.0.attributes.last_payment_error'),
        ]);
    }

    // -------------------------------------------------------------------------
    // Workflow
    // -------------------------------------------------------------------------

    private function startPaymentApprovalWorkflow(int $transactionId, int $userId): void
    {
        $workflow = \App\Models\Workflow::active()
            ->where('type', 'payment_approval')
            ->first();

        if (! $workflow) {
            Log::warning('PayMongo webhook: no active payment_approval workflow found', [
                'transaction_id' => $transactionId,
            ]);
            return;
        }

        app(\App\Services\WorkflowService::class)->startWorkflow(
            $workflow,
            \App\Models\Transaction::find($transactionId),
            $userId,
        );

        Log::info('PayMongo webhook: payment_approval workflow started', [
            'transaction_id' => $transactionId,
            'workflow_id'    => $workflow->id,
        ]);
    }

    // -------------------------------------------------------------------------
    // Signature Verification
    // -------------------------------------------------------------------------

    private function isValidSignature(Request $request): bool
    {
        $secret = config('services.paymongo.webhook_secret');

        if (! $secret) {
            Log::error('PayMongo webhook: PAYMONGO_WEBHOOK_SECRET not configured');
            return false;
        }

        $signatureHeader = $request->header('Paymongo-Signature');

        if (! $signatureHeader) {
            Log::warning('PayMongo webhook: Paymongo-Signature header missing');
            return false;
        }

        $parts = [];
        foreach (explode(',', $signatureHeader) as $part) {
            if (strpos($part, '=') === false) {
                continue;
            }
            [$key, $value] = explode('=', $part, 2);
            $parts[$key] = $value;
        }

        $timestamp  = $parts['t'] ?? null;
        $isLiveMode = app()->isProduction();
        $signature  = $isLiveMode ? ($parts['li'] ?? null) : ($parts['te'] ?? null);

        if (! $timestamp || ! $signature) {
            Log::warning('PayMongo webhook: timestamp or signature missing in header', [
                'has_timestamp' => isset($parts['t']),
                'has_live'      => isset($parts['li']),
                'has_test'      => isset($parts['te']),
                'is_production' => $isLiveMode,
            ]);
            return false;
        }

        $rawBody  = $request->getContent();
        $toSign   = $timestamp . '.' . $rawBody;
        $computed = hash_hmac('sha256', $toSign, $secret);
        $isValid  = hash_equals($computed, $signature);

        if (! $isValid) {
            Log::warning('PayMongo webhook: signature mismatch', [
                'timestamp'    => $timestamp,
                'body_length'  => strlen($rawBody),
            ]);
        }

        return $isValid;
    }
}