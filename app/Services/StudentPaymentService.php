<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\User;
use App\Models\Transaction;
use App\Models\Payment;
use App\Models\Notification;
use App\Models\StudentAssessment;
use App\Models\StudentPaymentTerm;
use App\Services\AccountService;          // FIX #3: was missing
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class StudentPaymentService
{
    /**
     * Process a payment for a user against a specific payment term.
     *
     * ⚠️ IMPORTANT: When $requiresApproval=true, the CALLER is responsible for
     * starting the approval workflow. This service does NOT automatically start
     * workflows. If a workflow is not created, accounting will never see the
     * pending payment for approval. See TransactionController::payNow() for an
     * example of proper workflow initialization.
     *
     * @param  User   $user             The user making the payment
     * @param  float  $amount           Amount being paid
     * @param  array  $options {
     *     payment_method:   string,
     *     paid_at:          string (date),
     *     description:      string|null,
     *     selected_term_id: int,
     *     term_name:        string|null,
     *     year:             int|null,
     *     semester:         string|null,
     * }
     * @param  bool   $requiresApproval Whether the payment needs admin approval
     * @return array {
     *     transaction_id:        int,
     *     transaction_reference: string,
     *     message:               string,
     * }
     *
     * @throws \Exception on validation or processing failure
     */
    public function processPayment(User $user, float $amount, array $options, bool $requiresApproval = true): array
    {
        $termId = (int) ($options['selected_term_id'] ?? 0);

        if ($termId === 0) {
            throw new \Exception('A payment term must be selected.');
        }

        $term = StudentPaymentTerm::findOrFail($termId);

        if ($amount <= 0) {
            throw new \Exception('Payment amount must be greater than zero.');
        }

        return DB::transaction(function () use ($user, $amount, $options, $term, $requiresApproval) {

            $reference = 'PAY-' . Str::upper(Str::random(8));

            // Determine transaction status based on approval requirement
            $status = $requiresApproval
                ? PaymentStatus::AWAITING_APPROVAL->value
                : PaymentStatus::PAID->value;

            // Normalise description — never null to satisfy DB NOT NULL constraint
            $description = $options['description'] ?? null;
            if (empty($description)) {
                $description = 'Payment — ' . ($options['term_name'] ?? $term->term_name);
            }

            // Build meta for audit trail. Store selected_term_id so finalizeApprovedPayment()
            // can look up the EXACT term without relying on term_name string-matching.
            $meta = [
                'payment_method'    => $options['payment_method'] ?? null,
                'description'       => $description,
                'term_name'         => $options['term_name'] ?? $term->term_name,
                'selected_term_id'  => $term->id,
                'requires_approval' => $requiresApproval,
            ];

            // Create the transaction record
            $transaction = Transaction::create([
                'user_id'         => $user->id,
                'reference'       => $reference,
                'kind'            => 'payment',
                'type'            => $options['term_name'] ?? $term->term_name,
                'amount'          => $amount,
                'status'          => $status,
                'payment_channel' => $options['payment_method'] ?? null,
                'paid_at'         => $options['paid_at'] ?? now(),
                'year'            => $options['year'] ?? now()->year,
                'semester'        => $options['semester'] ?? null,
                'meta'            => $meta,
            ]);

            // Update payment term balance and status only when immediately approved (staff-side payment)
            if (! $requiresApproval) {
                $newBalance = max(0, (float) $term->balance - $amount);
                $newStatus  = $newBalance <= 0
                    ? PaymentStatus::PAID->value
                    : PaymentStatus::PARTIAL->value;

                $term->update([
                    'balance'   => $newBalance,
                    'status'    => $newStatus,
                    'paid_date' => $newStatus === PaymentStatus::PAID->value ? now() : $term->paid_date,
                ]);

                if ($user->student) {
                    Payment::create([
                        'student_id'            => $user->student->id,
                        'student_assessment_id' => $term->student_assessment_id,
                        'amount'                => $amount,
                        'payment_method'        => $options['payment_method'] ?? null,
                        'description'           => $description,
                        'status'                => PaymentStatus::COMPLETED->value,
                        'created_at'            => $options['paid_at'] ?? now(),
                        'updated_at'            => $options['paid_at'] ?? now(),
                    ]);
                }

                // FIX #3: AccountService is now properly imported at top of file
                AccountService::recalculate($user);

                $this->checkAndNotifyProgressionReady($user, $term->student_assessment_id);

                $message = 'Payment of ₱' . number_format($amount, 2) . ' recorded successfully.';
            } else {
                $message = 'Payment of ₱' . number_format($amount, 2) . ' submitted and is awaiting accounting approval.';
            }

            return [
                'transaction_id'        => $transaction->id,
                'transaction_reference' => $reference,
                'message'               => $message,
            ];
        });
    }

    /**
     * Finalize an approved payment by updating the transaction and payment term.
     * Implements sequential allocation across terms if the payment exceeds the selected term's balance.
     */
    public function finalizeApprovedPayment(Transaction $transaction): void
    {
        if ($transaction->kind !== 'payment') {
            throw new \Exception('Transaction is not a payment.');
        }

        if ($transaction->status === PaymentStatus::PAID->value) {
            return;
        }

        DB::transaction(function () use ($transaction) {
            $user   = $transaction->user;
            $amount = (float) $transaction->amount;

            // Priority 1: use the term ID stored in meta
            $termId = isset($transaction->meta['selected_term_id'])
                ? (int) $transaction->meta['selected_term_id']
                : null;

            $term = null;

            if ($termId) {
                $term = StudentPaymentTerm::find($termId);
            }

            // Fallback: match by term name scoped to user assessments
            if (! $term) {
                $termName = $transaction->meta['term_name'] ?? $transaction->type;

                Log::warning('finalizeApprovedPayment: term_id not in meta, falling back to name match', [
                    'transaction_id' => $transaction->id,
                    'term_name'      => $termName,
                    'user_id'        => $user->id,
                ]);

                $term = StudentPaymentTerm::whereHas('assessment', function ($q) use ($user) {
                    $q->where('user_id', $user->id);
                })
                    ->where('term_name', $termName)
                    ->whereIn('status', PaymentStatus::unpaidValues())
                    ->orderBy('due_date', 'desc')
                    ->first();
            }

            if (! $term) {
                throw new \Exception(
                    "Could not find StudentPaymentTerm for transaction #{$transaction->id} (user {$user->id}). " .
                    'Payment cannot be finalized without a term reference.'
                );
            }

            // Sequential allocation across terms
            $allocation = [];
            $remaining  = $amount;

            $selectedTermBalance = round((float) $term->balance, 2);
            $appliedToSelected   = round(min($remaining, $selectedTermBalance), 2);
            $newBalance          = round($selectedTermBalance - $appliedToSelected, 2);
            $newStatus           = $newBalance <= 0
                ? PaymentStatus::PAID->value
                : PaymentStatus::PARTIAL->value;

            $term->update([
                'balance'   => $newBalance,
                'status'    => $newStatus,
                'paid_date' => $newStatus === PaymentStatus::PAID->value
                    ? now()
                    : $term->paid_date,
            ]);

            $allocation[] = [
                'term_id'        => $term->id,
                'term_name'      => $term->term_name,
                'term_order'     => $term->term_order,
                'applied'        => $appliedToSelected,
                'balance_before' => $selectedTermBalance,
                'balance_after'  => $newBalance,
                'status_after'   => $newStatus,
            ];

            $remaining = round($remaining - $appliedToSelected, 2);

            if ($remaining > 0) {
                $otherTerms = StudentPaymentTerm::where('student_assessment_id', $term->student_assessment_id)
                    ->whereIn('status', PaymentStatus::unpaidValues())
                    ->where('id', '!=', $term->id)
                    ->orderBy('term_order', 'asc')
                    ->get();

                foreach ($otherTerms as $otherTerm) {
                    if ($remaining <= 0) {
                        break;
                    }

                    $otherTermBalance = round((float) $otherTerm->balance, 2);
                    $appliedToOther   = round(min($remaining, $otherTermBalance), 2);
                    $otherNewBalance  = round($otherTermBalance - $appliedToOther, 2);
                    $otherNewStatus   = $otherNewBalance <= 0
                        ? PaymentStatus::PAID->value
                        : PaymentStatus::PARTIAL->value;

                    $otherTerm->update([
                        'balance'   => $otherNewBalance,
                        'status'    => $otherNewStatus,
                        'paid_date' => $otherNewStatus === PaymentStatus::PAID->value
                            ? now()
                            : $otherTerm->paid_date,
                    ]);

                    $allocation[] = [
                        'term_id'        => $otherTerm->id,
                        'term_name'      => $otherTerm->term_name,
                        'term_order'     => $otherTerm->term_order,
                        'applied'        => $appliedToOther,
                        'balance_before' => $otherTermBalance,
                        'balance_after'  => $otherNewBalance,
                        'status_after'   => $otherNewStatus,
                    ];

                    $remaining = round($remaining - $appliedToOther, 2);
                }
            }

            // Create Payment records per term
            $totalApplied = round($amount - $remaining, 2);

            foreach ($allocation as $alloc) {
                if ($user->student) {
                    Payment::create([
                        'student_id'            => $user->student->id,
                        'student_assessment_id' => $term->student_assessment_id,
                        'amount'                => $alloc['applied'],
                        'payment_method'        => $transaction->payment_channel,
                        'description'           => 'Payment — ' . $alloc['term_name']
                            . ' (from ₱' . number_format($totalApplied, 2) . ' total)',
                        'status'                => PaymentStatus::COMPLETED->value,
                        'created_at'            => $transaction->created_at ?? now(),
                        'updated_at'            => $transaction->created_at ?? now(),
                    ]);
                }
            }

            if (count($allocation) > 1) {
                $termsLabel  = collect($allocation)->pluck('term_name')->implode(', ');
                $description = '₱' . number_format($totalApplied, 2) . ' allocated across: ' . $termsLabel;
                if ($remaining > 0) {
                    $description .= '. Excess: ₱' . number_format($remaining, 2);
                }
            } else {
                $description = 'Payment — ' . ($allocation[0]['term_name'] ?? 'Term');
            }

            $transaction->update([
                'status' => PaymentStatus::PAID->value,
                'meta'   => array_merge($transaction->meta ?? [], [
                    'allocation'    => $allocation,
                    'terms_covered' => count($allocation),
                    'total_applied' => $totalApplied,
                    'unallocated'   => $remaining,
                    'description'   => $description,
                ]),
            ]);

            // FIX #3: AccountService is now properly imported
            AccountService::recalculate($user);

            $this->checkAndNotifyProgressionReady($user, $term->student_assessment_id);

            Log::info('Payment finalized with allocation', [
                'transaction_id'   => $transaction->id,
                'selected_term_id' => $term->id,
                'amount'           => $amount,
                'terms_allocated'  => count($allocation),
                'total_applied'    => $totalApplied,
                'unallocated'      => $remaining,
            ]);
        });
    }

    /**
     * Cancel a rejected payment by updating the transaction status.
     */
    public function cancelRejectedPayment(Transaction $transaction): void
    {
        if ($transaction->kind !== 'payment') {
            throw new \Exception('Transaction is not a payment.');
        }

        DB::transaction(function () use ($transaction) {
            $transaction->update(['status' => PaymentStatus::CANCELLED->value]);

            Log::info('Payment cancelled due to workflow rejection', [
                'transaction_id' => $transaction->id,
                'amount'         => $transaction->amount,
                'reference'      => $transaction->reference,
            ]);
        });
    }

    /**
     * Get the total outstanding balance for a user, derived from their payment terms.
     */
    public function getTotalOutstandingBalance(User $user): float
    {
        return (float) StudentPaymentTerm::whereHas('assessment', function ($q) use ($user) {
            $q->where('user_id', $user->id);
        })
            ->whereIn('status', PaymentStatus::unpaidValues())
            ->sum('balance');
    }

    /**
     * Public proxy for checkAndNotifyProgressionReady.
     */
    public function notifyProgressionIfComplete(User $user, int $assessmentId): void
    {
        $this->checkAndNotifyProgressionReady($user, $assessmentId);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE: Semester Completion Detection + Admin Notification
    // ─────────────────────────────────────────────────────────────────────────

    private function checkAndNotifyProgressionReady(User $user, int $assessmentId): void
    {
        try {
            $assessment = StudentAssessment::with('paymentTerms')->find($assessmentId);

            if (! $assessment) {
                return;
            }

            $allPaid = $assessment->paymentTerms->isNotEmpty()
                && $assessment->paymentTerms->every(
                    fn ($t) => $t->status === PaymentStatus::PAID->value
                );

            if (! $allPaid) {
                return;
            }

            $alreadyNotified = Notification::where('type', 'progression_ready')
                ->whereJsonContains('term_ids', $assessmentId)
                ->exists();

            if ($alreadyNotified) {
                Log::info('StudentPaymentService: progression notification already exists, skipping', [
                    'user_id'       => $user->id,
                    'assessment_id' => $assessmentId,
                ]);
                return;
            }

            $yearLevel   = $assessment->year_level;
            $semester    = $assessment->semester;
            $schoolYear  = $assessment->school_year;
            $studentName = trim($user->first_name . ' ' . $user->last_name);
            $nextLabel   = $this->resolveNextSemesterLabel($yearLevel, $semester);

            Notification::create([
                'title'       => "📋 Assessment Required: {$studentName}",
                'message'     => "{$studentName} (ID: {$user->account_id}) has fully paid their "
                               . "{$yearLevel} {$semester} ({$schoolYear}) assessment. "
                               . "Please create their {$nextLabel} assessment via Student Fees → Create Assessment.",
                'type'        => 'progression_ready',
                'target_role' => 'admin',
                'user_id'     => null,
                'is_active'   => true,
                'is_complete' => false,
                'start_date'  => now()->toDateString(),
                'end_date'    => now()->addDays(30)->toDateString(),
                'term_ids'    => [$assessmentId],
            ]);

            Notification::create([
                'title'       => "✅ {$yearLevel} {$semester} Fully Paid!",
                'message'     => "Congratulations! You have fully settled all payment terms for "
                               . "{$yearLevel} {$semester} ({$schoolYear}). "
                               . "The admin is now preparing your {$nextLabel} assessment. "
                               . 'You will be notified once it is ready.',
                'type'        => 'payment_due',
                'target_role' => 'student',
                'user_id'     => $user->id,
                'is_active'   => true,
                'is_complete' => false,
                'start_date'  => now()->toDateString(),
                'end_date'    => now()->addDays(14)->toDateString(),
            ]);

            Log::info('StudentPaymentService: progression_ready notifications sent', [
                'user_id'       => $user->id,
                'assessment_id' => $assessmentId,
                'year_level'    => $yearLevel,
                'semester'      => $semester,
                'next_label'    => $nextLabel,
            ]);

        } catch (\Exception $e) {
            Log::error('StudentPaymentService: failed to send progression_ready notification', [
                'user_id'       => $user->id,
                'assessment_id' => $assessmentId,
                'error'         => $e->getMessage(),
            ]);
        }
    }

    private function resolveNextSemesterLabel(string $yearLevel, string $semester): string
    {
        $progression = [
            '1st Year|1st Sem' => '1st Year 2nd Sem',
            '1st Year|2nd Sem' => '2nd Year 1st Sem',
            '2nd Year|1st Sem' => '2nd Year 2nd Sem',
            '2nd Year|2nd Sem' => '3rd Year 1st Sem',
            '3rd Year|1st Sem' => '3rd Year 2nd Sem',
            '3rd Year|2nd Sem' => '4th Year 1st Sem',
            '4th Year|1st Sem' => '4th Year 2nd Sem',
            '4th Year|2nd Sem' => 'graduation (program completed)',
        ];

        return $progression["{$yearLevel}|{$semester}"] ?? 'next semester';
    }
}