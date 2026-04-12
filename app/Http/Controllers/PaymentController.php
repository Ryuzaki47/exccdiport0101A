<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class PaymentController extends Controller
{
    private $secretKey;
    private $baseUrl = 'https://api.paymongo.com/v1';

    public function __construct()
    {
        $this->secretKey = config('services.paymongo.secret_key');
    }

    public function createCheckout(Request $request)
    {
        $validated = $request->validate([
            'student_id'     => 'required|exists:students,id',
            'transaction_id' => 'nullable|exists:transactions,id',
            'amount'         => 'required|numeric|min:100',
            'description'    => 'required|string',
        ]);

        $amountInCentavos = (int) ($validated['amount'] * 100);

        $response = Http::withBasicAuth($this->secretKey, '')
            ->post("{$this->baseUrl}/checkout_sessions", [
                'data' => [
                    'attributes' => [
                        'billing' => [
                            'name'  => auth()->user()->name,
                            'email' => auth()->user()->email,
                            'phone' => auth()->user()->phone ?? '09000000000',
                        ],
                        'line_items' => [[
                            'currency' => 'PHP',
                            'amount'   => $amountInCentavos,
                            'name'     => $validated['description'],
                            'quantity' => 1,
                        ]],
                        'payment_method_types' => ['gcash', 'paymaya', 'card'],
                        'success_url' => route('payment.success') . '?session_id={CHECKOUT_SESSION_ID}',
                        'cancel_url'  => route('payment.cancel'),
                        'description' => $validated['description'],
                    ]
                ]
            ]);

        if ($response->failed()) {
            return response()->json(['error' => 'Payment session failed.'], 500);
        }

        $session = $response->json('data');

        \App\Models\Payment::create([
            'student_id'                   => $validated['student_id'],
            'transaction_id'               => $validated['transaction_id'] ?? null,
            'paymongo_checkout_session_id' => $session['id'],
            'amount'                       => $validated['amount'],
            'description'                  => $validated['description'],
            'status'                       => 'pending',
        ]);

        return response()->json([
            'checkout_url' => $session['attributes']['checkout_url']
        ]);
    }

    public function success(Request $request)
    {
        $sessionId = $request->query('session_id');

        $response = Http::withBasicAuth($this->secretKey, '')
            ->get("{$this->baseUrl}/checkout_sessions/{$sessionId}");

        if ($response->ok()) {
            $status = $response->json('data.attributes.payment_intent.attributes.status');

            \App\Models\Payment::where('paymongo_checkout_session_id', $sessionId)
                ->update(['status' => $status === 'succeeded' ? 'paid' : 'pending']);
        }

        return redirect('/student/payments?status=success');
    }

    public function cancel()
    {
        return redirect('/student/payments?status=cancelled');
    }
}