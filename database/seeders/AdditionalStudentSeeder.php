<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Database\Seeders\Traits\GetAdminUserTrait;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\Account;
use App\Models\Notification;
use App\Models\Student;
use App\Models\StudentAssessment;
use App\Models\StudentPaymentTerm;
use App\Models\Transaction;
use App\Models\User;
use App\Enums\UserRoleEnum;

/**
 * AdditionalStudentSeeder
 *
 * Creates 3 named test students, each placed at a strategic point in their
 * academic journey to fully demonstrate the semester-completion detection
 * and admin-notification workflow.
 *
 * ┌───────────────────────────────────────────────────────────────────────────┐
 * │ Student                  │ Scenario                                      │
 * ├───────────────────────────────────────────────────────────────────────────┤
 * │ maria.santos@test.com    │ 1st Year 2nd Sem — 4 of 5 terms PAID.         │
 * │                          │ Pay the "Final" term once → all 5 terms paid  │
 * │                          │ → system notifies Admin to create              │
 * │                          │   "2nd Year 1st Sem" assessment.               │
 * │                          │ History: 1st Year 1st Sem fully paid.          │
 * ├───────────────────────────────────────────────────────────────────────────┤
 * │ juan.dela.cruz@test.com  │ 2nd Year 1st Sem — 3 of 5 terms PAID.         │
 * │                          │ Pay "Semi-Final" + "Final" → all 5 paid       │
 * │                          │ → system notifies Admin to create              │
 * │                          │   "2nd Year 2nd Sem" assessment.               │
 * │                          │ History: 1st Yr 1st Sem + 1st Yr 2nd Sem paid.│
 * ├───────────────────────────────────────────────────────────────────────────┤
 * │ ana.garcia@test.com      │ 1st Year 1st Sem — ALL 5 terms UNPAID.        │
 * │                          │ No due dates set (edge-case / baseline test).  │
 * └───────────────────────────────────────────────────────────────────────────┘
 *
 * HOW PROGRESSION WORKS (no code change needed here):
 * ─────────────────────────────────────────────────────
 * 1. Student pays last unpaid term → StudentPaymentService::processPayment()
 *    or finalizeApprovedPayment() detects all terms = PAID.
 * 2. System sends:
 *    a) Admin notification: "Please create [Student]'s [Next Sem] assessment"
 *    b) Student notification: "Your [Sem] is fully paid. Admin is preparing next."
 * 3. Admin goes to StudentFees → Create Assessment and creates it manually.
 *    This is identical to how any new assessment is created.
 *
 * USAGE:
 * ─────────────────────────────────────────────────────
 *   php artisan db:seed --class=AdditionalStudentSeeder
 */
class AdditionalStudentSeeder extends Seeder
{
    use GetAdminUserTrait;

    // ── Shared constants ───────────────────────────────────────────────────────

    private const SCHOOL_YEAR      = '2025-2026';
    private const PREV_SCHOOL_YEAR = '2024-2025';

    /**
     * Payment term definitions — must match ComprehensiveAssessmentSeeder exactly.
     * Percentages sum to 100.00%.
     */
    private const TERM_DEFINITIONS = [
        1 => ['name' => 'Upon Registration', 'percentage' => 42.15],
        2 => ['name' => 'Prelim',            'percentage' => 17.86],
        3 => ['name' => 'Midterm',           'percentage' => 17.86],
        4 => ['name' => 'Semi-Final',        'percentage' => 14.88],
        5 => ['name' => 'Final',             'percentage' =>  7.25],
    ];

    /**
     * Fee structures per Year × Semester — mirrors ComprehensiveAssessmentSeeder.
     */
    private const FEE_STRUCTURES = [
        '1st Year' => [
            '1st Sem' => [
                ['category' => 'Academic',      'name' => 'Tuition Fee',          'amount' => 15000.00],
                ['category' => 'Laboratory',    'name' => 'Laboratory Fee',       'amount' =>  1500.00],
                ['category' => 'Miscellaneous', 'name' => 'Registration Fee',     'amount' =>   500.00],
                ['category' => 'Miscellaneous', 'name' => 'Miscellaneous Fee',    'amount' =>   600.00],
                ['category' => 'Miscellaneous', 'name' => 'Athletics Fee',        'amount' =>   200.00],
                ['category' => 'Miscellaneous', 'name' => 'Library Fee',          'amount' =>   200.00],
                ['category' => 'Other',         'name' => 'Student Activity Fee', 'amount' =>   300.00],
                ['category' => 'Other',         'name' => 'ICT Fee',              'amount' =>   400.00],
                ['category' => 'Other',         'name' => 'Medical/Dental Fee',   'amount' =>   300.00],
            ],
            '2nd Sem' => [
                ['category' => 'Academic',      'name' => 'Tuition Fee',          'amount' => 15000.00],
                ['category' => 'Laboratory',    'name' => 'Laboratory Fee',       'amount' =>  1500.00],
                ['category' => 'Miscellaneous', 'name' => 'Registration Fee',     'amount' =>   400.00],
                ['category' => 'Miscellaneous', 'name' => 'Miscellaneous Fee',    'amount' =>   600.00],
                ['category' => 'Miscellaneous', 'name' => 'Athletics Fee',        'amount' =>   200.00],
                ['category' => 'Miscellaneous', 'name' => 'Library Fee',          'amount' =>   200.00],
                ['category' => 'Other',         'name' => 'Student Activity Fee', 'amount' =>   300.00],
                ['category' => 'Other',         'name' => 'ICT Fee',              'amount' =>   400.00],
                ['category' => 'Other',         'name' => 'Medical/Dental Fee',   'amount' =>   300.00],
            ],
        ],
        '2nd Year' => [
            '1st Sem' => [
                ['category' => 'Academic',      'name' => 'Tuition Fee',          'amount' => 16500.00],
                ['category' => 'Laboratory',    'name' => 'Laboratory Fee',       'amount' =>  1800.00],
                ['category' => 'Miscellaneous', 'name' => 'Registration Fee',     'amount' =>   500.00],
                ['category' => 'Miscellaneous', 'name' => 'Miscellaneous Fee',    'amount' =>   600.00],
                ['category' => 'Miscellaneous', 'name' => 'Athletics Fee',        'amount' =>   200.00],
                ['category' => 'Miscellaneous', 'name' => 'Library Fee',          'amount' =>   200.00],
                ['category' => 'Other',         'name' => 'Student Activity Fee', 'amount' =>   300.00],
                ['category' => 'Other',         'name' => 'ICT Fee',              'amount' =>   400.00],
                ['category' => 'Other',         'name' => 'Medical/Dental Fee',   'amount' =>   300.00],
            ],
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
            $this->cmd()->info('1/3  maria.santos@test.com');
            $this->seedMaria();

            $this->cmd()->info('');
            $this->cmd()->info('2/3  juan.dela.cruz@test.com');
            $this->seedJuan();

            $this->cmd()->info('');
            $this->cmd()->info('3/3  ana.garcia@test.com');
            $this->seedAna();

            $this->printSummary();
        });
    }

    // =========================================================================
    // STUDENT SCENARIOS
    // =========================================================================

    /**
     * MARIA SANTOS — 1st Year, 2nd Semester
     *
     * History : 1st Year 1st Sem — fully paid (completed history).
     * Current : 1st Year 2nd Sem — terms 1–4 paid, term 5 (Final) unpaid.
     *
     * TEST ACTION: Pay "Final" → all 5 terms = PAID →
     *   Admin gets: "Create Maria's 2nd Year 1st Sem assessment"
     *   Maria gets: "1st Year 2nd Sem fully paid — admin preparing next sem"
     */
    private function seedMaria(): void
    {
        $user = $this->upsertUser(
            email: 'maria.santos@test.com',
            firstName: 'Maria', lastName: 'Santos',
            accountId: '2024-0002', yearLevel: '1st Year'
        );
        $this->upsertStudent($user, '1st Year');

        // ── Completed: 1st Year 1st Sem ───────────────────────────────────────
        $this->buildAssessment(
            user: $user,
            yearLevel: '1st Year', semester: '1st Sem',
            schoolYear: self::PREV_SCHOOL_YEAR,
            paidOrders: [1, 2, 3, 4, 5],
            paidAtBase: Carbon::create(2024, 12, 10)
        );
        $this->cmd()->info('   ✓ 1st Year 1st Sem — FULLY PAID (historical)');

        // ── Current: 1st Year 2nd Sem — only Final (term 5) unpaid ───────────
        $assessment = $this->buildAssessment(
            user: $user,
            yearLevel: '1st Year', semester: '2nd Sem',
            schoolYear: self::SCHOOL_YEAR,
            paidOrders: [1, 2, 3, 4],           // Term 5 "Final" is deliberately unpaid
            paidAtBase: Carbon::create(2025, 2, 1)
        );

        $remaining = $assessment->paymentTerms()->where('status', '!=', 'paid')->sum('balance');
        $this->cmd()->info('   ✓ 1st Year 2nd Sem — 4/5 paid. "Final" unpaid: ₱' . number_format($remaining, 2));
        $this->cmd()->info('   → Pay "Final" to trigger admin progression notification');

        \App\Services\AccountService::recalculate($user);
    }

    /**
     * JUAN DELA CRUZ — 2nd Year, 1st Semester
     *
     * History : 1st Year 1st Sem fully paid, 1st Year 2nd Sem fully paid.
     * Current : 2nd Year 1st Sem — terms 1–3 paid, terms 4–5 unpaid.
     *
     * TEST ACTION: Pay "Semi-Final" + "Final" → all 5 terms = PAID →
     *   Admin gets: "Create Juan's 2nd Year 2nd Sem assessment"
     *   Juan gets:  "2nd Year 1st Sem fully paid — admin preparing next sem"
     */
    private function seedJuan(): void
    {
        $user = $this->upsertUser(
            email: 'juan.dela.cruz@test.com',
            firstName: 'Juan', lastName: 'Dela Cruz',
            accountId: '2024-0003', yearLevel: '2nd Year'
        );
        $this->upsertStudent($user, '2nd Year');

        // ── Completed: 1st Year 1st Sem ───────────────────────────────────────
        $this->buildAssessment(
            user: $user,
            yearLevel: '1st Year', semester: '1st Sem',
            schoolYear: self::PREV_SCHOOL_YEAR,
            paidOrders: [1, 2, 3, 4, 5],
            paidAtBase: Carbon::create(2024, 1, 10)
        );
        $this->cmd()->info('   ✓ 1st Year 1st Sem — FULLY PAID (historical)');

        // ── Completed: 1st Year 2nd Sem ───────────────────────────────────────
        $this->buildAssessment(
            user: $user,
            yearLevel: '1st Year', semester: '2nd Sem',
            schoolYear: self::PREV_SCHOOL_YEAR,
            paidOrders: [1, 2, 3, 4, 5],
            paidAtBase: Carbon::create(2024, 6, 5)
        );
        $this->cmd()->info('   ✓ 1st Year 2nd Sem — FULLY PAID (historical)');

        // ── Current: 2nd Year 1st Sem — Semi-Final + Final still unpaid ───────
        $assessment = $this->buildAssessment(
            user: $user,
            yearLevel: '2nd Year', semester: '1st Sem',
            schoolYear: self::SCHOOL_YEAR,
            paidOrders: [1, 2, 3],              // Terms 4 "Semi-Final" + 5 "Final" unpaid
            paidAtBase: Carbon::create(2025, 9, 1)
        );

        $remaining = $assessment->paymentTerms()->where('status', '!=', 'paid')->sum('balance');
        $this->cmd()->info('   ✓ 2nd Year 1st Sem — 3/5 paid. Semi-Final + Final unpaid: ₱' . number_format($remaining, 2));
        $this->cmd()->info('   → Pay both to trigger admin progression notification');

        \App\Services\AccountService::recalculate($user);
    }

    /**
     * ANA GARCIA — 1st Year, 1st Semester (brand new student)
     *
     * No payment history. All 5 terms unpaid. No due dates set.
     * Represents a freshly enrolled student — baseline / edge-case test.
     *
     * TEST ACTION: Pay all 5 terms one by one. Only after the LAST one is paid
     * does the admin notification fire.
     */
    private function seedAna(): void
    {
        $user = $this->upsertUser(
            email: 'ana.garcia@test.com',
            firstName: 'Ana', lastName: 'Garcia',
            accountId: '2024-0004', yearLevel: '1st Year'
        );
        $this->upsertStudent($user, '1st Year');

        // ── Current: 1st Year 1st Sem — all unpaid, no due dates ─────────────
        $assessment = $this->buildAssessment(
            user: $user,
            yearLevel: '1st Year', semester: '1st Sem',
            schoolYear: self::SCHOOL_YEAR,
            paidOrders: [],                     // Nothing paid yet
            setDueDates: false                  // No due dates — edge case
        );

        $this->cmd()->info('   ✓ 1st Year 1st Sem — FULLY UNPAID (no due dates)');
        $this->cmd()->info('   → Total: ₱' . number_format($assessment->total_assessment, 2));
        $this->cmd()->info('   → Pay all 5 terms to trigger admin progression notification');

        \App\Services\AccountService::recalculate($user);
    }

    // =========================================================================
    // CORE ASSESSMENT BUILDER
    // =========================================================================

    /**
     * Create one assessment for the given student.
     * Idempotent: skips silently if the assessment already exists.
     *
     * @param  User        $user
     * @param  string      $yearLevel    e.g. "1st Year"
     * @param  string      $semester     e.g. "1st Sem"
     * @param  string      $schoolYear   e.g. "2025-2026"
     * @param  int[]       $paidOrders   Term orders (1–5) that are already paid
     * @param  Carbon|null $paidAtBase   Base date for paid-at timestamps
     * @param  bool        $setDueDates  Whether to populate due_date on unpaid terms
     */
    private function buildAssessment(
        User    $user,
        string  $yearLevel,
        string  $semester,
        string  $schoolYear,
        array   $paidOrders  = [],
        ?Carbon $paidAtBase  = null,
        bool    $setDueDates = true
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

        // ── Resolve fees ───────────────────────────────────────────────────────
        $fees = self::FEE_STRUCTURES[$yearLevel][$semester] ?? [
            ['category' => 'Tuition', 'name' => 'Tuition Fee', 'amount' => 15000.00],
        ];

        $tuitionFee      = collect($fees)->where('category', 'Tuition')->sum('amount');
        $otherFees       = collect($fees)->where('category', '!=', 'Tuition')->sum('amount');
        $totalAssessment = round($tuitionFee + $otherFees, 2);
        $yearNum         = (int) explode('-', $schoolYear)[0];
        $semStart        = $this->semStart($semester, $schoolYear);
        $allPaid         = count($paidOrders) === count(self::TERM_DEFINITIONS);

        // Calculate units based on year level
        $unitMap = [
            '1st Year' => ['lec' => 48, 'lab' => 4],
            '2nd Year' => ['lec' => 48, 'lab' => 3],
            '3rd Year' => ['lec' => 45, 'lab' => 2],
            '4th Year' => ['lec' => 42, 'lab' => 2],
        ];
        $units = $unitMap[$yearLevel] ?? ['lec' => 48, 'lab' => 3];

        // ── Assessment record ──────────────────────────────────────────────────
        $assessment = StudentAssessment::create([
            'user_id'           => $user->id,
            'assessment_number' => StudentAssessment::generateAssessmentNumber(),
            'year_level'        => $yearLevel,
            'semester'          => $semester,
            'school_year'       => $schoolYear,
            'lec_units'         => $units['lec'],
            'lab_units'         => $units['lab'],
            'total_assessment'  => $totalAssessment,
            'status'            => 'active',
        ]);

        // DISABLED: Do not create charge transactions during seeding
        // Charges should only be created when admin explicitly creates an assessment
        // via the StudentFeeController::store() endpoint, not automatically during seed.
        /*
        // ── Charge transaction ─────────────────────────────────────────────────
        Transaction::create([
            'user_id'   => $user->id,
            'reference' => 'CHG-' . strtoupper(Str::random(8)),
            'kind'      => 'charge',
            'type'      => 'Academic',
            'year'      => $yearNum,
            'semester'  => $semester,
            'amount'    => $totalAssessment,
            'status'    => $allPaid ? 'paid' : 'pending',
            'meta'      => [
                'assessment_id' => $assessment->id,
                'description'   => "Assessment — {$yearLevel} {$semester} {$schoolYear}",
            ],
        ]);
        */

        // ── Payment terms ──────────────────────────────────────────────────────
        $allocated = 0.00;
        $lastOrder = array_key_last(self::TERM_DEFINITIONS);

        foreach (self::TERM_DEFINITIONS as $order => $def) {
            // Last term absorbs rounding remainder so total is exact
            $amount = ($order === $lastOrder)
                ? round($totalAssessment - $allocated, 2)
                : round(($def['percentage'] / 100) * $totalAssessment, 2);

            if ($order !== $lastOrder) {
                $allocated += $amount;
            }

            $isPaid   = in_array($order, $paidOrders, true);
            $paidDate = ($isPaid && $paidAtBase)
                ? $paidAtBase->copy()->addDays($order * 3)
                : null;

            // Always set due date on paid terms; only set on unpaid terms when $setDueDates = true
            $dueDate = ($isPaid || $setDueDates)
                ? $semStart->copy()->addWeeks(($order - 1) * 4)->toDateString()
                : null;

            $term = StudentPaymentTerm::create([
                'student_assessment_id'  => $assessment->id,
                'term_name'              => $def['name'],
                'term_order'             => $order,
                'percentage'             => $def['percentage'],
                'amount'                 => $amount,
                'balance'                => $isPaid ? 0.00 : $amount,
                'due_date'               => $dueDate,
                'status'                 => $isPaid
                                                ? StudentPaymentTerm::STATUS_PAID
                                                : StudentPaymentTerm::STATUS_PENDING,
                'paid_date'              => $paidDate,
                'carryover_from_term_id' => null,
                'carryover_amount'       => 0.00,
            ]);

            // Payment transaction for already-paid terms
            if ($isPaid) {
                Transaction::create([
                    'user_id'         => $user->id,
                    'reference'       => 'PAY-' . strtoupper(Str::random(8)),
                    'kind'            => 'payment',
                    'type'            => $def['name'],
                    'year'            => $yearNum,
                    'semester'        => $semester,
                    'amount'          => $amount,
                    'status'          => 'paid',
                    'payment_channel' => 'gcash',
                    'paid_at'         => $paidDate,
                    'created_at'      => $paidDate,
                    'updated_at'      => $paidDate,
                    'meta'            => [
                        'assessment_id'    => $assessment->id,
                        'term_name'        => $def['name'],
                        'selected_term_id' => $term->id,
                        'description'      => "Payment — {$def['name']} ({$yearLevel} {$semester})",
                    ],
                ]);
            }
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
        string $yearLevel
    ): User {
        $user = User::where('email', $email)->first();

        if ($user) {
            // Wipe previous seeded data so we always start from a clean state
            Transaction::where('user_id', $user->id)->delete();
            StudentPaymentTerm::whereIn(
                'student_assessment_id',
                StudentAssessment::where('user_id', $user->id)->pluck('id')
            )->delete();
            StudentAssessment::where('user_id', $user->id)->delete();
            $this->cmd()->comment("   ~ Reset existing user: {$email}");
            return $user;
        }

        $user = User::create([
            'first_name'        => $firstName,
            'last_name'         => $lastName,
            'email'             => $email,
            'password'          => bcrypt('password'),
            'email_verified_at' => now(),
            'role'              => UserRoleEnum::STUDENT->value,
            'account_id'        => $accountId,
            'year_level'        => $yearLevel,
            'status'            => User::STATUS_ACTIVE,
            'course'            => 'BS Electrical Engineering Technology',
        ]);

        // Idempotent account creation — use firstOrCreate to avoid duplicate errors
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
                'student_id'       => $user->account_id,
                'enrollment_status' => 'active',
            ]
        );
    }

    // =========================================================================
    // UTILITIES
    // =========================================================================

    /**
     * Return the Carbon start date for a semester.
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

    /** Convenience wrapper so we avoid repeating $this->command everywhere */
    private function cmd(): \Illuminate\Console\Command
    {
        return $this->command;
    }

    private function printSummary(): void
    {
        $this->cmd()->info('');
        $this->cmd()->info(str_repeat('═', 68));
        $this->cmd()->info('  ✅  ALL TEST STUDENTS SEEDED');
        $this->cmd()->info(str_repeat('═', 68));
        $this->cmd()->table(
            ['Email', 'Password', 'Current Sem', 'Action to Test Progression'],
            [
                ['maria.santos@test.com',   'password', '1st Yr 2nd Sem', 'Pay "Final" term (1 payment)'],
                ['juan.dela.cruz@test.com', 'password', '2nd Yr 1st Sem', 'Pay "Semi-Final" + "Final" (2 payments)'],
                ['ana.garcia@test.com',     'password', '1st Yr 1st Sem', 'Pay all 5 terms (no due dates)'],
            ]
        );
        $this->cmd()->info('');
        $this->cmd()->info('  WHAT HAPPENS WHEN LAST TERM IS PAID:');
        $this->cmd()->info('  ─────────────────────────────────────');
        $this->cmd()->info('  1. Admin receives notification:');
        $this->cmd()->info('     "📋 Assessment Required: [Student Name]"');
        $this->cmd()->info('     → Admin goes to Student Fees → Create Assessment');
        $this->cmd()->info('     → Creates the next semester\'s assessment manually');
        $this->cmd()->info('');
        $this->cmd()->info('  2. Student receives notification:');
        $this->cmd()->info('     "✅ [Year] [Sem] Fully Paid!"');
        $this->cmd()->info('     → Informs them the admin is preparing next sem payables');
        $this->cmd()->info('');
        $this->cmd()->info('  This mirrors the real school workflow — the admin always');
        $this->cmd()->info('  controls when and what assessment a student receives.');
        $this->cmd()->info(str_repeat('═', 68));
        $this->cmd()->info('');
    }
}