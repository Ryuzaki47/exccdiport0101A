<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Database\Seeders\Traits\GetAdminUserTrait;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\Fee;
use App\Models\StudentAssessment;
use App\Models\StudentPaymentTerm;
use App\Models\Transaction;
use App\Models\User;

/**
 * ComprehensiveAssessmentSeeder
 *
 * Creates one assessment per active student per semester (1st Sem + 2nd Sem).
 *
 * Fee formula (from config/fees.php — single source of truth):
 *   Tuition  = lec_units  × config('fees.tuition_per_lec_unit')   [₱364.00]
 *   Lab Fee  = lab_subjects × config('fees.lab_fee_per_unit')      [₱1,656.00]
 *   Misc Fee = config('fees.misc_fee_fixed')                       [₱4,700.00]
 *   ─────────────────────────────────────────────────────────────────
 *   Total    = tuition + lab_fee + misc_fee
 *
 * Unit map per year level (semester-agnostic for 2nd Sem seeding):
 *   1st Year → 18 lec_units, 3 lab_subjects, 3 lab_units (informational)
 *   2nd Year → 18 lec_units, 3 lab_subjects, 3 lab_units
 *   3rd Year → 15 lec_units, 2 lab_subjects, 2 lab_units
 *   4th Year → 12 lec_units, 1 lab_subject,  1 lab_unit
 *
 * NOTE: No charge Transactions are created here.
 * Charges are only generated when accounting staff creates an assessment
 * via StudentFeeController::store(). Seeder = structural/test data only.
 *
 * Payment terms split (from config('fees.payment_terms')):
 *   Upon Registration 25% | Prelim 25% | Midterm 25%
 *   Semi-Final 12.5%      | Final  12.5%
 */
class ComprehensiveAssessmentSeeder extends Seeder
{
    use GetAdminUserTrait;

    private string $schoolYear = '2025-2026';

    /**
     * Unit configuration per year level.
     * lec_units → drives tuition fee formula (₱364 per unit)
     * lab_units → drives lab fee formula (₱1,656 per unit)
     */
    private array $unitMap = [
        '1st Year' => ['lec_units' => 18, 'lab_units' => 3],
        '2nd Year' => ['lec_units' => 18, 'lab_units' => 3],
        '3rd Year' => ['lec_units' => 15, 'lab_units' => 2],
        '4th Year' => ['lec_units' => 12, 'lab_units' => 1],
    ];

    // ─────────────────────────────────────────────────────────────────────────

    public function run(): void
    {
        $adminId = $this->getOrFindAdminUserId();

        $this->command->info('🗑️  Clearing existing assessments and payment terms…');
        $studentIds = User::where('role', 'student')->pluck('id');

        StudentPaymentTerm::whereIn(
            'student_assessment_id',
            StudentAssessment::whereIn('user_id', $studentIds)->pluck('id')
        )->delete();

        StudentAssessment::whereIn('user_id', $studentIds)->delete();
        $this->command->info('✓ Cleared.');
        $this->command->newLine();

        // Pull fee rates from config — single source of truth
        $tuitionRate  = (float) config('fees.tuition_per_lec_unit', 364.00);
        $labRate      = (float) config('fees.lab_fee_per_unit', 1656.00);
        $miscFee      = (float) config('fees.misc_fee_fixed', 4700.00);
        $termDefs     = config('fees.payment_terms', $this->defaultTerms());

        $this->command->info("💰 Fee rates loaded from config:");
        $this->command->info("   Tuition per lec unit: ₱" . number_format($tuitionRate, 2));
        $this->command->info("   Lab fee per subject:  ₱" . number_format($labRate, 2));
        $this->command->info("   Misc fee (fixed):     ₱" . number_format($miscFee, 2));
        $this->command->newLine();

        $students  = User::where('role', 'student')
            ->where('status', User::STATUS_ACTIVE)
            ->whereNotNull('year_level')
            ->get();

        $semesters = ['1st Sem', '2nd Sem'];

        $this->command->info("📋 Creating assessments for {$students->count()} active students…");
        $created = 0;
        $skipped = 0;

        DB::transaction(function () use ($students, $semesters, $tuitionRate, $labRate, $miscFee, $termDefs, &$created, &$skipped) {
            foreach ($students as $student) {
                if (empty($student->year_level)) {
                    $skipped++;
                    continue;
                }

                $units = $this->unitMap[$student->year_level] ?? null;
                if ($units === null) {
                    $this->command->warn("  ⚠ Unknown year level '{$student->year_level}' for student {$student->email} — skipped.");
                    $skipped++;
                    continue;
                }

                // Calculate total using the real formula
                $tuition      = round($units['lec_units'] * $tuitionRate, 2);
                $labFee       = round($units['lab_units'] * $labRate, 2);
                $grandTotal   = round($tuition + $labFee + $miscFee, 2);

                foreach ($semesters as $semester) {
                    $this->createAssessment(
                        student:    $student,
                        semester:   $semester,
                        units:      $units,
                        grandTotal: $grandTotal,
                        termDefs:   $termDefs,
                    );
                    $created++;
                }
            }
        });

        $this->command->info("✓ Created {$created} assessments. Skipped {$skipped}.");
        $this->command->newLine();
        $this->command->info('✅ ComprehensiveAssessmentSeeder complete.');
        $this->command->table(
            ['Item', 'Count'],
            [
                ['Assessments',   StudentAssessment::count()],
                ['Payment Terms', StudentPaymentTerm::count()],
            ]
        );
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function createAssessment(
        User   $student,
        string $semester,
        array  $units,
        float  $grandTotal,
        array  $termDefs,
    ): void {
        $assessment = StudentAssessment::create([
            'user_id'           => $student->id,
            'assessment_number' => StudentAssessment::generateAssessmentNumber(),
            'year_level'        => $student->year_level,
            'semester'          => $semester,
            'school_year'       => $this->schoolYear,
            'lec_units'         => $units['lec_units'],
            'lab_units'         => $units['lab_units'],
            'total_assessment'  => $grandTotal,
            'status'            => 'active',
        ]);

        // Due date anchors per semester
        $semStart = $semester === '1st Sem'
            ? Carbon::create(2025, 8, 1)
            : Carbon::create(2026, 1, 5);

        $allocated = 0.00;
        $lastIndex = count($termDefs) - 1;

        foreach ($termDefs as $i => $term) {
            $isLast = ($i === $lastIndex);

            // Last term absorbs any rounding remainder so sum equals grand total exactly
            $amount = $isLast
                ? round($grandTotal - $allocated, 2)
                : round(($term['percentage'] / 100) * $grandTotal, 2);

            if (! $isLast) {
                $allocated += $amount;
            }

            $dueDate = $semStart->copy()->addWeeks($i * 4)->toDateString();

            StudentPaymentTerm::create([
                'student_assessment_id'  => $assessment->id,
                'term_name'              => $term['term_name'],
                'term_order'             => $term['term_order'],
                'percentage'             => $term['percentage'],
                'amount'                 => $amount,
                'balance'                => $amount, // All start unpaid
                'due_date'               => $dueDate,
                'status'                 => StudentPaymentTerm::STATUS_PENDING,
                'remarks'                => null,
                'paid_date'              => null,
                'carryover_from_term_id' => null,
                'carryover_amount'       => 0.00,
            ]);
        }
    }

    /**
     * Fallback term definitions if config/fees.php payment_terms is missing.
     * Kept in sync with the constant in StudentPaymentTerm.
     */
    private function defaultTerms(): array
    {
        return [
            ['term_name' => 'Upon Registration', 'term_order' => 1, 'percentage' => 25.00],
            ['term_name' => 'Prelim',            'term_order' => 2, 'percentage' => 25.00],
            ['term_name' => 'Midterm',           'term_order' => 3, 'percentage' => 25.00],
            ['term_name' => 'Semi-Final',        'term_order' => 4, 'percentage' => 12.50],
            ['term_name' => 'Final',             'term_order' => 5, 'percentage' => 12.50],
        ];
    }
}