<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Database\Seeders\Traits\GetAdminUserTrait;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\StudentAssessment;
use App\Models\StudentPaymentTerm;
use App\Models\User;

/**
 * ComprehensiveAssessmentSeeder
 *
 * Creates a COMPLETE academic progression of assessments for all 100 students.
 *
 * ── SCHOOL YEAR MAPPING ───────────────────────────────────────────────────────
 * All year levels are enrolled in AY 2025-2026 simultaneously.
 * Historical semesters go backwards from each student's current year level.
 *
 *   1st Year students:
 *     Current:    1Y-1st Sem → 2025-2026  (pending terms 4+5)
 *     ---
 *
 *   2nd Year students:
 *     Historical: 1Y-1st Sem → 2024-2025  (all paid)
 *                 1Y-2nd Sem → 2024-2025  (all paid)
 *     Current:    2Y-1st Sem → 2025-2026  (pending terms 4+5)
 *
 *   3rd Year students:
 *     Historical: 1Y-1st Sem → 2023-2024  (all paid)
 *                 1Y-2nd Sem → 2023-2024  (all paid)
 *                 2Y-1st Sem → 2024-2025  (all paid)
 *                 2Y-2nd Sem → 2024-2025  (all paid)
 *     Current:    3Y-1st Sem → 2025-2026  (pending terms 4+5)
 *
 *   4th Year students:
 *     Historical: 1Y → 2022-2023, 2Y → 2023-2024, 3Y → 2024-2025  (all paid)
 *     Current:    4Y-1st Sem → 2025-2026  (pending terms 4+5)
 *
 * Result: ALL students with pending balances land in school_year = 2025-2026.
 * The Financial Reports page filtered to 2025-2026 shows all active students.
 *
 * ── FEE FORMULA ──────────────────────────────────────────────────────────────
 *   Tuition  = lec_units × ₱364.00
 *   Lab Fee  = (lab_units × ₱1,656.00) + ₱600.00 Entrep (only if lab_units > 0)
 *   Misc Fee = ₱4,700.00 (fixed)
 *   Total    = Tuition + Lab Fee + Misc Fee
 *
 * ── PAYMENT STATUS ────────────────────────────────────────────────────────────
 *   Historical semesters → all 5 terms PAID
 *   Current semester     → terms 1–3 PAID, terms 4–5 PENDING
 *   Graduated students   → ALL semesters fully PAID
 */
class ComprehensiveAssessmentSeeder extends Seeder
{
    use GetAdminUserTrait;

    /**
     * School year map: (year_level, semester) → school_year string.
     *
     * Anchored so that EVERY year level's FIRST semester of their current
     * year maps to 2025-2026. Historical semesters go back one AY per year.
     *
     * 1st Year: current year = 2025-2026 (just enrolled)
     * 2nd Year: current year = 2025-2026 (1st year was 2024-2025)
     * 3rd Year: current year = 2025-2026 (2nd year was 2024-2025, 1st year 2023-2024)
     * 4th Year: current year = 2025-2026 (1st year was 2022-2023)
     */
    private array $schoolYearMap = [
        '1st Year' => ['1st Sem' => '2025-2026', '2nd Sem' => '2025-2026'],
        '2nd Year' => ['1st Sem' => '2024-2025', '2nd Sem' => '2024-2025'],
        '3rd Year' => ['1st Sem' => '2023-2024', '2nd Sem' => '2023-2024'],
        '4th Year' => ['1st Sem' => '2022-2023', '2nd Sem' => '2022-2023'],
    ];

    /**
     * The "current" semester for each year level — the last one in their
     * progression. This must map to 2025-2026 via schoolYearMap.
     *
     * All year levels' current semester is their 1st Sem of their year level,
     * which all map to 2025-2026 as anchored above.
     */
    private array $allSemesters = [
        ['1st Year', '1st Sem'],   // slot 0 — 1st Year current
        ['1st Year', '2nd Sem'],   // slot 1
        ['2nd Year', '1st Sem'],   // slot 2 — 2nd Year current (in 2024-2025 history)
        ['2nd Year', '2nd Sem'],   // slot 3
        ['3rd Year', '1st Sem'],   // slot 4
        ['3rd Year', '2nd Sem'],   // slot 5
        ['4th Year', '1st Sem'],   // slot 6
        ['4th Year', '2nd Sem'],   // slot 7
    ];

    /**
     * Progression cutoff per year level.
     * Determines how many semesters from $allSemesters this student has.
     *
     * BUT: For each year level, their "current" semester — the one that should
     * have pending terms — must be the LAST slot in their progression AND
     * must be in 2025-2026.
     *
     * We rebuild progression per year level explicitly below.
     */

    /**
     * Full semester progression per year level.
     * Format: [year_level_slot, semester, school_year]
     * Last entry in each group = current semester = pending terms.
     */
    private array $progressionMap = [
        '1st Year' => [
            ['1st Year', '1st Sem', '2025-2026'],  // CURRENT — pending
        ],
        '2nd Year' => [
            ['1st Year', '1st Sem', '2024-2025'],  // historical — paid
            ['1st Year', '2nd Sem', '2024-2025'],  // historical — paid
            ['2nd Year', '1st Sem', '2025-2026'],  // CURRENT — pending
        ],
        '3rd Year' => [
            ['1st Year', '1st Sem', '2023-2024'],  // historical — paid
            ['1st Year', '2nd Sem', '2023-2024'],  // historical — paid
            ['2nd Year', '1st Sem', '2024-2025'],  // historical — paid
            ['2nd Year', '2nd Sem', '2024-2025'],  // historical — paid
            ['3rd Year', '1st Sem', '2025-2026'],  // CURRENT — pending
        ],
        '4th Year' => [
            ['1st Year', '1st Sem', '2022-2023'],  // historical — paid
            ['1st Year', '2nd Sem', '2022-2023'],  // historical — paid
            ['2nd Year', '1st Sem', '2023-2024'],  // historical — paid
            ['2nd Year', '2nd Sem', '2023-2024'],  // historical — paid
            ['3rd Year', '1st Sem', '2024-2025'],  // historical — paid
            ['3rd Year', '2nd Sem', '2024-2025'],  // historical — paid
            ['4th Year', '1st Sem', '2025-2026'],  // CURRENT — pending
        ],
    ];

    private array $unitMap = [
        '1st Year' => [
            '1st Sem' => ['lec_units' => 18, 'lab_units' => 3, 'lec_units_for_fee' => 18.0],
            '2nd Sem' => ['lec_units' => 18, 'lab_units' => 3, 'lec_units_for_fee' => 18.0],
        ],
        '2nd Year' => [
            '1st Sem' => ['lec_units' => 18, 'lab_units' => 3, 'lec_units_for_fee' => 18.0],
            '2nd Sem' => ['lec_units' => 18, 'lab_units' => 3, 'lec_units_for_fee' => 18.0],
        ],
        '3rd Year' => [
            '1st Sem' => ['lec_units' => 15, 'lab_units' => 2, 'lec_units_for_fee' => 15.0],
            '2nd Sem' => ['lec_units' => 18, 'lab_units' => 3, 'lec_units_for_fee' => 18.5],
        ],
        '4th Year' => [
            '1st Sem' => ['lec_units' => 12, 'lab_units' => 1, 'lec_units_for_fee' => 12.0],
            '2nd Sem' => ['lec_units' => 12, 'lab_units' => 1, 'lec_units_for_fee' => 12.0],
        ],
    ];

    private const NSTP_TUITION_FEE = 546.00;

    // =========================================================================

    public function run(): void
    {
        $this->command->info('🗑  Clearing existing assessments and payment terms…');

        $studentIds = User::where('role', 'student')->pluck('id');

        StudentPaymentTerm::whereIn(
            'student_assessment_id',
            StudentAssessment::whereIn('user_id', $studentIds)->pluck('id')
        )->delete();

        StudentAssessment::whereIn('user_id', $studentIds)->delete();

        $this->command->info('✓ Cleared.');
        $this->command->newLine();

        $tuitionRate = (float) config('fees.tuition_per_lec_unit', 364.00);
        $labRate     = (float) config('fees.lab.per_unit', 1656.00);
        $entrepFee   = (float) config('fees.lab.entrepreneurship_fee', 600.00);
        $miscFee     = (float) config('fees.misc_fee_fixed', 4700.00);
        $termDefs    = config('fees.payment_terms', $this->defaultTerms());

        $this->command->info('💰 Fee config:');
        $this->command->info("   Tuition/unit:   ₱{$tuitionRate}");
        $this->command->info("   Lab/unit:       ₱{$labRate}");
        $this->command->info("   Entrep fee:     ₱{$entrepFee}  (when lab_units > 0)");
        $this->command->info("   Misc (fixed):   ₱{$miscFee}");
        $this->command->newLine();

        $students = User::where('role', 'student')
            ->whereNotNull('year_level')
            ->whereNotNull('course')
            ->orderBy('id')
            ->get();

        $this->command->info("📋 Creating assessments for {$students->count()} students…");
        $this->command->newLine();

        $created = 0;
        $skipped = 0;

        DB::transaction(function () use (
            $students, $tuitionRate, $labRate, $entrepFee, $miscFee, $termDefs, &$created, &$skipped
        ) {
            foreach ($students as $student) {
                $progression = $this->progressionMap[$student->year_level] ?? null;

                if ($progression === null) {
                    $this->command->warn("  ⚠ Unknown year_level '{$student->year_level}' for {$student->email} — skipped.");
                    $skipped++;
                    continue;
                }

                $discountType = $this->resolveDiscountType($student->email);
                $isNstp       = ($discountType === 'nstp');
                $isFull       = ($discountType === 'full');
                $isGraduated  = ($student->status === User::STATUS_GRADUATED);
                $totalSems    = count($progression);

                foreach ($progression as $semIndex => [$semYear, $semSemester, $schoolYear]) {
                    $units = $this->unitMap[$semYear][$semSemester];

                    $rawTuition = round($units['lec_units_for_fee'] * $tuitionRate, 2);
                    $rawLab     = round($units['lab_units'] * $labRate, 2);
                    $rawEntrep  = $units['lab_units'] > 0 ? $entrepFee : 0.0;

                    $labFee     = round($rawLab + $rawEntrep, 2);
                    $miscFeeOut = $miscFee;

                    if ($isFull) {
                        $tuitionFee = 0.00;
                    } elseif ($isNstp) {
                        $tuitionFee = self::NSTP_TUITION_FEE;
                    } else {
                        $tuitionFee = $rawTuition;
                    }

                    $grandTotal = round($tuitionFee + $labFee + $miscFeeOut, 2);

                    $isCurrentSem = ($semIndex === $totalSems - 1);

                    if ($isGraduated) {
                        $paidOrders = [1, 2, 3, 4, 5];
                    } elseif ($isCurrentSem) {
                        $paidOrders = [1, 2, 3];
                    } else {
                        $paidOrders = [1, 2, 3, 4, 5];
                    }

                    $semStart = $this->semStart($semSemester, $schoolYear);

                    $assessment = StudentAssessment::create([
                        'user_id'           => $student->id,
                        'course'            => $student->course,
                        'assessment_number' => StudentAssessment::generateAssessmentNumber(),
                        'year_level'        => $semYear,
                        'semester'          => $semSemester,
                        'school_year'       => $schoolYear,
                        'lec_units'         => $units['lec_units'],
                        'lab_units'         => $units['lab_units'],
                        'discount_type'     => $discountType,
                        'is_taking_nstp'    => $isNstp,
                        'tuition_fee'       => $tuitionFee,
                        'lab_fee'           => $labFee,
                        'misc_fee'          => $miscFeeOut,
                        'total_assessment'  => $grandTotal,
                        'status'            => 'active',
                    ]);

                    $this->createPaymentTerms(
                        assessment: $assessment,
                        grandTotal: $grandTotal,
                        termDefs:   $termDefs,
                        paidOrders: $paidOrders,
                        semStart:   $semStart,
                    );

                    $created++;
                }

                $this->command->line(sprintf(
                    '  ✓ %-35s %-10s  %d assessments  [%s]',
                    $student->last_name . ', ' . $student->first_name,
                    $student->year_level,
                    $totalSems,
                    $discountType
                ));
            }
        });

        $this->command->newLine();
        $this->command->info("✅ Created {$created} assessments. Skipped {$skipped} students.");
        $this->command->newLine();

        $this->command->table(
            ['Metric', 'Count'],
            [
                ['Total Assessments',   StudentAssessment::count()],
                ['Total Payment Terms', StudentPaymentTerm::count()],
                ['Paid Terms',          StudentPaymentTerm::where('status', 'paid')->count()],
                ['Pending Terms',       StudentPaymentTerm::where('status', 'pending')->count()],
            ]
        );

        // Verify the critical invariant: all pending balances are in 2025-2026
        $this->command->newLine();
        $this->command->info('📊 Assessments per school_year (pending balance distribution):');
        $rows = StudentAssessment::selectRaw('school_year, semester, COUNT(*) as cnt')
            ->groupBy('school_year', 'semester')
            ->orderBy('school_year')
            ->orderBy('semester')
            ->get();

        foreach ($rows as $row) {
            $this->command->info("   {$row->school_year} | {$row->semester}: {$row->cnt} assessments");
        }

        $pendingIn2025 = StudentPaymentTerm::where('status', 'pending')
            ->whereHas('assessment', fn ($q) => $q->where('school_year', '2025-2026'))
            ->count();

        $this->command->newLine();
        $this->command->info("✅ Pending payment terms in 2025-2026: {$pendingIn2025}");
        $this->command->newLine();
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function resolveDiscountType(string $email): string
    {
        return match ($email) {
            'student1@ccdi.edu.ph' => 'full',
            'student2@ccdi.edu.ph' => 'nstp',
            default                => 'none',
        };
    }

    private function createPaymentTerms(
        StudentAssessment $assessment,
        float             $grandTotal,
        array             $termDefs,
        array             $paidOrders,
        Carbon            $semStart,
    ): void {
        $allocated = 0.00;
        $lastIndex = count($termDefs) - 1;

        foreach ($termDefs as $i => $def) {
            $isLast = ($i === $lastIndex);
            $order  = (int) $def['term_order'];
            $isPaid = in_array($order, $paidOrders, true);

            $amount = $isLast
                ? round($grandTotal - $allocated, 2)
                : round(($def['percentage'] / 100) * $grandTotal, 2);

            if (! $isLast) {
                $allocated += $amount;
            }

            $dueDate = $semStart->copy()->addWeeks($i * 4)->toDateString();

            $paidAt = $isPaid
                ? $semStart->copy()->addWeeks($i * 4)->addDays(rand(1, 5))->toDateString()
                : null;

            StudentPaymentTerm::create([
                'student_assessment_id'  => $assessment->id,
                'term_name'              => $def['term_name'],
                'term_order'             => $order,
                'percentage'             => $def['percentage'],
                'amount'                 => $amount,
                'balance'                => $isPaid ? 0.00 : $amount,
                'due_date'               => $dueDate,
                'status'                 => $isPaid
                                              ? StudentPaymentTerm::STATUS_PAID
                                              : StudentPaymentTerm::STATUS_PENDING,
                'paid_date'              => $paidAt,
                'carryover_from_term_id' => null,
                'carryover_amount'       => 0.00,
                'remarks'                => null,
            ]);
        }
    }

    private function semStart(string $semester, string $schoolYear): Carbon
    {
        [$startYear, $endYear] = array_map('intval', explode('-', $schoolYear));

        return $semester === '1st Sem'
            ? Carbon::create($startYear, 8, 1)
            : Carbon::create($endYear, 1, 5);
    }

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