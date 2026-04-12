<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
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

    // ✅ Ito ang kulang — display ng payment page
    public function create()
    {
        $student = auth()->user()->student;

        return Inertia::render('Payment/Create', [
            'student'   => $student,
            'publicKey' => config('services.paymongo.public_key'),
        ]);
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
                        'success_url' => url('/payment/success') . '?session_id={CHECKOUT_SESSION_ID}',
                        'cancel_url'  => url('/payment/cancel'),
                        'description' => $validated['description'],
                    ]
                ]
            ]);

        if ($response->failed()) {
            return response()->json(['error' => 'Payment session failed.'], 500);
        }

        $session = $response->json('data');

        \App\Models\Payment::create([
            'student_id'        => $validated['student_id'],
            'amount'            => $validated['amount'],
            'description'       => $validated['description'],
            'payment_method'    => 'paymongo_checkout',
            'status'            => 'pending',
            'paymongo_source_id'=> $session['id'],
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

            \App\Models\Payment::where('paymongo_source_id', $sessionId)
                ->update(['status' => $status === 'succeeded' ? 'completed' : 'pending']);
        }

        return redirect('/student/dashboard?payment=success');
    }

    public function cancel()
    {
        return redirect('/student/dashboard?payment=cancelled');
    }

    public function getBankDetails()
    {
        return response()->json([
            'bank_details' => [
                'account_name'   => config('services.bank.account_name', 'CCDI School'),
                'account_number' => config('services.bank.account_number', '1234-5678-9012'),
                'bank_name'      => config('services.bank.bank_name', 'PNB'),
            ]
        ]);
    }

    public function submitBankTransfer(Request $request)
    {
        $validated = $request->validate([
            'student_id'       => 'required|exists:students,id',
            'amount'           => 'required|numeric|min:100',
            'reference_number' => 'required|string',
        ]);

        \App\Models\Payment::create([
            'student_id'       => $validated['student_id'],
            'amount'           => $validated['amount'],
            'reference_number' => $validated['reference_number'],
            'payment_method'   => 'bank_transfer',
            'status'           => 'pending',
            'description'      => 'Bank Transfer - PNB',
        ]);

        return response()->json(['message' => 'Bank transfer submitted successfully.']);
    }

    public function checkStatus(Request $request)
    {
        $request->validate(['payment_id' => 'required|exists:payments,id']);
        $payment = \App\Models\Payment::find($request->payment_id);
        return response()->json(['status' => $payment->status]);
    }

    public function verifyBankTransfer(Request $request, \App\Models\Payment $payment)
    {
        $request->validate(['verified' => 'required|boolean']);
        $payment->update([
            'status' => $request->verified ? 'completed' : 'failed',
        ]);
        return response()->json(['message' => 'Payment verified successfully.']);
    }
}