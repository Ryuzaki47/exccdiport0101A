<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * ComprehensiveUserSeeder
 *
 * Seeds exactly 100 students + 1 admin + 1 accounting staff.
 *
 * Distribution:
 *   - 40 Active    → 1st Year
 *   - 30 Active    → 2nd Year
 *   - 10 Dropped   → 2nd Year  (status=dropped, enrollment_status=inactive)
 *   - 10 Active    → 4th Year  (with remaining balance)
 *   - 10 Graduated → 4th Year  (fully paid, balance=0)
 *
 * Discount overrides (resolved in ComprehensiveAssessmentSeeder):
 *   student1@ccdi.edu.ph  → Maria Santos  → discount_type = 'full'
 *   student2@ccdi.edu.ph  → Ana Garcia    → discount_type = 'nstp'
 *   student3–100          →               → discount_type = 'none'
 */
class ComprehensiveUserSeeder extends Seeder
{
    private int $accountNumberCounter = 0;

    private array $lastNames = [
        'Dela Cruz', 'Santos', 'Reyes', 'Garcia', 'Ramos',
        'Mendoza', 'Torres', 'Flores', 'Gonzales', 'Castro',
        'Rivera', 'Bautista', 'Santiago', 'Fernandez', 'Lopez',
        'Morales', 'Aquino', 'Villanueva', 'Cruz', 'Jimenez',
        'Martinez', 'Rodriguez', 'Hernandez', 'Perez', 'Gomez',
    ];

    private array $maleFirstNames = [
        'Juan', 'Jose', 'Pedro', 'Miguel', 'Carlos',
        'Antonio', 'Manuel', 'Francisco', 'Rafael', 'Eduardo',
        'Ricardo', 'Fernando', 'Roberto', 'Andres', 'Javier',
        'Rommel', 'Angelo', 'Danilo', 'Rodel', 'Marvin',
    ];

    private array $femaleFirstNames = [
        'Carmen', 'Rosa', 'Teresa', 'Elena', 'Isabel',
        'Lucia', 'Sofia', 'Patricia', 'Angela', 'Monica',
        'Gloria', 'Diana', 'Cristina', 'Rowena', 'Lourdes',
        'Jennelyn', 'Maribel', 'Charisma', 'Lovely', 'Maricel',
    ];

    private array $middleInitials = [
        'A', 'B', 'C', 'D', 'E', 'F', 'G',
        'H', 'J', 'K', 'L', 'M', 'N', 'P',
        'R', 'S', 'T', 'V',
    ];

    private array $addresses = [
        'Sorsogon City', 'Legazpi City', 'Naga City', 'Daet',
        'Iriga City', 'Tabaco City', 'Ligao City', 'Polangui',
        'Daraga', 'Camalig', 'Bulan', 'Irosin', 'Gubat',
    ];

    // Cycling list — guarantees every student has a non-null course
    private array $courses = [
        'BET Electronics Engineering Technology',
        'BET Electrical Engineering Technology',
        'BS Information Technology',
        'BS Information Systems',
        'BS Computer Science',
        'Associate in Computer Technology - Networking',
        'Associate in Computer Technology - Programming',
        'Associate in Computer Technology - Multimedia/Animation',
        'Diploma in Software Development and Programming',
        'Diploma in Electronics and Computer Technology',
    ];

    // =========================================================================

    public function run(): void
    {
        // Wipe previous student batch; preserve admin and accounting
        Student::whereHas('user', fn ($q) => $q->where('role', 'student'))->delete();
        User::where('role', 'student')->delete();

        // Admin
        $admin = User::firstOrCreate(
            ['email' => 'admin@ccdi.edu.ph'],
            [
                'last_name'      => 'Rodriguez',
                'first_name'     => 'Carlos',
                'middle_initial' => 'M',
                'password'       => Hash::make('password'),
                'role'           => 'admin',
                'status'         => User::STATUS_ACTIVE,
                'faculty'        => 'Administration',
                'phone'          => '09171234501',
                'address'        => 'Sorsogon City',
                'birthday'       => '1985-05-15',
            ]
        );
        $admin->account()->firstOrCreate([], ['balance' => 0]);

        // Accounting
        $accounting = User::firstOrCreate(
            ['email' => 'accounting@ccdi.edu.ph'],
            [
                'last_name'      => 'Garcia',
                'first_name'     => 'Ana Marie',
                'middle_initial' => 'S',
                'password'       => Hash::make('password'),
                'role'           => 'accounting',
                'status'         => User::STATUS_ACTIVE,
                'faculty'        => 'Accounting Department',
                'phone'          => '09181234502',
                'address'        => 'Legazpi City',
                'birthday'       => '1990-08-20',
            ]
        );
        $accounting->account()->firstOrCreate([], ['balance' => 0]);

        // ── Slots 0–1: discount students (fixed — never shuffled) ───────────────
        // Built first so the remaining 98 maintain their exact intended distribution.
        // student1 → Maria Santos (full discount)
        // student2 → Ana Garcia   (nstp discount)
        $blueprint = [
            ['year_level' => '1st Year', 'status' => 'active', 'balance' => 0],
            ['year_level' => '1st Year', 'status' => 'active', 'balance' => 0],
        ];

        // ── Slots 2–99: exactly 98 students with the correct distribution ───────
        // 38 more 1st Year  (+2 above = 40 total 1st Year)
        // 30 Active  2nd Year
        // 10 Dropped 2nd Year  (= 40 total 2nd Year)
        // 10 Active  4th Year
        // 10 Graduated 4th Year (= 20 total 4th Year)
        $pool = [];
        for ($i = 0; $i < 38; $i++) {
            $pool[] = ['year_level' => '1st Year', 'status' => 'active',    'balance' => rand(5000, 15000)];
        }
        for ($i = 0; $i < 30; $i++) {
            $pool[] = ['year_level' => '2nd Year', 'status' => 'active',    'balance' => rand(3000, 12000)];
        }
        for ($i = 0; $i < 10; $i++) {
            $pool[] = ['year_level' => '2nd Year', 'status' => 'dropped',   'balance' => rand(5000, 20000)];
        }
        for ($i = 0; $i < 10; $i++) {
            $pool[] = ['year_level' => '4th Year', 'status' => 'active',    'balance' => rand(1000, 5000)];
        }
        for ($i = 0; $i < 10; $i++) {
            $pool[] = ['year_level' => '4th Year', 'status' => 'graduated', 'balance' => 0];
        }
        shuffle($pool);

        // Discount students locked at front; randomized pool appended after
        $blueprint = array_merge($blueprint, $pool);

        $userStatusMap = [
            'active'    => User::STATUS_ACTIVE,
            'dropped'   => User::STATUS_DROPPED,
            'graduated' => User::STATUS_GRADUATED,
        ];

        $enrollmentStatusMap = [
            'active'    => 'enrolled',
            'dropped'   => 'inactive',
            'graduated' => 'graduated',
        ];

        foreach ($blueprint as $index => $slot) {
            $studentNumber = $index + 1;
            $studentId     = '2025-' . str_pad($studentNumber, 4, '0', STR_PAD_LEFT);
            $email         = "student{$studentNumber}@ccdi.edu.ph";

            // Named discount students
            if ($studentNumber === 1) {
                $firstName = 'Maria';
                $lastName  = 'Santos';
            } elseif ($studentNumber === 2) {
                $firstName = 'Ana';
                $lastName  = 'Garcia';
            } else {
                $isFemale  = ($studentNumber % 2 === 0);
                $firstName = $isFemale
                    ? $this->femaleFirstNames[array_rand($this->femaleFirstNames)]
                    : $this->maleFirstNames[array_rand($this->maleFirstNames)];
                $lastName  = $this->lastNames[array_rand($this->lastNames)];
            }

            $middleInitial = $this->middleInitials[array_rand($this->middleInitials)];
            $address       = $this->addresses[array_rand($this->addresses)];

            // Cycle through courses so no student is ever left with null
            $course = $this->courses[$index % count($this->courses)];

            $yearLevelNum = (int) substr($slot['year_level'], 0, 1);
            $birthYear    = 2025 - 18 - ($yearLevelNum - 1);
            $birthday     = $birthYear
                . '-' . str_pad(rand(1, 12), 2, '0', STR_PAD_LEFT)
                . '-' . str_pad(rand(1, 28), 2, '0', STR_PAD_LEFT);

            $user = User::create([
                'last_name'      => $lastName,
                'first_name'     => $firstName,
                'middle_initial' => $middleInitial,
                'email'          => $email,
                'password'       => Hash::make('password'),
                'role'           => 'student',
                'account_id'     => $studentId,
                'status'         => $userStatusMap[$slot['status']],
                'course'         => $course,
                'year_level'     => $slot['year_level'],
                'birthday'       => $birthday,
                'phone'          => '0917' . rand(1000000, 9999999),
                'address'        => $address,
            ]);

            // Negative balance = money owed
            $user->account()->create([
                'account_number' => $this->nextAccountNumber(),
                'balance'        => -$slot['balance'],
            ]);

            Student::create([
                'user_id'           => $user->id,
                'student_id'        => $studentId,
                'enrollment_status' => $enrollmentStatusMap[$slot['status']],
            ]);
        }

        $this->command->info('✓ 100 students seeded.');
        $this->command->info('  student1 → Maria Santos  (full discount)');
        $this->command->info('  student2 → Ana Garcia    (nstp discount)');
        $this->command->info('  student3–100 → regular   (no discount)');
        $this->command->info('  All passwords: password');
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
}