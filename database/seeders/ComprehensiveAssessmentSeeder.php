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
 * Every student receives one assessment per semester FROM 1st Year 1st Semester
 * UP TO (and including) their current year and semester.
 *
 * ── PROGRESSION RULES ────────────────────────────────────────────────────────
 *   1st Year → 2 assessments  (1Y-S1, 1Y-S2)
 *   2nd Year → 4 assessments  (1Y-S1, 1Y-S2, 2Y-S1, 2Y-S2)
 *   3rd Year → 6 assessments  (…, 3Y-S1, 3Y-S2)
 *   4th Year → 8 assessments  (…, 4Y-S1, 4Y-S2)
 *
 * Applies to ALL students including dropped ones — they get full history.
 *
 * ── FEE FORMULA (from config/fees.php + StudentFeeController) ────────────────
 *   Tuition  = lec_units × ₱364.00
 *   Lab Fee  = (lab_units × ₱1,656.00) + ₱600.00 Entrep Fee  [only if lab_units > 0]
 *   Misc Fee = ₱4,700.00  (fixed, always)
 *   Total    = Tuition + Lab Fee + Misc Fee
 *
 *   NOTE: lab_fee stored in DB already includes the ₱600 entrep fee,
 *         matching how StudentFeeController->store() saves it (line 316).
 *
 * ── UNIT MAP PER YEAR + SEMESTER ──────────────────────────────────────────────
 *   1st Year, 1st Sem → 18 lec, 3 lab
 *   1st Year, 2nd Sem → 18 lec, 3 lab
 *   2nd Year, 1st Sem → 18 lec, 3 lab
 *   2nd Year, 2nd Sem → 18 lec, 3 lab
 *   3rd Year, 1st Sem → 15 lec, 2 lab
 *   3rd Year, 2nd Sem → 18.5 lec (stored as 18, computed as 18.5), 3 lab
 *   4th Year, 1st Sem → 12 lec, 1 lab
 *   4th Year, 2nd Sem → 12 lec, 1 lab
 *
 * ── DISCOUNT RULES ────────────────────────────────────────────────────────────
 *   student1 (Maria Santos) → discount_type = 'full'
 *     → tuition_fee = 0, lab_fee = 0, misc_fee = 0, total = 0
 *   student2 (Ana Garcia)   → discount_type = 'nstp'
 *     → tuition_fee = 546 (fixed NSTP rate), lab_fee & misc_fee as-is
 *   student3–100            → discount_type = 'none' (no discount)
 *
 * ── PAYMENT STATUS RULES ──────────────────────────────────────────────────────
 *   Historical semesters (all before current) → all 5 terms PAID
 *   Current semester (most recent) → terms 1–3 PAID, terms 4–5 PENDING
 *   Exception: graduated students → ALL semesters fully PAID
 */
class ComprehensiveAssessmentSeeder extends Seeder
{
    use GetAdminUserTrait;

    // ── School year timeline ───────────────────────────────────────────────────

    /**
     * Maps (yearLevel, semester) → school year string.
     * Assumes current AY is 2025-2026 and students enrolled in sequence.
     */
    private array $schoolYearMap = [
        '1st Year' => ['1st Sem' => '2023-2024', '2nd Sem' => '2023-2024'],
        '2nd Year' => ['1st Sem' => '2024-2025', '2nd Sem' => '2024-2025'],
        '3rd Year' => ['1st Sem' => '2025-2026', '2nd Sem' => '2025-2026'],
        '4th Year' => ['1st Sem' => '2026-2027', '2nd Sem' => '2026-2027'],
    ];

    /**
     * Ordered list of all semesters a student may have.
     * Each entry: ['year_level', 'semester']
     */
    private array $allSemesters = [
        ['1st Year', '1st Sem'],
        ['1st Year', '2nd Sem'],
        ['2nd Year', '1st Sem'],
        ['2nd Year', '2nd Sem'],
        ['3rd Year', '1st Sem'],
        ['3rd Year', '2nd Sem'],
        ['4th Year', '1st Sem'],
        ['4th Year', '2nd Sem'],
    ];

    /**
     * Unit map: (year_level, semester) → [lec_units, lab_units, lec_units_for_fee]
     *
     * lec_units         = integer stored in DB column (must be whole number)
     * lec_units_for_fee = decimal used in tuition computation
     *                     (differs only for 3rd Year 2nd Sem: 18.5)
     */
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
            '2nd Sem' => ['lec_units' => 18, 'lab_units' => 3, 'lec_units_for_fee' => 18.5], // 18.5 per spec
        ],
        '4th Year' => [
            '1st Sem' => ['lec_units' => 12, 'lab_units' => 1, 'lec_units_for_fee' => 12.0],
            '2nd Sem' => ['lec_units' => 12, 'lab_units' => 1, 'lec_units_for_fee' => 12.0],
        ],
    ];

    /**
     * How many semesters each year level implies (index into $allSemesters).
     * Index is 0-based, inclusive.
     */
    private array $progressionCutoff = [
        '1st Year' => 1, // slots 0–1
        '2nd Year' => 3, // slots 0–3
        '3rd Year' => 5, // slots 0–5
        '4th Year' => 7, // slots 0–7
    ];

    // ── NSTP fixed tuition per DiscountService ─────────────────────────────────
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

        // ── Load fee config ────────────────────────────────────────────────────
        $tuitionRate  = (float) config('fees.tuition_per_lec_unit', 364.00);
        $labRate      = (float) config('fees.lab.per_unit', 1656.00);
        $entrepFee    = (float) config('fees.lab.entrepreneurship_fee', 600.00);
        $miscFee      = (float) config('fees.misc_fee_fixed', 4700.00);
        $termDefs     = config('fees.payment_terms', $this->defaultTerms());

        $this->command->info('💰 Fee config:');
        $this->command->info("   Tuition/unit:   ₱{$tuitionRate}");
        $this->command->info("   Lab/unit:       ₱{$labRate}");
        $this->command->info("   Entrep fee:     ₱{$entrepFee}  (when lab_units > 0)");
        $this->command->info("   Misc (fixed):   ₱{$miscFee}");
        $this->command->newLine();

        // ── Load all students (ALL statuses — dropped included) ────────────────
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
                $cutoff = $this->progressionCutoff[$student->year_level] ?? null;

                if ($cutoff === null) {
                    $this->command->warn("  ⚠ Unknown year_level '{$student->year_level}' for {$student->email} — skipped.");
                    $skipped++;
                    continue;
                }

                // Resolve discount type for this student
                $discountType = $this->resolveDiscountType($student->email);
                $isNstp       = ($discountType === 'nstp');
                $isFull       = ($discountType === 'full');

                // Is this student graduated? If so, all semesters are fully paid.
                $isGraduated = ($student->status === User::STATUS_GRADUATED);

                // Semesters this student should have (0 → $cutoff inclusive)
                $semestersForStudent = array_slice($this->allSemesters, 0, $cutoff + 1);
                $totalSems           = count($semestersForStudent);

                foreach ($semestersForStudent as $semIndex => $semSlot) {
                    [$semYear, $semSemester] = $semSlot;

                    $units      = $this->unitMap[$semYear][$semSemester];
                    $schoolYear = $this->schoolYearMap[$semYear][$semSemester];

                    // ── Compute raw fees ──────────────────────────────────────
                    $rawTuition = round($units['lec_units_for_fee'] * $tuitionRate, 2);
                    $rawLab     = round($units['lab_units'] * $labRate, 2);
                    $rawEntrep  = $units['lab_units'] > 0 ? $entrepFee : 0.0;
                    $rawMisc    = $miscFee;

                    // ── Apply discount (Option A — tuition only) ──────────────
                    // Lab fee and misc fee are NEVER discounted.
                    // They cover actual consumables, equipment, insurance, and
                    // institutional funds that cannot be waived by any scholarship.
                    $labFee     = round($rawLab + $rawEntrep, 2); // always full
                    $miscFeeOut = $rawMisc;                         // always full

                    if ($isFull) {
                        // Full scholarship: tuition waived to ₱0
                        $tuitionFee = 0.00;
                    } elseif ($isNstp) {
                        // NSTP waiver: tuition fixed at CHED minimum (₱546)
                        $tuitionFee = self::NSTP_TUITION_FEE;
                    } else {
                        // No discount: full tuition rate
                        $tuitionFee = $rawTuition;
                    }

                    $grandTotal = round($tuitionFee + $labFee + $miscFeeOut, 2);

                    // ── Determine paid status for terms ───────────────────────
                    // Historical sems (not the last one) → fully paid
                    // Current sem (last in list) → 3/5 paid (unless graduated)
                    $isCurrentSem = ($semIndex === $totalSems - 1);

                    if ($isGraduated) {
                        // Graduated: every semester fully paid
                        $paidOrders = [1, 2, 3, 4, 5];
                    } elseif ($isCurrentSem) {
                        // Current semester: terms 1-3 paid, 4-5 pending
                        $paidOrders = [1, 2, 3];
                    } else {
                        // Historical semester: fully paid
                        $paidOrders = [1, 2, 3, 4, 5];
                    }

                    // ── Semester start anchor for due dates ───────────────────
                    $semStart = $this->semStart($semSemester, $schoolYear);

                    // ── Create assessment ─────────────────────────────────────
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

                    // ── Create payment terms ──────────────────────────────────
                    $this->createPaymentTerms(
                        assessment:  $assessment,
                        grandTotal:  $grandTotal,
                        termDefs:    $termDefs,
                        paidOrders:  $paidOrders,
                        semStart:    $semStart,
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

        $this->command->newLine();
        $this->command->info('💡 Fee formula breakdown (sample — 1st Year 1st Sem, no discount):');
        $sample = 18 * $tuitionRate;
        $sLab   = 3 * $labRate + $entrepFee;
        $this->command->info("   Tuition: 18 × ₱{$tuitionRate}                     = ₱" . number_format($sample, 2));
        $this->command->info("   Lab:     3 × ₱{$labRate} + ₱{$entrepFee} entrep   = ₱" . number_format($sLab, 2));
        $this->command->info("   Misc:    ₱{$miscFee} (fixed)");
        $this->command->info("   Total:                                    = ₱" . number_format($sample + $sLab + $miscFee, 2));
        $this->command->newLine();
        $this->command->info('💡 3rd Year 2nd Sem (per spec — 18.5 lec, 3 lab, no discount):');
        $t3 = 18.5 * $tuitionRate;
        $l3 = 3 * $labRate + $entrepFee;
        $this->command->info("   Tuition: 18.5 × ₱{$tuitionRate}                   = ₱" . number_format($t3, 2));
        $this->command->info("   Lab:     3 × ₱{$labRate} + ₱{$entrepFee}          = ₱" . number_format($l3, 2));
        $this->command->info("   Misc:    ₱{$miscFee}");
        $this->command->info("   Total:                                    = ₱" . number_format($t3 + $l3 + $miscFee, 2));
        $this->command->newLine();
        $this->command->info('💡 Discount examples (1st Year 1st Sem):');
        $fullTotal = 0.00 + $sLab + $miscFee; // tuition=0, lab+misc as-is
        $this->command->info("   full  (Maria) → ₱" . number_format($fullTotal, 2) . "  (tuition=₱0, lab+misc still charged)");
        $nstp = self::NSTP_TUITION_FEE + $sLab + $miscFee;
        $this->command->info("   nstp  (Ana)   → ₱" . number_format($nstp, 2) . "  (tuition=₱546 fixed, lab+misc still charged)");
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Determine discount_type for a student based on their email.
     *
     * student1@ccdi.edu.ph → 'full'  (Maria Santos)
     * student2@ccdi.edu.ph → 'nstp'  (Ana Garcia)
     * everyone else        → 'none'
     */
    private function resolveDiscountType(string $email): string
    {
        return match ($email) {
            'student1@ccdi.edu.ph' => 'full',
            'student2@ccdi.edu.ph' => 'nstp',
            default                => 'none',
        };
    }

    /**
     * Create payment terms for one assessment.
     * The last term absorbs rounding remainder to ensure exact total.
     *
     * @param  int[]  $paidOrders  Term orders (1–5) that should be PAID
     */
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

            // Last term absorbs rounding so sum == grandTotal exactly
            $amount = $isLast
                ? round($grandTotal - $allocated, 2)
                : round(($def['percentage'] / 100) * $grandTotal, 2);

            if (! $isLast) {
                $allocated += $amount;
            }

            // Due dates stagger 4 weeks apart from semester start
            $dueDate = $semStart->copy()->addWeeks($i * 4)->toDateString();

            // Paid timestamp — stagger 1 week apart per term so history looks natural
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

    /**
     * Semester start date anchor.
     *
     * 1st Sem of a school year like "2025-2026" starts August 2025.
     * 2nd Sem starts January 2026.
     */
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