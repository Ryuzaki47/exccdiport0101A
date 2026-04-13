<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\User;
use App\Models\Transaction;
use App\Models\Payment;
use App\Models\Notification;
use App\Models\StudentAssessment;
use App\Models\StudentPaymentTerm;
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
            // can look up the EXACT term without relying on term_name string-matching, which
            // breaks when a student has multiple assessments with identically-named terms.
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

                // FIX (Bug #4): Added student_assessment_id so PDF export can filter
                // payments by assessment. Without it, assessment-scoped payment history
                // was always empty for staff-recorded payments.
                if ($user->student) {
                    Payment::create([
                        'student_id'           => $user->student->id,
                        'student_assessment_id' => $term->student_assessment_id,
                        'amount'               => $amount,
                        'payment_method'       => $options['payment_method'] ?? null,
                        'description'          => $description,
                        'status'               => PaymentStatus::COMPLETED->value,
                        'created_at'           => $options['paid_at'] ?? now(),
                        'updated_at'           => $options['paid_at'] ?? now(),
                    ]);
                }

                // Recalculate account balance
                AccountService::recalculate($user);

                // Check if all terms of this assessment are now fully paid.
                // If yes, notify admin to create the next semester's assessment.
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
     * Called when a payment approval workflow is completed.
     *
     * Uses the stored `selected_term_id` in transaction meta for reliable term
     * lookup — avoids mismatches when a student has multiple assessments that
     * contain identically-named payment terms (e.g. "Upon Registration" for both
     * 1st Sem and 2nd Sem assessments).
     *
     * @param  Transaction $transaction The approved payment transaction
     * @return void
     * @throws \Exception on processing failure
     */
    /**
     * Finalize an approved payment by updating the transaction and payment term.
     * Implements sequential allocation across terms if the payment exceeds the selected term's balance.
     * Called when a payment approval workflow is completed.
     *
     * ── Allocation Logic ──
     * If payment amount > selected term's balance:
     *   1. Apply up to the selected term's balance to that term
     *   2. Allocate remaining balance sequentially to other unpaid terms
     *   3. Document allocation in transaction meta
     *   4. Note any unallocated excess (overpayment beyond total outstanding)
     *
     * @param  Transaction $transaction The approved payment transaction
     * @return void
     */
    public function finalizeApprovedPayment(Transaction $transaction): void
    {
        if ($transaction->kind !== 'payment') {
            throw new \Exception('Transaction is not a payment.');
        }

        if ($transaction->status === PaymentStatus::PAID->value) {
            // Already finalized — idempotent, skip
            return;
        }

        DB::transaction(function () use ($transaction) {
            $user   = $transaction->user;
            $amount = (float) $transaction->amount;

            // ── Priority 1: use the term ID stored in meta ──
            $termId = isset($transaction->meta['selected_term_id'])
                ? (int) $transaction->meta['selected_term_id']
                : null;

            $term = null;

            if ($termId) {
                $term = StudentPaymentTerm::find($termId);
            }

            // ── BUG FIX #4: User doesn't have assessments() relationship ──
            // Fallback: match by term name scoped to user. Query StudentAssessment directly
            if (! $term) {
                $termName = $transaction->meta['term_name'] ?? $transaction->type;

                Log::warning('finalizeApprovedPayment: term_id not in meta, falling back to name match', [
                    'transaction_id' => $transaction->id,
                    'term_name'      => $termName,
                    'user_id'        => $user->id,
                ]);

                // Query through StudentAssessment directly instead of non-existent User::assessments()
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

            // ── Sequential Allocation across terms ──────────────────────────────
            // If payment exceeds selected term's balance, allocate remainder to other terms.
            // This matches StudentFeeController::storePayment() allocation pattern.
            
            $allocation = [];
            $remaining  = $amount;

            // START: Apply to selected term first
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

            // IF OVERPAYMENT: allocate remainder to other unpaid terms (in order)
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

            // ── Create Payment records per term ──────────────────────────────────
            // One Payment row per term to give per-term payment history
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

            // ── Build description reflecting allocation ────────────────────────
            if (count($allocation) > 1) {
                $termsLabel  = collect($allocation)->pluck('term_name')->implode(', ');
                $description = '₱' . number_format($totalApplied, 2) . ' allocated across: ' . $termsLabel;
                if ($remaining > 0) {
                    $description .= '. Excess: ₱' . number_format($remaining, 2);
                }
            } else {
                $description = 'Payment — ' . ($allocation[0]['term_name'] ?? 'Term');
            }

            // ── Mark transaction as paid and update meta with allocation details ──
            $transaction->update([
                'status' => PaymentStatus::PAID->value,
                'meta'   => array_merge($transaction->meta ?? [], [
                    'allocation'        => $allocation,
                    'terms_covered'     => count($allocation),
                    'total_applied'     => $totalApplied,
                    'unallocated'       => $remaining,
                    'description'       => $description,
                ]),
            ]);

            // ── Recalculate account balance ──
            AccountService::recalculate($user);

            // ── Check if all terms of this assessment are now fully paid ──
            // If yes, notify admin to create the next semester's assessment.
            $this->checkAndNotifyProgressionReady($user, $term->student_assessment_id);

            Log::info('Payment finalized with allocation', [
                'transaction_id' => $transaction->id,
                'selected_term_id' => $term->id,
                'amount'        => $amount,
                'terms_allocated' => count($allocation),
                'total_applied' => $totalApplied,
                'unallocated'   => $remaining,
            ]);
        });
    }

    /**
     * Cancel a rejected payment by updating the transaction status.
     * Called when a payment approval workflow is rejected.
     *
     * @param  Transaction $transaction The rejected payment transaction
     * @return void
     */
    public function cancelRejectedPayment(Transaction $transaction): void
    {
        if ($transaction->kind !== 'payment') {
            throw new \Exception('Transaction is not a payment.');
        }

        DB::transaction(function () use ($transaction) {
            // Mark as cancelled — term balance was never deducted (payment was pending)
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
     * Queries through StudentAssessment to ensure consistent access pattern.
     *
     * @param  User $user
     * @return float
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
     * Called by StudentFeeController::storePayment() after the multi-term
     * allocation completes, so the controller does not need to duplicate
     * the progression-ready notification logic.
     */
    public function notifyProgressionIfComplete(User $user, int $assessmentId): void
    {
        $this->checkAndNotifyProgressionReady($user, $assessmentId);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE: Semester Completion Detection + Admin Notification
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * After every payment finalization (approved or direct), check whether ALL
     * payment terms of the given assessment are now fully paid.
     *
     * If yes:
     *   1. Notify the Admin  — "Student X finished paying [Year] [Sem]. Please
     *                           create their next semester's assessment."
     *   2. Notify the Student — "Your [Year] [Sem] is fully settled. The admin
     *                            is preparing your next semester's payables."
     *
     * The check is idempotent: if a "progression_ready" notification for this
     * exact assessment already exists, it is not duplicated.
     */
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

            // Guard: don't duplicate notifications for the same assessment
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

            // ── 1. Admin notification ─────────────────────────────────────────
            // NOTIFICATION: CUSTOM ADMIN_NOTIFICATIONS
            // Progression ready is a system broadcast; admins need time to process.
            // Uses: Notification::create() → writes to `admin_notifications` table
            // Why: Role targeting (admin), time window (30 days), not user-specific
            // See: docs/NOTIFICATION_ARCHITECTURE.md for system overview
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

            // ── 2. Student notification ───────────────────────────────────────
            // NOTIFICATION: CUSTOM ADMIN_NOTIFICATIONS
            // Progression confirmation is user-specific but system-generated.
            // Uses: Notification::create() → writes to `admin_notifications` table
            // Why: Could send via $user->notify() in future, but using admin_notifications
            //      for consistency with matching admin notification and audit trail
            // See: docs/NOTIFICATION_ARCHITECTURE.md for system overview
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
            // Never let notification failure break payment finalization
            Log::error('StudentPaymentService: failed to send progression_ready notification', [
                'user_id'       => $user->id,
                'assessment_id' => $assessmentId,
                'error'         => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build a human-readable label for the next semester/year.
     */
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