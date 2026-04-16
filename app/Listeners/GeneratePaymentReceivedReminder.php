<?php

namespace App\Listeners;

use App\Events\PaymentRecorded;
use App\Models\PaymentReminder;
use App\Models\StudentAssessment;

class GeneratePaymentReceivedReminder
{
    public function handle(PaymentRecorded $event): void
    {
        $user = $event->user;

        // Resolve the correct assessment via transaction meta, not just "latest"
        $assessment = $this->resolveAssessment($user, $event->transactionId);

        if (! $assessment) {
            return;
        }

        $paymentTerms = $assessment->paymentTerms()
            ->where('balance', '>', 0)
            ->orderBy('term_order')
            ->get();

        $remainingBalance = $paymentTerms->sum('balance');

        if ($remainingBalance > 0) {
            $message = "Payment of ₱" . number_format($event->amount, 2)
                     . " received. Outstanding balance: ₱" . number_format($remainingBalance, 2);
            $type = PaymentReminder::TYPE_PARTIAL_PAYMENT;
        } else {
            $message = "Payment of ₱" . number_format($event->amount, 2)
                     . " received. Account balance fully paid!";
            $type = PaymentReminder::TYPE_PAYMENT_RECEIVED;
        }

        PaymentReminder::create([
            'user_id'                 => $user->id,
            'student_assessment_id'   => $assessment->id,
            'student_payment_term_id' => $paymentTerms->first()?->id,
            'type'                    => $type,
            'message'                 => $message,
            'outstanding_balance'     => $remainingBalance,
            'status'                  => PaymentReminder::STATUS_SENT,
            'in_app_sent'             => true,
            'sent_at'                 => now(),
            'trigger_reason'          => PaymentReminder::TRIGGER_ADMIN_UPDATE,
            'triggered_by'            => $event->triggeredBy,  // ← use event payload
            'metadata'                => [
                'transaction_id' => $event->transactionId,
                'reference'      => $event->reference,
                'payment_amount' => $event->amount,
            ],
        ]);
        
        $user->notify(new \App\Notifications\PaymentDueNotification(
            $assessment->paymentTerms()->where('balance', '>', 0)->first()?->term_name ?? 'Payment',
            (float) $remainingBalance,
            $assessment->paymentTerms()->where('balance', '>', 0)->first()?->due_date,
        ));
    }

    private function resolveAssessment(\App\Models\User $user, int $transactionId): ?StudentAssessment
    {
        $transaction = $user->transactions()->find($transactionId);

        if ($transaction && ! empty($transaction->meta['assessment_id'])) {
            $assessment = StudentAssessment::find($transaction->meta['assessment_id']);
            if ($assessment && $assessment->user_id === $user->id) {
                return $assessment;
            }
        }

        return $user->assessments()->latest('created_at')->first();
    }
}