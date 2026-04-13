<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Payment;

class PaymentController extends Controller
{
    private string $secretKey;
    private string $publicKey;
    private string $baseUrl = 'https://api.paymongo.com/v1';

    public function __construct()
    {
        // FIX: was 'services.paymongo.secret_key' — key is 'secret', not 'secret_key'
        $this->secretKey = config('services.paymongo.secret');
        $this->publicKey = config('services.paymongo.public');
    }

    public function create()
    {
        $user    = auth()->user();
        $student = $user->student;

        if (! $student) {
            abort(403, 'No student record linked to this account.');
        }

        return Inertia::render('Payment/Create', [
            'student'   => [
                'id'   => $student->id,
                'name' => $user->name,
            ],
            'publicKey' => $this->publicKey,
        ]);
    }

    public function createCheckout(Request $request)
    {
        $validated = $request->validate([
            'student_id'  => 'required|exists:students,id',
            'amount'      => 'required|numeric|min:100',
            'description' => 'required|string|max:255',
        ]);

        // Abort early if secret key is missing — gives a clear 500 message instead of silent null auth
        abort_if(empty($this->secretKey), 500, 'PayMongo secret key is not configured.');

        $amountInCentavos = (int) round($validated['amount'] * 100);

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
                    ],
                ],
            ]);

        if ($response->failed()) {
            Log::error('PayMongo checkout session failed', [
                'status'   => $response->status(),
                'body'     => $response->body(),
                'student'  => $validated['student_id'],
            ]);

            return response()->json([
                'error' => 'Payment session could not be created. Please try again.',
            ], 500);
        }

        $session = $response->json('data');

        Payment::create([
            'student_id'         => $validated['student_id'],
            'amount'             => $validated['amount'],
            'description'        => $validated['description'],
            'payment_method'     => 'paymongo_checkout',
            'status'             => 'pending',
            'paymongo_source_id' => $session['id'],
        ]);

        return response()->json([
            'checkout_url' => $session['attributes']['checkout_url'],
        ]);
    }

    public function success(Request $request)
    {
        $sessionId = $request->query('session_id');

        if ($sessionId) {
            $response = Http::withBasicAuth($this->secretKey, '')
                ->get("{$this->baseUrl}/checkout_sessions/{$sessionId}");

            if ($response->ok()) {
                $status = $response->json('data.attributes.payment_intent.attributes.status');

                Payment::where('paymongo_source_id', $sessionId)
                    ->update(['status' => $status === 'succeeded' ? 'completed' : 'pending']);
            }
        }

        return redirect()->route('student.dashboard', ['payment' => 'success']);
    }

    public function cancel()
    {
        return redirect()->route('student.dashboard', ['payment' => 'cancelled']);
    }

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