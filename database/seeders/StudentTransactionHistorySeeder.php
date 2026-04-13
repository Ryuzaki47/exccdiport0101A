<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Student;
use App\Models\StudentAssessment;
use App\Models\StudentPaymentTerm;
use App\Models\Transaction;
use App\Models\Account;
use App\Enums\UserRoleEnum;
use Illuminate\Support\Str;
use Database\Seeders\Traits\GetAdminUserTrait;

/**
 * StudentTransactionHistorySeeder
 *
 * Creates a 3rd year student with complete transaction history:
 * - 1st Year 1st & 2nd Semester: All PAID
 * - 2nd Year 1st & 2nd Semester: All PAID
 * - 3rd Year 1st Semester: PAID
 * - 3rd Year 2nd Semester (Current): NOT PAID (pending manual payment)
 *
 * Results in 6 expandable transaction history sections
 * Displayed newest-first (3rd Yr 2nd Sem at top, 1st Yr 1st Sem at bottom)
 *
 * USAGE:
 * ------
 * php artisan db:seed --class=StudentTransactionHistorySeeder
 *
 * STUDENT DETAILS:
 * ----------------
 * Email: transaction.history@ccdi.edu.ph
 * Password: password
 * Year Level: 3rd Year
 * Current Term: 2nd Semester
 *
 * EXPECTED OUTCOME:
 * -----------------
 * ✅ One student created with full history
 * ✅ 6 assessments generated (1st yr 1stSem, 1st yr 2ndSem, 2nd yr 1stSem, 2nd yr 2ndSem, 3rd yr 1stSem, 3rd yr 2ndSem)
 * ✅ 5 payment terms per assessment
 * ✅ ALL payments for 1st-3rd year except current 3rd yr 2nd sem: PAID
 * ✅ 3rd Year 2nd Semester (Current): UNPAID (ready for manual payment test)
 * ✅ Full transaction history visible in Transactions page
 * ✅ Display order: Newest (3rd Yr 2nd Sem) → Oldest (1st Yr 1st Sem)
 */
class StudentTransactionHistorySeeder extends Seeder
{
    use GetAdminUserTrait;

    private static $accountIdCounter = 201;
    // Instance counter for generating account numbers sequentially during seeding
    private $accountNumberCounter = 0;

    /**
     * Generate account number sequentially by querying last used number from database
     */
    private function getNextAccountNumber(): string
    {
        $year = now()->year;
        
        // Query for highest number used in current year
        if ($this->accountNumberCounter === 0) {
            $lastAccount = \App\Models\Account::where('account_number', 'like', "ACC-{$year}-%")
                ->orderByRaw("CAST(SUBSTRING(account_number, 10) AS UNSIGNED) DESC")
                ->first();
            
            if ($lastAccount) {
                $this->accountNumberCounter = intval(substr($lastAccount->account_number, -4));
            }
        }
        
        $this->accountNumberCounter++;
        $number = str_pad($this->accountNumberCounter, 4, '0', STR_PAD_LEFT);
        return "ACC-{$year}-{$number}";
    }

    private const EMAIL = 'transaction.history@ccdi.edu.ph';
    private const COURSE = 'Computer Science';
    private const TOTAL_ASSESSMENT_PER_TERM = 15000;

    // Payment term percentages and names
    private const PAYMENT_TERMS = [
        [
            'term_name' => 'Upon Registration',
            'percentage' => 42.15,
            'term_order' => 1,
        ],
        [
            'term_name' => 'Prelim',
            'percentage' => 17.86,
            'term_order' => 2,
        ],
        [
            'term_name' => 'Midterm',
            'percentage' => 17.86,
            'term_order' => 3,
        ],
        [
            'term_name' => 'Semi-Final',
            'percentage' => 14.88,
            'term_order' => 4,
        ],
        [
            'term_name' => 'Final',
            'percentage' => 7.25,
            'term_order' => 5,
        ],
    ];

    public function run(): void
    {
        DB::transaction(function () {
            // ============================================
            // 0. CLEANUP EXISTING DATA
            // ============================================
            $existingUser = User::where('email', self::EMAIL)->first();
            if ($existingUser) {
                // Delete transactions
                Transaction::where('user_id', $existingUser->id)->delete();

                // Delete payment terms and assessments via user_id
                StudentPaymentTerm::where('user_id', $existingUser->id)->delete();
                StudentAssessment::where('user_id', $existingUser->id)->delete();

                $this->command->info('✓ Cleaned up existing data for ' . self::EMAIL);
                $this->command->newLine();
            }

            // ============================================
            // 1. GET OR CREATE STUDENT
            // ============================================
            $student = $this->getOrCreateStudent();

            if (!$student) {
                $this->command->error('❌ Failed to create student');
                return;
            }

            $this->command->info("✓ Student: {$student->email} (ID: {$student->id})");
            $this->command->newLine();

            // ============================================
            // 2. CREATE ASSESSMENTS & PAYMENTS FOR PAID TERMS
            // ============================================
            $this->command->info('📚 Creating paid assessments (All semesters except current)...');

            $paidTerms = [
                ['year' => '1st Year', 'year_num' => '2023-2024', 'semester' => '1st Sem'],
                ['year' => '1st Year', 'year_num' => '2023-2024', 'semester' => '2nd Sem'],
                ['year' => '2nd Year', 'year_num' => '2024-2025', 'semester' => '1st Sem'],
                ['year' => '2nd Year', 'year_num' => '2024-2025', 'semester' => '2nd Sem'],
                ['year' => '3rd Year', 'year_num' => '2025-2026', 'semester' => '1st Sem'],
            ];

            foreach ($paidTerms as $termConfig) {
                $assessment = $this->createAssessmentWithPaidPayments($student, $termConfig);
                $this->command->info("  ✓ {$termConfig['year']}, {$termConfig['semester']}: " .
                    number_format($assessment->total_assessment, 2) . " - ALL PAID");
            }

            $this->command->newLine();

            // ============================================
            // 3. CREATE UNPAID ASSESSMENT FOR CURRENT TERM
            // ============================================
            $this->command->info('📚 Creating unpaid assessment (Current: 3rd Year, 2nd Semester)...');

            $currentTermAssessment = $this->createAssessmentWithUnpaidTerms($student, [
                'year' => '3rd Year',
                'year_num' => '2025-2026',
                'semester' => '2nd Sem',
            ]);

            $this->command->info("  ✓ 3rd Year, 2nd Sem: " .
                number_format($currentTermAssessment->total_assessment, 2) . " - NOT PAID");

            $this->command->newLine();

            // ============================================
            // 4. DISPLAY SUMMARY
            // ============================================
            $this->displaySummary($student);
        });
    }

    /**
     * Get or create student user
     */
    private function getOrCreateStudent(): ?Student
    {
        // Try to find existing student
        $student = Student::whereHas('user', function ($query) {
            $query->where('email', self::EMAIL);
        })->first();

        if ($student) {
            return $student;
        }

        // Try to find user
        $user = User::where('email', self::EMAIL)->first();

        // Generate proper Account ID
        $accountId = '2025-' . str_pad(self::$accountIdCounter++, 4, '0', STR_PAD_LEFT);

        if (!$user) {
            $user = User::create([
                'first_name' => 'Transaction',
                'last_name' => 'History',
                'middle_initial' => 'S',
                'email' => self::EMAIL,
                'course' => self::COURSE,
                'year_level' => '3rd Year',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
                'role' => UserRoleEnum::STUDENT->value,
                'account_id' => $accountId,
            ]);

            // Create account
            Account::create([
                'user_id' => $user->id,
                'account_number' => $this->getNextAccountNumber(),
                'balance' => 0,
            ]);
        }

        // Create student record with student-specific fields only
        $student = Student::create([
            'user_id' => $user->id,
            'student_id' => $accountId,
            'student_number' => 'STU-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT),
            'enrollment_status' => 'active',
        ]);

        return $student;
    }

    /**
     * Create assessment with ALL payment terms PAID
     */
    private function createAssessmentWithPaidPayments(Student $student, array $termConfig): StudentAssessment
    {
        $assessment = StudentAssessment::create([
            'user_id' => $student->user_id,
            'assessment_number' => StudentAssessment::generateAssessmentNumber(),
            'year_level' => $termConfig['year'],
            'semester' => $termConfig['semester'],
            'school_year' => $termConfig['year_num'],
            'lec_units' => 48,
            'lab_units' => 3,
            'total_assessment' => self::TOTAL_ASSESSMENT_PER_TERM,
            'status' => 'active',
        ]);

        // Create payment terms
        $totalAmount = 0;
        $totalTerms = count(self::PAYMENT_TERMS);
        $termIndex = 0;
        foreach (self::PAYMENT_TERMS as $term) {
            $termIndex++;
            // For the last term, use remainder to ensure total equals assessment (no rounding)
            if ($termIndex === $totalTerms) {
                $amount = self::TOTAL_ASSESSMENT_PER_TERM - $totalAmount;
            } else {
                $amount = round(self::TOTAL_ASSESSMENT_PER_TERM * ($term['percentage'] / 100), 2);
                $totalAmount += $amount;
            }

            $paymentTerm = StudentPaymentTerm::create([
                'student_assessment_id' => $assessment->id,
                'user_id' => $student->user_id,
                'term_name' => $term['term_name'],
                'term_order' => $term['term_order'],
                'percentage' => $term['percentage'],
                'amount' => $amount,
                'balance' => 0, // PAID - no balance
                'due_date' => $this->calculateDueDate($assessment, $term['term_order']),
                'status' => StudentPaymentTerm::STATUS_PAID,
                'paid_date' => now()->subDays(rand(5, 30)),
            ]);

            $termsData[] = $paymentTerm;
        }

        // Create transaction for assessment charge
        Transaction::create([
            'user_id' => $student->user_id,
            'reference' => 'ASS-' . strtoupper(Str::random(8)),
            'kind' => 'charge',
            'type' => 'Tuition Fee',
            'year' => substr($termConfig['year_num'], 0, 4),
            'semester' => $termConfig['semester'],
            'amount' => self::TOTAL_ASSESSMENT_PER_TERM,
            'status' => 'paid',
            'meta' => [
                'assessment_id' => $assessment->id,
                'assessment_number' => $assessment->assessment_number,
            ],
        ]);

        // Create payment transactions for each term
        foreach ($termsData as $term) {
            $paymentDate = $term->paid_date ?? now()->subDays(rand(1, 10));
            $paymentRef = substr($term->term_name, 0, 3) . '-' . strtoupper(Str::random(6));

            Transaction::create([
                'user_id' => $student->user_id,
                'reference' => $paymentRef,
                'kind' => 'payment',
                'type' => 'Payment: ' . $term->term_name,
                'year' => substr($termConfig['year_num'], 0, 4),
                'semester' => $termConfig['semester'],
                'amount' => $term->amount,
                'status' => 'paid',
                'payment_channel' => $this->getRandomPaymentChannel(),
                'paid_at' => $paymentDate,
                'created_at' => $paymentDate,
                'meta' => [
                    'assessment_id' => $assessment->id,
                    'term_id' => $term->id,
                    'term_name' => $term->term_name,
                ],
            ]);
        }

        return $assessment;
    }

    /**
     * Create assessment with UNPAID payment terms (current semester)
     */
    private function createAssessmentWithUnpaidTerms(Student $student, array $termConfig): StudentAssessment
    {
        $assessment = StudentAssessment::create([
            'user_id' => $student->user_id,
            'assessment_number' => StudentAssessment::generateAssessmentNumber(),
            'year_level' => $termConfig['year'],
            'semester' => $termConfig['semester'],
            'school_year' => $termConfig['year_num'],
            'lec_units' => 48,
            'lab_units' => 3,
            'total_assessment' => self::TOTAL_ASSESSMENT_PER_TERM,
            'status' => 'active',
        ]);

        // Create payment terms - ALL UNPAID
        $totalAmount = 0;
        $totalTerms = count(self::PAYMENT_TERMS);
        $termIndex = 0;
        foreach (self::PAYMENT_TERMS as $term) {
            $termIndex++;
            // For the last term, use remainder to ensure total equals assessment (no rounding)
            if ($termIndex === $totalTerms) {
                $amount = self::TOTAL_ASSESSMENT_PER_TERM - $totalAmount;
            } else {
                $amount = round(self::TOTAL_ASSESSMENT_PER_TERM * ($term['percentage'] / 100), 2);
                $totalAmount += $amount;
            }

            StudentPaymentTerm::create([
                'student_assessment_id' => $assessment->id,
                'user_id' => $student->user_id,
                'term_name' => $term['term_name'],
                'term_order' => $term['term_order'],
                'percentage' => $term['percentage'],
                'amount' => $amount,
                'balance' => $amount, // UNPAID - full balance
                'due_date' => $this->calculateDueDate($assessment, $term['term_order']),
                'status' => StudentPaymentTerm::STATUS_PENDING,
                'paid_date' => null,
            ]);
        }

        // Create transaction for assessment charge (pending)
        Transaction::create([
            'user_id' => $student->user_id,
            'reference' => 'ASS-' . strtoupper(Str::random(8)),
            'kind' => 'charge',
            'type' => 'Tuition Fee',
            'year' => substr($termConfig['year_num'], 0, 4),
            'semester' => $termConfig['semester'],
            'amount' => self::TOTAL_ASSESSMENT_PER_TERM,
            'status' => 'pending',
            'meta' => [
                'assessment_id' => $assessment->id,
                'assessment_number' => $assessment->assessment_number,
            ],
        ]);

        return $assessment;
    }

    /**
     * Calculate due date based on term order
     */
    private function calculateDueDate(StudentAssessment $assessment, int $termOrder): string
    {
        // Parse school year (e.g., "2025-2026")
        $schoolYear = explode('-', $assessment->school_year);
        $year = (int)$schoolYear[0];

        // Calculate month based on semester and term order
        if ($assessment->semester == '1st Sem') {
            // First semester: Aug-Dec
            $baseMonth = 8; // August
        } else {
            // Second semester: Jan-May
            $baseMonth = 1; // January
            $year = (int)$schoolYear[1]; // Use next year for 2nd sem
        }

        $dueMonth = $baseMonth + ($termOrder - 1);
        if ($dueMonth > 12) {
            $dueMonth = $dueMonth - 12;
            $year++;
        }

        return Carbon::create($year, $dueMonth, 15)->format('Y-m-d');
    }

    /**
     * Get random payment channel
     */
    private function getRandomPaymentChannel(): string
    {
        $channels = ['GCash', 'PayMaya', 'BanePayt', 'Dana', 'Cash', 'Check'];
        return $channels[array_rand($channels)];
    }

    /**
     * Display summary
     */
    private function displaySummary(Student $student): void
    {
        $this->command->info("\n" . str_repeat("=", 70));
        $this->command->info("✅ TRANSACTION HISTORY SEEDER COMPLETED");
        $this->command->info(str_repeat("=", 70));
        $this->command->info("Student Details:");
        $this->command->info("  📧 Email: " . self::EMAIL);
        $this->command->info("  🔑 Password: password");
        $this->command->info("  👤 Name: {$student->first_name} {$student->last_name}");
        $this->command->info("  📚 Student ID: {$student->student_id}");
        $this->command->info("  📖 Year Level: {$student->year_level}");
        $this->command->info("  📚 Course: " . self::COURSE);
        $this->command->newLine();

        $this->command->info("Transaction History:");
        $this->command->info("  ✓ 1st Year, 1st Semester: ₱" . number_format(self::TOTAL_ASSESSMENT_PER_TERM, 2) . " (ALL PAID)");
        $this->command->info("  ✓ 1st Year, 2nd Semester: ₱" . number_format(self::TOTAL_ASSESSMENT_PER_TERM, 2) . " (ALL PAID)");
        $this->command->info("  ✓ 2nd Year, 1st Semester: ₱" . number_format(self::TOTAL_ASSESSMENT_PER_TERM, 2) . " (ALL PAID)");
        $this->command->info("  ✓ 2nd Year, 2nd Semester: ₱" . number_format(self::TOTAL_ASSESSMENT_PER_TERM, 2) . " (ALL PAID)");
        $this->command->info("  ✓ 3rd Year, 1st Semester: ₱" . number_format(self::TOTAL_ASSESSMENT_PER_TERM, 2) . " (ALL PAID)");
        $this->command->info("  ⏳ 3rd Year, 2nd Semester: ₱" . number_format(self::TOTAL_ASSESSMENT_PER_TERM, 2) . " (NOT PAID - CURRENT)");
        $this->command->newLine();

        $this->command->info("Expandable Sections: 6 (in Transactions page, newest first)");
        $this->command->info("  1. 3rd Year, 2nd Sem - 5 unpaid payment terms (ready for manual payment)");
        $this->command->info("  2. 3rd Year, 1st Sem - 5 paid payment terms");
        $this->command->info("  3. 2nd Year, 2nd Sem - 5 paid payment terms");
        $this->command->info("  4. 2nd Year, 1st Sem - 5 paid payment terms");
        $this->command->info("  5. 1st Year, 2nd Sem - 5 paid payment terms");
        $this->command->info("  6. 1st Year, 1st Sem - 5 paid payment terms");
        $this->command->newLine();

        $this->command->info("Features Enabled:");
        $this->command->info("  ✓ Full transaction history visible");
        $this->command->info("  ✓ Receipt download for each term");
        $this->command->info("  ✓ Manual payment testing on current term");
        $this->command->info("  ✓ Payment carryover system ready");
        $this->command->info(str_repeat("=", 70) . "\n");
    }
}
