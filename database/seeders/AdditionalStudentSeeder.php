<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Database\Seeders\Traits\GetAdminUserTrait;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\Account;
use App\Models\Student;
use App\Models\StudentAssessment;
use App\Models\StudentPaymentTerm;
use App\Models\Transaction;
use App\Models\User;
use App\Enums\UserRoleEnum;

/**
 * AdditionalStudentSeeder
 *
 * Creates 4 named test students with realistic academic histories.
 * Each assessment uses the REAL fee formula from config/fees.php:
 *
 *   Total = (lec_units × ₱364) + (lab_subjects × ₱1,656) + ₱4,700
 *
 * ┌────────────────────────────────────────────────────────────────────┐
 * │ Student               │ Scenario                                   │
 * ├────────────────────────────────────────────────────────────────────┤
 * │ maria.santos          │ 1st Yr 2nd Sem: 4/5 terms paid.            │
 * │                       │ History: 1st Yr 1st Sem fully paid.        │
 * │                       │ Pay "Final" → triggers progression notice. │
 * ├────────────────────────────────────────────────────────────────────┤
 * │ juan.dela.cruz        │ 2nd Yr 1st Sem: 3/5 terms paid.            │
 * │                       │ History: 1st Yr 1st+2nd Sem fully paid.   │
 * │                       │ Pay Semi-Final+Final → triggers notice.    │
 * ├────────────────────────────────────────────────────────────────────┤
 * │ ana.garcia            │ 1st Yr 1st Sem: 0/5 terms paid.            │
 * │                       │ Freshly enrolled — baseline test.          │
 * ├────────────────────────────────────────────────────────────────────┤
 * │ transaction.history   │ 3rd Yr student with complete paid history  │
 * │                       │ 1st–3rd Yr 1st Sem all PAID.               │
 * │                       │ 3rd Yr 2nd Sem UNPAID (current).           │
 * └────────────────────────────────────────────────────────────────────┘
 *
 * Transaction records:
 *   - One 'payment' Transaction per PAID term (kind=payment, status=paid)
 *   - NO charge Transactions (charges are created only via admin UI)
 *
 * USAGE:
 *   php artisan db:seed --class=AdditionalStudentSeeder
 */
class AdditionalStudentSeeder extends Seeder
{
    use GetAdminUserTrait;

    // ── Constants ──────────────────────────────────────────────────────────────

    private const SCHOOL_YEAR      = '2025-2026';
    private const PREV_SCHOOL_YEAR = '2024-2025';
    private const OLDER_SCHOOL_YEAR = '2023-2024';

    private const PAYMENT_CHANNELS = ['GCash', 'PayMaya', 'Cash', 'BDO Online', 'Maya'];

    /**
     * Unit map per year level.
     * lec_units → tuition formula: lec_units × ₱364
     * lab_units → lab fee formula: lab_units × ₱1,656
     */
    /**
     * Unit map per year level AND semester.
     * lec_units_for_fee is the decimal value used in tuition computation
     * (differs from lec_units only for 3rd Year 2nd Sem: 18.5 per spec).
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
            '2nd Sem' => ['lec_units' => 18, 'lab_units' => 3, 'lec_units_for_fee' => 18.5], // per spec
        ],
        '4th Year' => [
            '1st Sem' => ['lec_units' => 12, 'lab_units' => 1, 'lec_units_for_fee' => 12.0],
            '2nd Sem' => ['lec_units' => 12, 'lab_units' => 1, 'lec_units_for_fee' => 12.0],
        ],
    ];

    private int $accountNumberCounter = 0;

    // ─────────────────────────────────────────────────────────────────────────

    public function run(): void
    {
        DB::transaction(function () {
            $this->cmd()->info('');
            $this->cmd()->info(str_repeat('═', 68));
            $this->cmd()->info('  ADDITIONAL TEST STUDENTS — PROGRESSION NOTIFICATION DEMO');
            $this->cmd()->info(str_repeat('═', 68));

            $this->cmd()->info('');
            $this->cmd()->info('1/4  maria.santos@test.com');
            $this->seedMaria();

            $this->cmd()->info('');
            $this->cmd()->info('2/4  juan.dela.cruz@test.com');
            $this->seedJuan();

            $this->cmd()->info('');
            $this->cmd()->info('3/4  ana.garcia@test.com');
            $this->seedAna();

            $this->cmd()->info('');
            $this->cmd()->info('4/4  transaction.history@ccdi.edu.ph');
            $this->seedTransactionHistoryStudent();

            $this->printSummary();
        });
    }

    // =========================================================================
    // STUDENT SCENARIOS
    // =========================================================================

    /**
     * MARIA SANTOS — 1st Year, 2nd Semester
     *
     * History : 1st Year 1st Sem — FULLY PAID
     * Current : 1st Year 2nd Sem — 4/5 terms paid, "Final" unpaid
     *
     * TEST: Pay "Final" → all 5 terms paid → admin progression notification
     */
    private function seedMaria(): void
    {
        $user = $this->upsertUser(
            email:      'maria.santos@test.com',
            firstName:  'Maria',
            lastName:   'Santos',
            accountId:  '2024-0002',
            yearLevel:  '1st Year',
            course:     'BS Information Technology',
        );
        $this->upsertStudent($user, '1st Year');

        // ── Historical: 1st Year 1st Sem — FULLY PAID ─────────────────────────
        $this->buildAssessment(
            user:       $user,
            yearLevel:  '1st Year',
            semester:   '1st Sem',
            schoolYear: self::PREV_SCHOOL_YEAR,
            paidOrders: [1, 2, 3, 4, 5],
            paidAtBase: Carbon::create(2024, 8, 20),
        );
        $this->cmd()->info('   ✓ 1st Year 1st Sem — FULLY PAID (historical)');

        // ── Current: 1st Year 2nd Sem — "Final" still unpaid ──────────────────
        $assessment = $this->buildAssessment(
            user:       $user,
            yearLevel:  '1st Year',
            semester:   '2nd Sem',
            schoolYear: self::SCHOOL_YEAR,
            paidOrders: [1, 2, 3, 4],
            paidAtBase: Carbon::create(2025, 1, 10),
        );

        $remaining = $assessment->paymentTerms()->where('status', '!=', 'paid')->sum('balance');
        $this->cmd()->info('   ✓ 1st Year 2nd Sem — 4/5 paid. "Final" unpaid: ₱' . number_format($remaining, 2));
        $this->cmd()->info('   → Pay "Final" to trigger admin progression notification');

        $this->recalculateAccount($user);
    }

    /**
     * JUAN DELA CRUZ — 2nd Year, 1st Semester
     *
     * History : 1st Year 1st + 2nd Sem — FULLY PAID
     * Current : 2nd Year 1st Sem — 3/5 paid, Semi-Final + Final unpaid
     *
     * TEST: Pay "Semi-Final" + "Final" → triggers admin progression notification
     */
    private function seedJuan(): void
    {
        $user = $this->upsertUser(
            email:      'juan.dela.cruz@test.com',
            firstName:  'Juan',
            lastName:   'Dela Cruz',
            accountId:  '2024-0003',
            yearLevel:  '2nd Year',
            course:     'BS Computer Science',
        );
        $this->upsertStudent($user, '2nd Year');

        // ── Historical: 1st Year 1st Sem ──────────────────────────────────────
        $this->buildAssessment(
            user:       $user,
            yearLevel:  '1st Year',
            semester:   '1st Sem',
            schoolYear: self::OLDER_SCHOOL_YEAR,
            paidOrders: [1, 2, 3, 4, 5],
            paidAtBase: Carbon::create(2023, 8, 15),
        );
        $this->cmd()->info('   ✓ 1st Year 1st Sem — FULLY PAID (historical)');

        // ── Historical: 1st Year 2nd Sem ──────────────────────────────────────
        $this->buildAssessment(
            user:       $user,
            yearLevel:  '1st Year',
            semester:   '2nd Sem',
            schoolYear: self::OLDER_SCHOOL_YEAR,
            paidOrders: [1, 2, 3, 4, 5],
            paidAtBase: Carbon::create(2024, 1, 10),
        );
        $this->cmd()->info('   ✓ 1st Year 2nd Sem — FULLY PAID (historical)');

        // ── Current: 2nd Year 1st Sem — Semi-Final + Final unpaid ─────────────
        $assessment = $this->buildAssessment(
            user:       $user,
            yearLevel:  '2nd Year',
            semester:   '1st Sem',
            schoolYear: self::SCHOOL_YEAR,
            paidOrders: [1, 2, 3],
            paidAtBase: Carbon::create(2025, 8, 10),
        );

        $remaining = $assessment->paymentTerms()->where('status', '!=', 'paid')->sum('balance');
        $this->cmd()->info('   ✓ 2nd Year 1st Sem — 3/5 paid. Semi-Final + Final unpaid: ₱' . number_format($remaining, 2));
        $this->cmd()->info('   → Pay both to trigger admin progression notification');

        $this->recalculateAccount($user);
    }

    /**
     * ANA GARCIA — 1st Year, 1st Semester (brand new student)
     *
     * No history. All 5 terms unpaid. No due dates set.
     * Baseline / edge-case test: freshly enrolled student.
     */
    private function seedAna(): void
    {
        $user = $this->upsertUser(
            email:      'ana.garcia@test.com',
            firstName:  'Ana',
            lastName:   'Garcia',
            accountId:  '2024-0004',
            yearLevel:  '1st Year',
            course:     'BS Information Systems',
        );
        $this->upsertStudent($user, '1st Year');

        // ── Current: 1st Year 1st Sem — all unpaid, no due dates ──────────────
        $assessment = $this->buildAssessment(
            user:        $user,
            yearLevel:   '1st Year',
            semester:    '1st Sem',
            schoolYear:  self::SCHOOL_YEAR,
            paidOrders:  [],
            setDueDates: false,
        );

        $this->cmd()->info('   ✓ 1st Year 1st Sem — FULLY UNPAID (no due dates)');
        $this->cmd()->info('   → Total: ₱' . number_format($assessment->total_assessment, 2));
        $this->cmd()->info('   → Pay all 5 terms to trigger admin progression notification');

        $this->recalculateAccount($user);
    }

    /**
     * TRANSACTION HISTORY STUDENT — 3rd Year
     *
     * Complete paid academic history:
     *   1st Yr 1st Sem (2023-2024) → PAID
     *   1st Yr 2nd Sem (2023-2024) → PAID
     *   2nd Yr 1st Sem (2024-2025) → PAID
     *   2nd Yr 2nd Sem (2024-2025) → PAID
     *   3rd Yr 1st Sem (2025-2026) → PAID
     *   3rd Yr 2nd Sem (2025-2026) → UNPAID (current — ready for manual payment)
     *
     * Displays as 6 expandable accordion sections in the Transactions page,
     * ordered newest-first.
     */
    private function seedTransactionHistoryStudent(): void
    {
        $user = $this->upsertUser(
            email:      'transaction.history@ccdi.edu.ph',
            firstName:  'Transaction',
            lastName:   'History',
            accountId:  '2023-0201',
            yearLevel:  '3rd Year',
            course:     'BS Computer Science',
        );
        $this->upsertStudent($user, '3rd Year');

        $paidSemesters = [
            ['yearLevel' => '1st Year', 'semester' => '1st Sem', 'schoolYear' => '2023-2024', 'paidAtBase' => Carbon::create(2023, 8, 20)],
            ['yearLevel' => '1st Year', 'semester' => '2nd Sem', 'schoolYear' => '2023-2024', 'paidAtBase' => Carbon::create(2024, 1, 10)],
            ['yearLevel' => '2nd Year', 'semester' => '1st Sem', 'schoolYear' => '2024-2025', 'paidAtBase' => Carbon::create(2024, 8, 15)],
            ['yearLevel' => '2nd Year', 'semester' => '2nd Sem', 'schoolYear' => '2024-2025', 'paidAtBase' => Carbon::create(2025, 1, 8)],
            ['yearLevel' => '3rd Year', 'semester' => '1st Sem', 'schoolYear' => '2025-2026', 'paidAtBase' => Carbon::create(2025, 8, 12)],
        ];

        foreach ($paidSemesters as $sem) {
            $assessment = $this->buildAssessment(
                user:       $user,
                yearLevel:  $sem['yearLevel'],
                semester:   $sem['semester'],
                schoolYear: $sem['schoolYear'],
                paidOrders: [1, 2, 3, 4, 5],
                paidAtBase: $sem['paidAtBase'],
            );
            $this->cmd()->info("   ✓ {$sem['yearLevel']} {$sem['semester']} ({$sem['schoolYear']}) — FULLY PAID");
        }

        // Current: 3rd Year 2nd Sem — UNPAID
        $current = $this->buildAssessment(
            user:       $user,
            yearLevel:  '3rd Year',
            semester:   '2nd Sem',
            schoolYear: self::SCHOOL_YEAR,
            paidOrders: [],
            paidAtBase: null,
        );

        $this->cmd()->info('   ⏳ 3rd Year 2nd Sem (2025-2026) — UNPAID (current)');
        $this->cmd()->info('   → Total: ₱' . number_format($current->total_assessment, 2));

        $this->recalculateAccount($user);
    }

    // =========================================================================
    // CORE ASSESSMENT BUILDER
    // =========================================================================

    /**
     * Create one assessment with payment terms and payment transactions.
     *
     * Idempotent: if the assessment already exists (same user/year/semester/schoolYear),
     * it is returned as-is without modification.
     *
     * @param  int[]       $paidOrders   Term orders 1–5 that should be marked PAID
     * @param  Carbon|null $paidAtBase   Base date for payment timestamps (staggered by term)
     * @param  bool        $setDueDates  Set due dates on unpaid terms (false = Ana edge case)
     */
    private function buildAssessment(
        User    $user,
        string  $yearLevel,
        string  $semester,
        string  $schoolYear,
        array   $paidOrders  = [],
        ?Carbon $paidAtBase  = null,
        bool    $setDueDates = true,
    ): StudentAssessment {
        // ── Idempotency guard ──────────────────────────────────────────────────
        $existing = StudentAssessment::where('user_id', $user->id)
            ->where('year_level', $yearLevel)
            ->where('semester', $semester)
            ->where('school_year', $schoolYear)
            ->first();

        if ($existing) {
            return $existing;
        }

        // ── Resolve units and compute total ───────────────────────────────────
        // Unit map is now keyed by [yearLevel][semester] for per-semester accuracy.
        $units       = $this->unitMap[$yearLevel][$semester]
                    ?? ['lec_units' => 18, 'lab_units' => 3, 'lec_units_for_fee' => 18.0];
        $tuitionRate = (float) config('fees.tuition_per_lec_unit', 364.00);
        $labRate     = (float) config('fees.lab.per_unit', 1656.00);
        $entrepFee   = (float) config('fees.lab.entrepreneurship_fee', 600.00);
        $miscFee     = (float) config('fees.misc_fee_fixed', 4700.00);

        $tuition    = round($units['lec_units_for_fee'] * $tuitionRate, 2);
        $entrep     = $units['lab_units'] > 0 ? $entrepFee : 0.00;
        $labFee     = round(($units['lab_units'] * $labRate) + $entrep, 2);
        $grandTotal = round($tuition + $labFee + $miscFee, 2);

        $yearNum = (int) explode('-', $schoolYear)[0];
        $allPaid = count($paidOrders) === 5;

        // ── Assessment record ──────────────────────────────────────────────────
        $assessment = StudentAssessment::create([
            'user_id'           => $user->id,
            'course'            => $user->course,
            'assessment_number' => StudentAssessment::generateAssessmentNumber(),
            'year_level'        => $yearLevel,
            'semester'          => $semester,
            'school_year'       => $schoolYear,
            'lec_units'         => $units['lec_units'],
            'lab_units'         => $units['lab_units'],
            'discount_type'     => 'none',
            'is_taking_nstp'    => false,
            'tuition_fee'       => $tuition,
            'lab_fee'           => $labFee,
            'misc_fee'          => $miscFee,
            'total_assessment'  => $grandTotal,
            'status'            => 'active',
        ]);

        // ── Term due-date anchor ───────────────────────────────────────────────
        $semStart = $this->semStart($semester, $schoolYear);

        // ── Payment term definitions from config ───────────────────────────────
        $termDefs  = config('fees.payment_terms', $this->defaultTerms());
        $lastIndex = count($termDefs) - 1;
        $allocated = 0.00;
        $createdTerms = [];

        foreach ($termDefs as $i => $def) {
            $isLast  = ($i === $lastIndex);
            $order   = $def['term_order'];
            $isPaid  = in_array($order, $paidOrders, true);

            // Last term absorbs any rounding remainder for exact total
            $amount = $isLast
                ? round($grandTotal - $allocated, 2)
                : round(($def['percentage'] / 100) * $grandTotal, 2);

            if (! $isLast) {
                $allocated += $amount;
            }

            // Due date: always set on paid terms; on unpaid only when $setDueDates = true
            $dueDate = ($isPaid || $setDueDates)
                ? $semStart->copy()->addWeeks($i * 4)->toDateString()
                : null;

            // Paid timestamp: stagger by term order so history looks realistic
            $paidAt = ($isPaid && $paidAtBase)
                ? $paidAtBase->copy()->addDays(($order - 1) * 7)
                : null;

            $term = StudentPaymentTerm::create([
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

            $createdTerms[] = ['term' => $term, 'isPaid' => $isPaid, 'paidAt' => $paidAt];
        }

        // ── Payment transactions for paid terms ────────────────────────────────
        // NOTE: Charge transactions are deliberately NOT created here.
        // They are created only when accounting staff creates an assessment
        // via StudentFeeController::store(). This is intentional architecture.
        foreach ($createdTerms as $entry) {
            if (! $entry['isPaid']) {
                continue;
            }

            $term   = $entry['term'];
            $paidAt = $entry['paidAt'];

            Transaction::create([
                'user_id'         => $user->id,
                'reference'       => 'PAY-' . strtoupper(Str::random(8)),
                'kind'            => 'payment',
                'type'            => $term->term_name,
                'year'            => (string) $yearNum,
                'semester'        => $semester,
                'amount'          => $term->amount,
                'status'          => 'paid',
                'payment_channel' => self::PAYMENT_CHANNELS[array_rand(self::PAYMENT_CHANNELS)],
                'paid_at'         => $paidAt,
                'created_at'      => $paidAt,
                'updated_at'      => $paidAt,
                'meta'            => [
                    'assessment_id'    => $assessment->id,
                    'assessment_number'=> $assessment->assessment_number,
                    'term_name'        => $term->term_name,
                    'selected_term_id' => $term->id,
                    'description'      => "Payment — {$term->term_name} ({$yearLevel} {$semester} {$schoolYear})",
                ],
            ]);
        }

        return $assessment;
    }

    // =========================================================================
    // USER / STUDENT HELPERS
    // =========================================================================

    private function upsertUser(
        string $email,
        string $firstName,
        string $lastName,
        string $accountId,
        string $yearLevel,
        string $course,
    ): User {
        $user = User::where('email', $email)->first();

        if ($user) {
            // Wipe previous seeded data for a clean slate on re-seed
            Transaction::where('user_id', $user->id)->delete();
            StudentPaymentTerm::whereIn(
                'student_assessment_id',
                StudentAssessment::where('user_id', $user->id)->pluck('id')
            )->delete();
            StudentAssessment::where('user_id', $user->id)->delete();
            $this->cmd()->comment("   ~ Reset existing data for: {$email}");
            return $user;
        }

        $user = User::create([
            'first_name'        => $firstName,
            'last_name'         => $lastName,
            'middle_initial'    => null,
            'email'             => $email,
            'password'          => bcrypt('password'),
            'email_verified_at' => now(),
            'role'              => UserRoleEnum::STUDENT->value,
            'account_id'        => $accountId,
            'year_level'        => $yearLevel,
            'course'            => $course,
            'status'            => User::STATUS_ACTIVE,
            'address'           => 'Naga City, Camarines Sur',
            'phone'             => '09' . rand(100000000, 999999999),
            'birthday'          => '2003-01-01',
        ]);

        Account::firstOrCreate(
            ['user_id' => $user->id],
            [
                'account_number' => $this->nextAccountNumber(),
                'balance'        => 0,
            ]
        );

        $this->cmd()->info("   + Created: {$email} (id: {$user->id})");
        return $user;
    }

    private function upsertStudent(User $user, string $yearLevel): Student
    {
        return Student::updateOrCreate(
            ['user_id' => $user->id],
            [
                'student_id'        => $user->account_id,
                'enrollment_status' => 'active',
            ]
        );
    }

    // =========================================================================
    // UTILITIES
    // =========================================================================

    /**
     * Semester start dates.
     * "2025-2026" + "1st Sem" → Carbon(2025-08-01)
     * "2025-2026" + "2nd Sem" → Carbon(2026-01-05)
     */
    private function semStart(string $semester, string $schoolYear): Carbon
    {
        [$startYear, $endYear] = array_map('intval', explode('-', $schoolYear));

        return $semester === '1st Sem'
            ? Carbon::create($startYear, 8, 1)
            : Carbon::create($endYear, 1, 5);
    }

    private function nextAccountNumber(): string
    {
        $year = now()->year;

        if ($this->accountNumberCounter === 0) {
            $last = Account::where('account_number', 'like', "ACC-{$year}-%")
                ->orderByRaw('CAST(SUBSTRING(account_number, 10) AS UNSIGNED) DESC')
                ->first();

            $this->accountNumberCounter = $last
                ? (int) substr($last->account_number, -4)
                : 0;
        }

        return 'ACC-' . $year . '-' . str_pad(++$this->accountNumberCounter, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Recalculate account balance from outstanding payment terms.
     * Uses AccountService if available, otherwise computes directly.
     */
    private function recalculateAccount(User $user): void
    {
        if (class_exists(\App\Services\AccountService::class)) {
            \App\Services\AccountService::recalculate($user);
        }
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

    private function cmd(): \Illuminate\Console\Command
    {
        return $this->command;
    }

    private function printSummary(): void
    {
        $this->cmd()->info('');
        $this->cmd()->info(str_repeat('═', 68));
        $this->cmd()->info('  ✅  ALL 4 TEST STUDENTS SEEDED');
        $this->cmd()->info(str_repeat('═', 68));

        // Fee breakdown for reference
        $tuitionRate = (float) config('fees.tuition_per_lec_unit', 364.00);
        $labRate     = (float) config('fees.lab.per_unit', 1656.00);
        $entrepFee   = (float) config('fees.lab.entrepreneurship_fee', 600.00);
        $miscFee     = (float) config('fees.misc_fee_fixed', 4700.00);

        $this->cmd()->info('');
        $this->cmd()->info('  FEE FORMULA USED:');
        $this->cmd()->info("  Tuition: lec_units_for_fee × ₱{$tuitionRate}");
        $this->cmd()->info("  Lab:     (lab_units × ₱{$labRate}) + ₱{$entrepFee} entrep fee");
        $this->cmd()->info("  Misc:    ₱{$miscFee} (fixed per semester)");

        $this->cmd()->info('');
        $this->cmd()->table(
            ['Email', 'Password', 'Current Sem', 'Action to Test'],
            [
                ['maria.santos@test.com',         'password', '1st Yr 2nd Sem', 'Pay "Final" term'],
                ['juan.dela.cruz@test.com',        'password', '2nd Yr 1st Sem', 'Pay Semi-Final + Final'],
                ['ana.garcia@test.com',            'password', '1st Yr 1st Sem', 'Pay all 5 terms (no due dates)'],
                ['transaction.history@ccdi.edu.ph','password', '3rd Yr 2nd Sem', 'Full history — pay current sem'],
            ]
        );

        $this->cmd()->info('');
        $this->cmd()->info('  WHAT HAPPENS WHEN ALL TERMS ARE PAID:');
        $this->cmd()->info('  ─────────────────────────────────────────');
        $this->cmd()->info('  Admin notification: "Please create [Student]\'s [Next Sem] assessment"');
        $this->cmd()->info('  Student notification: "[Sem] fully paid — admin is preparing next sem"');
        $this->cmd()->info('');
        $this->cmd()->info('  TRANSACTION HISTORY (transaction.history@ccdi.edu.ph):');
        $this->cmd()->info('  Section 1 → 3rd Yr 2nd Sem (2025-2026) — UNPAID (current)');
        $this->cmd()->info('  Section 2 → 3rd Yr 1st Sem (2025-2026) — paid');
        $this->cmd()->info('  Section 3 → 2nd Yr 2nd Sem (2024-2025) — paid');
        $this->cmd()->info('  Section 4 → 2nd Yr 1st Sem (2024-2025) — paid');
        $this->cmd()->info('  Section 5 → 1st Yr 2nd Sem (2023-2024) — paid');
        $this->cmd()->info('  Section 6 → 1st Yr 1st Sem (2023-2024) — paid');
        $this->cmd()->info(str_repeat('═', 68));
        $this->cmd()->info('');
    }
}