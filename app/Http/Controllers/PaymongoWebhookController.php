<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Models\StudentPaymentTerm;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class PaymongoWebhookController extends Controller
{
    /**
     * Handle incoming PayMongo webhook events.
     * Must always return 2xx — PayMongo disables your webhook after
     * 3 consecutive events that exhaust 12 retry attempts each.
     *
     * Webhook payload structure:
     * {
     *   "data": {
     *     "id": "evt_...",
     *     "type": "checkout_session.payment.paid",
     *     "attributes": {
     *       "data": { ... checkout_session object ... }
     *     }
     *   }
     * }
     */
    public function handle(Request $request): Response
    {
        // 1. Verify the signature FIRST before touching anything
        if (!$this->isValidSignature($request)) {
            Log::warning('PayMongo webhook: invalid signature rejected.');
            // Return 200 anyway — returning 4xx causes retries on a bad request
            return response('Unauthorized', 200);
        }

        $payload = $request->json()->all();
        // ✅ CORRECTED: Event type is at data.type, NOT data.attributes.type
        $eventType = data_get($payload, 'data.type');

        Log::info('PayMongo webhook received', [
            'event' => $eventType,
            'event_id' => data_get($payload, 'data.id'),
            'resource_id' => data_get($payload, 'data.attributes.data.id'),
        ]);

        match ($eventType) {
            'checkout_session.payment.paid',
            'payment.paid' => $this->handlePaymentPaid($payload),
            'payment.failed' => $this->handlePaymentFailed($payload),
            default => Log::info('PayMongo webhook: ignoring event type', ['type' => $eventType]),
        };

        // Always return 200 to acknowledge receipt
        return response('OK', 200);
    }

    // -------------------------------------------------------------------------

    /**
     * Process a successful payment webhook.
     * Locates the StudentPaymentTerm by payment_intent_id and marks it as paid.
     */
    private function handlePaymentPaid(array $payload): void
    {
        // Extract nested checkout_session from webhook attributes
        $checkoutSession = data_get($payload, 'data.attributes.data');

        if (!$checkoutSession) {
            Log::error('PayMongo webhook: no checkout_session data found', [
                'event_id' => data_get($payload, 'data.id'),
            ]);
            return;
        }

        // Payment intent ID is the primary key to link with StudentPaymentTerm
        $paymentIntentId = data_get($checkoutSession, 'payment_intent.id')
            ?? data_get($checkoutSession, 'payments.0.attributes.payment_intent_id');

        if (!$paymentIntentId) {
            Log::error('PayMongo webhook: missing payment_intent_id in checkout_session', [
                'event_id' => data_get($payload, 'data.id'),
                'session_id' => data_get($checkoutSession, 'id'),
            ]);
            return;
        }

        // Look up the StudentPaymentTerm by payment_intent_id
        // This ID was stored when creating the payment intent via PaymentController
        $paymentTerm = StudentPaymentTerm::where('payment_intent_id', $paymentIntentId)->first();

        if (!$paymentTerm) {
            Log::warning('PayMongo webhook: no StudentPaymentTerm found', [
                'payment_intent_id' => $paymentIntentId,
                'event_id' => data_get($payload, 'data.id'),
            ]);
            return;
        }

        // Extract payment amount (stored in centavos: 432800 PHP = 4,328 PHP)
        $amountPaid = (data_get($checkoutSession, 'payments.0.attributes.amount') 
            ?? data_get($checkoutSession, 'line_items.0.amount', 0)) / 100;

        // Update the payment term as paid
        $paymentTerm->update([
            'status' => PaymentStatus::PAID->value,
            'balance' => max(0, $paymentTerm->balance - $amountPaid),
            'paid_at' => now(),
        ]);

        Log::info('PayMongo webhook: payment successfully processed', [
            'payment_term_id' => $paymentTerm->id,
            'user_id' => $paymentTerm->user_id,
            'payment_intent_id' => $paymentIntentId,
            'amount_php' => $amountPaid,
            'new_balance' => $paymentTerm->balance,
            'term_name' => data_get($checkoutSession, 'description'),
            'customer_email' => data_get($checkoutSession, 'billing.email'),
            'payment_method' => data_get($checkoutSession, 'payment_method_used'),
        ]);
    }

    /**
     * Process a failed payment webhook.
     * Marks the StudentPaymentTerm as failed.
     */
    private function handlePaymentFailed(array $payload): void
    {
        $checkoutSession = data_get($payload, 'data.attributes.data');

        if (!$checkoutSession) {
            Log::warning('PayMongo webhook payment.failed: no checkout_session data');
            return;
        }

        $paymentIntentId = data_get($checkoutSession, 'payment_intent.id')
            ?? data_get($checkoutSession, 'payments.0.attributes.payment_intent_id');

        if (!$paymentIntentId) {
            Log::warning('PayMongo webhook payment.failed: missing payment_intent_id');
            return;
        }

        $paymentTerm = StudentPaymentTerm::where('payment_intent_id', $paymentIntentId)->first();

        if ($paymentTerm) {
            $paymentTerm->update(['status' => PaymentStatus::FAILED->value]);

            Log::info('PayMongo webhook: payment marked as failed', [
                'payment_term_id' => $paymentTerm->id,
                'user_id' => $paymentTerm->user_id,
                'payment_intent_id' => $paymentIntentId,
                'reason' => data_get($checkoutSession, 'payments.0.attributes.last_payment_error'),
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Signature Verification
    // -------------------------------------------------------------------------

    /**
     * Verify PayMongo webhook signature.
     * Uses HMAC-SHA256 with the webhook secret and timestamp.
     */
    private function isValidSignature(Request $request): bool
    {
        $secret = config('services.paymongo.webhook_secret');

        if (!$secret) {
            Log::error('PayMongo webhook: PAYMONGO_WEBHOOK_SECRET not configured');
            return false;
        }

        $signatureHeader = $request->header('Paymongo-Signature');

        if (!$signatureHeader) {
            Log::warning('PayMongo webhook: Paymongo-Signature header missing');
            return false;
        }

        // Parse signature header: "t=timestamp,te=test_sig,li=live_sig"
        $parts = [];
        foreach (explode(',', $signatureHeader) as $part) {
            if (strpos($part, '=') === false) {
                continue;
            }
            [$key, $value] = explode('=', $part, 2);
            $parts[$key] = $value;
        }

        $timestamp = $parts['t'] ?? null;
        $isLiveMode = app()->isProduction();
        $signature = $isLiveMode ? ($parts['li'] ?? null) : ($parts['te'] ?? null);

        if (!$timestamp || !$signature) {
            Log::warning('PayMongo webhook: timestamp or signature missing', [
                'has_timestamp' => isset($parts['t']),
                'has_live' => isset($parts['li']),
                'has_test' => isset($parts['te']),
                'is_production' => $isLiveMode,
            ]);
            return false;
        }

        // Build signed string: timestamp + "." + raw body
        $rawBody = $request->getContent();
        $toSign = $timestamp . '.' . $rawBody;
        $computed = hash_hmac('sha256', $toSign, $secret);

        $isValid = hash_equals($computed, $signature);

        if (!$isValid) {
            Log::warning('PayMongo webhook: signature verification failed', [
                'timestamp' => $timestamp,
                'has_raw_body' => !empty($rawBody),
            ]);
            return false;
        }

        return true;
    }
}
