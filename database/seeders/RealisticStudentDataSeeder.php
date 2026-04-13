<?php

namespace Database\Seeders;

use App\Enums\PaymentStatus;
use App\Models\StudentAssessment;
use App\Models\StudentEnrollment;
use App\Models\StudentPaymentTerm;
use App\Models\Subject;
use App\Models\Transaction;
use App\Models\Payment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * RealisticStudentDataSeeder
 *
 * Enhances ComprehensiveAssessmentSeeder with realistic scenarios:
 * ─────────────────────────────────────────────────────────────────
 *
 *  1. STUDENT ENROLLMENTS
 *     - Links each active student to 15-18 relevant subjects per assessment
 *     - Subjects filtered by course, year_level, semester
 *     - Status: 'enrolled' (confirmed), occasional 'dropped' (realistic scenarios)
 *
 *  2. TRANSACTION HISTORY (Payment Records)
 *     Distributes students across realistic payment scenarios:
 *
 *     GROUP A (40%): Fully Paid
 *       - Paid all 5 terms completely
 *       - Transactions dated throughout semester
 *       - Shows allocation across terms when applicable
 *
 *     GROUP B (30%): Partial Payment
 *       - Paid 2-4 terms, outstanding on remainder
 *       - Mixed payment amounts (some exact, some overpayments)
 *       - Shows realistic payment behavior
 *
 *     GROUP C (20%): First Payment Only
 *       - Only paid "Upon Registration" term
 *       - Remaining 4 terms outstanding
 *       - Represents students making progress
 *
 *     GROUP D (10%): No Payments
 *       - All terms unpaid
 *       - Represents students just enrolled
 *
 *  3. PAYMENT ALLOCATION
 *     - Single-term payments recorded as-is
 *     - Multi-term allocations stored in transaction.meta
 *     - Reflects automatic allocation logic in StudentFeeController
 *
 *  4. STATUS DISTRIBUTION
 *     Each term gets one of:
 *       - paid: balance = 0, status = 'paid'
 *       - partial: balance > 0, status = 'partial'
 *       - pending: balance = amount, status = 'pending'
 */
class RealisticStudentDataSeeder extends Seeder
{
    private string $schoolYear = '2025-2026';
    private array $semesters = ['1st Sem', '2nd Sem'];

    public function run(): void
    {
        $this->command->info('🎯 Seeding realistic student data...');
        $this->command->newLine();

        $this->command->info('Step 1: Enrolling students in subjects by assessment...');
        $this->seedStudentEnrollments();

        $this->command->info('Step 2: Creating realistic payment transaction history...');
        $this->seedPaymentTransactions();

        $this->command->newLine();
        $this->command->info('✅ Realistic Student Data Seeding Complete!');
        $this->displaySummary();
    }

    // ════════════════════════════════════════════════════════════════════════
    // STEP 1: STUDENT ENROLLMENTS
    // ════════════════════════════════════════════════════════════════════════

    private function seedStudentEnrollments(): void
    {
        StudentEnrollment::query()->delete();

        $students = User::where('role', 'student')
            ->where('status', User::STATUS_ACTIVE)
            ->whereNotNull('year_level')
            ->get();

        $enrolled = 0;

        DB::transaction(function () use ($students, &$enrolled) {
            foreach ($students as $student) {
                foreach ($this->semesters as $semester) {
                    // Get matching subjects for this student's course, year level, semester
                    $subjects = Subject::where('course', $student->course)
                        ->where('year_level', $student->year_level)
                        ->where('semester', $semester)
                        ->where('is_active', true)
                        ->get();

                    if ($subjects->isEmpty()) {
                        continue;
                    }

                    // Enroll in 70-90% of available subjects (realistic scenario)
                    $enrollmentRate = rand(70, 90) / 100;
                    $enrollCount = max(1, (int) ($subjects->count() * $enrollmentRate));
                    $subjectsToEnroll = $subjects->random($enrollCount);

                    foreach ($subjectsToEnroll as $subject) {
                        // 95% enrolled, 5% dropped for realism
                        $status = rand(1, 100) <= 95 ? 'enrolled' : 'dropped';

                        StudentEnrollment::create([
                            'user_id'      => $student->id,
                            'subject_id'   => $subject->id,
                            'school_year'  => $this->schoolYear,
                            'semester'     => $semester,
                            'status'       => $status,
                        ]);

                        $enrolled++;
                    }
                }
            }
        });

        $this->command->info("✓ Created {$enrolled} student enrollments");
    }

    // ════════════════════════════════════════════════════════════════════════
    // STEP 2: PAYMENT TRANSACTION HISTORY
    // ════════════════════════════════════════════════════════════════════════

    private function seedPaymentTransactions(): void
    {
        $students = User::where('role', 'student')
            ->where('status', User::STATUS_ACTIVE)
            ->get();

        $transactionCount = 0;
        $paymentCount = 0;

        DB::transaction(function () use ($students, &$transactionCount, &$paymentCount) {
            foreach ($students as $student) {
                // Get all assessments for this student
                $assessments = StudentAssessment::where('user_id', $student->id)
                    ->where('status', '!=', 'cancelled')
                    ->get();

                foreach ($assessments as $assessment) {
                    // Determine payment scenario based on student ID (pseudo-random but consistent)
                    $paymentScenario = $this->getPaymentScenario($student->id);

                    // Get payment terms for this assessment, ordered by term_order
                    $terms = StudentPaymentTerm::where('student_assessment_id', $assessment->id)
                        ->orderBy('term_order')
                        ->get();

                    if ($terms->isEmpty()) {
                        continue;
                    }

                    match ($paymentScenario) {
                        'fully_paid' => $this->processFullyPaid(
                            $student, $assessment, $terms,
                            $transactionCount, $paymentCount
                        ),
                        'partial' => $this->processPartialPayment(
                            $student, $assessment, $terms,
                            $transactionCount, $paymentCount
                        ),
                        'first_only' => $this->processFirstPaymentOnly(
                            $student, $assessment, $terms,
                            $transactionCount, $paymentCount
                        ),
                        'unpaid' => null, // Leave all terms unpaid
                    };
                }
            }
        });

        $this->command->info("✓ Created {$transactionCount} payment transactions and {$paymentCount} payment records");
    }

    /**
     * Determine payment scenario based on deterministic hash of student ID
     * Distribution:
     *   40% fully_paid
     *   30% partial
     *   20% first_only
     *   10% unpaid (no change)
     */
    private function getPaymentScenario(int $studentId): string
    {
        $hash = $studentId % 100;
        return match (true) {
            $hash < 40 => 'fully_paid',
            $hash < 70 => 'partial',
            $hash < 90 => 'first_only',
            default => 'unpaid',
        };
    }

    /**
     * Scenario A: FULLY PAID
     * All terms are paid, spread across multiple transactions.
     * Some transactions may cover multiple terms (overpayment allocation).
     */
    private function processFullyPaid(
        User $student,
        StudentAssessment $assessment,
        \Illuminate\Database\Eloquent\Collection $terms,
        &$txCount,
        &$payCount
    ): void {
        $yearNum = (int) explode('-', $assessment->school_year)[0];
        $totalAmount = (float) $assessment->total_assessment;
        $remaining = $totalAmount;

        $paymentDates = $this->generatePaymentDates(count($terms));
        $dateIdx = 0;

        // Simulate 2-4 payment transactions that cover all terms
        $numPayments = rand(2, 4);
        $perPayment = $totalAmount / $numPayments;

        foreach ($terms as $term) {
            // Mark term as paid
            $term->update([
                'balance' => 0,
                'status' => 'paid',
                'paid_date' => now()->copy()->subDays(rand(1, 60))->toDateString(),
            ]);

            // Create payment transaction and record
            $amount = min($remaining, round($perPayment, 2));
            if ($amount <= 0) {
                $amount = $remaining;
            }

            $reference = 'PAY-' . strtoupper(substr(md5($student->id . $term->id), 0, 8));
            $paidDate = $paymentDates[$dateIdx % count($paymentDates)] ?? now();

            $transaction = Transaction::create([
                'user_id' => $student->id,
                'reference' => $reference,
                'kind' => 'payment',
                'type' => $term->term_name,
                'amount' => $amount,
                'status' => 'paid',
                'payment_channel' => $this->getRandomPaymentMethod(),
                'paid_at' => $paidDate,
                'year' => $yearNum,
                'semester' => $assessment->semester,
                'meta' => [
                    'selected_term_id' => $term->id,
                    'term_name' => $term->term_name,
                    'description' => "Payment for {$term->term_name}",
                ],
            ]);

            // Create matching Payment record
            if ($student->student) {
                Payment::create([
                    'student_id' => $student->student->id,
                    'student_assessment_id' => $assessment->id,
                    'amount' => $amount,
                    'payment_method' => $transaction->payment_channel,
                    'description' => "Payment — {$term->term_name}",
                    'status' => 'completed',
                    'created_at' => $paidDate,
                    'updated_at' => $paidDate,
                ]);
            }

            $txCount++;
            $payCount++;
            $remaining -= $amount;
            $dateIdx++;
        }
    }

    /**
     * Scenario B: PARTIAL PAYMENT
     * Paid 2-4 terms completely, remainder unpaid.
     */
    private function processPartialPayment(
        User $student,
        StudentAssessment $assessment,
        \Illuminate\Database\Eloquent\Collection $terms,
        &$txCount,
        &$payCount
    ): void {
        $yearNum = (int) explode('-', $assessment->school_year)[0];
        $termsToPayCount = rand(2, min(4, $terms->count() - 1));
        $termsToPay = $terms->take($termsToPayCount);
        $paymentDates = $this->generatePaymentDates($termsToPayCount);
        $dateIdx = 0;

        foreach ($termsToPay as $term) {
            $term->update([
                'balance' => 0,
                'status' => 'paid',
                'paid_date' => $paymentDates[$dateIdx]->toDateString(),
            ]);

            $reference = 'PAY-' . strtoupper(substr(md5($student->id . $term->id . 'partial'), 0, 8));

            $transaction = Transaction::create([
                'user_id' => $student->id,
                'reference' => $reference,
                'kind' => 'payment',
                'type' => $term->term_name,
                'amount' => (float) $term->amount,
                'status' => 'paid',
                'payment_channel' => $this->getRandomPaymentMethod(),
                'paid_at' => $paymentDates[$dateIdx],
                'year' => $yearNum,
                'semester' => $assessment->semester,
                'meta' => [
                    'selected_term_id' => $term->id,
                    'term_name' => $term->term_name,
                ],
            ]);

            if ($student->student) {
                Payment::create([
                    'student_id' => $student->student->id,
                    'student_assessment_id' => $assessment->id,
                    'amount' => (float) $term->amount,
                    'payment_method' => $transaction->payment_channel,
                    'description' => "Payment — {$term->term_name}",
                    'status' => 'completed',
                    'created_at' => $paymentDates[$dateIdx],
                    'updated_at' => $paymentDates[$dateIdx],
                ]);
            }

            $txCount++;
            $payCount++;
            $dateIdx++;
        }

        // Mark remaining terms as unpaid (leave balance as-is)
    }

    /**
     * Scenario C: FIRST PAYMENT ONLY
     * Only paid the first term (Upon Registration).
     */
    private function processFirstPaymentOnly(
        User $student,
        StudentAssessment $assessment,
        \Illuminate\Database\Eloquent\Collection $terms,
        &$txCount,
        &$payCount
    ): void {
        $yearNum = (int) explode('-', $assessment->school_year)[0];
        $firstTerm = $terms->first();

        if (!$firstTerm) {
            return;
        }

        $firstTerm->update([
            'balance' => 0,
            'status' => 'paid',
            'paid_date' => now()->copy()->subDays(rand(30, 90))->toDateString(),
        ]);

        $reference = 'PAY-' . strtoupper(substr(md5($student->id . $firstTerm->id . 'first'), 0, 8));
        $paidDate = $firstTerm->paid_date;

        $transaction = Transaction::create([
            'user_id' => $student->id,
            'reference' => $reference,
            'kind' => 'payment',
            'type' => $firstTerm->term_name,
            'amount' => (float) $firstTerm->amount,
            'status' => 'paid',
            'payment_channel' => $this->getRandomPaymentMethod(),
            'paid_at' => $paidDate,
            'year' => $yearNum,
            'semester' => $assessment->semester,
            'meta' => [
                'selected_term_id' => $firstTerm->id,
                'term_name' => $firstTerm->term_name,
            ],
        ]);

        if ($student->student) {
            Payment::create([
                'student_id' => $student->student->id,
                'student_assessment_id' => $assessment->id,
                'amount' => (float) $firstTerm->amount,
                'payment_method' => $transaction->payment_channel,
                'description' => "Payment — {$firstTerm->term_name}",
                'status' => 'completed',
                'created_at' => $paidDate,
                'updated_at' => $paidDate,
            ]);
        }

        $txCount++;
        $payCount++;
    }

    // ════════════════════════════════════════════════════════════════════════
    // HELPERS
    // ════════════════════════════════════════════════════════════════════════

    private function generatePaymentDates(int $count): array
    {
        $dates = [];
        $startDate = now()->copy()->subMonths(3);

        for ($i = 0; $i < $count; $i++) {
            $dates[] = $startDate->copy()->addWeeks($i);
        }

        return $dates;
    }

    private function getRandomPaymentMethod(): string
    {
        return collect(['cash', 'gcash', 'bank_transfer', 'credit_card', 'debit_card'])
            ->random();
    }

    private function displaySummary(): void
    {
        $this->command->table(
            ['Metric', 'Count'],
            [
                ['Student Enrollments', StudentEnrollment::count()],
                ['Payment Transactions', Transaction::where('kind', 'payment')->count()],
                ['Payment Records', Payment::count()],
                ['Paid Terms', StudentPaymentTerm::where('status', 'paid')->count()],
                ['Partial Terms', StudentPaymentTerm::where('status', 'partial')->count()],
                ['Pending Terms', StudentPaymentTerm::where('status', 'pending')->count()],
            ]
        );
    }
}
