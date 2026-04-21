<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessPaymongoWebhook;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class PaymongoWebhookController extends Controller
{
    /**
     * Handle incoming PayMongo webhook events.
     *
     * CRITICAL DESIGN:
     * - Validate the signature
     * - Return 200 IMMEDIATELY
     * - Dispatch a queued job to handle the actual processing
     *
     * PayMongo will retry if it doesn't receive a 2xx within ~5 seconds.
     * By returning immediately and processing async, we decouple webhook
     * response time from database/workflow processing time entirely.
     *
     * PayMongo disables your webhook after 3 consecutive events that exhaust
     * 12 retry attempts each — so a fast 200 response is mandatory.
     */
    public function handle(Request $request): Response
    {
        if (! $this->isValidSignature($request)) {
            Log::warning('PayMongo webhook: invalid signature rejected.', [
                'ip' => $request->ip(),
            ]);
            // Return 200 intentionally — returning 4xx triggers PayMongo retries
            // on a request that will never succeed (bad secret = always fails).
            return response('Unauthorized', 200);
        }

        $payload   = $request->json()->all();
        $eventType = data_get($payload, 'data.type');

        Log::info('PayMongo webhook received — dispatching job', [
            'event'       => $eventType,
            'event_id'    => data_get($payload, 'data.id'),
            'resource_id' => data_get($payload, 'data.attributes.data.id'),
        ]);

        // Dispatch to queue. Job handles all DB work asynchronously.
        // If the queue connection is down, this will throw and we return 500
        // (acceptable — PayMongo will retry and the job will eventually dispatch).
        ProcessPaymongoWebhook::dispatch($payload, $eventType ?? 'unknown');

        // Always return 200 to PayMongo immediately.
        return response('OK', 200);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Signature Verification
    // ─────────────────────────────────────────────────────────────────────────

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
            if (! str_contains($part, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $part, 2);
            $parts[$key] = $value;
        }

        $timestamp = $parts['t'] ?? null;

        // Accept either live or test signature — whichever is present.
        // The HMAC verification itself determines legitimacy.
        $signature = $parts['li'] ?? $parts['te'] ?? null;

        if (! $timestamp || ! $signature) {
            Log::warning('PayMongo webhook: timestamp or signature missing in header', [
                'has_timestamp' => isset($parts['t']),
                'has_live'      => isset($parts['li']),
                'has_test'      => isset($parts['te']),
            ]);
            return false;
        }

        $rawBody  = $request->getContent();
        $toSign   = $timestamp . '.' . $rawBody;
        $computed = hash_hmac('sha256', $toSign, $secret);
        $isValid  = hash_equals($computed, $signature);

        if (! $isValid) {
            Log::warning('PayMongo webhook: signature mismatch', [
                'timestamp'   => $timestamp,
                'body_length' => strlen($rawBody),
            ]);
        }

        return $isValid;
    }
}