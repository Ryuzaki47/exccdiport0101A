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
 * Each assessment uses a SINGLE flat "Tuition Fee" line equal to the
 * course-specific total for that year level and semester.
 *
 * "Academic" category removed — the single fee uses category "Tuition".
 *
 * Payment terms: 5 per assessment
 *   Upon Registration 42.15% | Prelim 17.86% | Midterm 17.86%
 *   Semi-Final 14.88% | Final 7.25%
 */
class ComprehensiveAssessmentSeeder extends Seeder
{
    use GetAdminUserTrait;

    private string $schoolYear = '2025-2026';

    // ─────────────────────────────────────────────────────────────────────────
    // Course-specific flat tuition totals per year level × semester
    // shape: course → yearLevel → semester → total amount (float)
    //
    // BSEET amounts provided:  1Y1S=18,400 | 1Y2S=16,000 | 2Y1S=17,600
    // Remaining marked TODO — update these with real values.
    // ─────────────────────────────────────────────────────────────────────────
    private array $courseTotals = [
        'Associate in Computer Technology - Multimedia/Animation' => [
            '1st Year' => ['2nd Sem' => 17220.00],
            '2nd Year' => ['2nd Sem' => 14108.00],
        ],
        'Associate in Computer Technology - Networking' => [
            '1st Year' => ['2nd Sem' => 17220.00],
            '2nd Year' => ['2nd Sem' => 16492.00],
        ],
        'Associate in Computer Technology - Programming' => [
            '1st Year' => ['2nd Sem' => 17220.00],
            '2nd Year' => ['2nd Sem' => 16492.00],
        ],
        'BET Electrical Engineering Technology' => [
            '1st Year' => ['2nd Sem' => 12088.00],
        ],
        'BET Electronics Engineering Technology' => [
            '1st Year' => ['2nd Sem' => 17584.00],
        ],
        'BS Computer Science' => [
            '1st Year' => ['2nd Sem' => 17220.00],
            '2nd Year' => ['2nd Sem' => 14836.00],
            '3rd Year' => ['2nd Sem' => 14836.00],
        ],
        'BS Information Systems' => [
            '1st Year' => ['2nd Sem' => 17220.00],
            '2nd Year' => ['2nd Sem' => 12452.00],
            '3rd Year' => ['2nd Sem' => 12452.00],
        ],
        'BS Information Technology' => [
            '1st Year' => ['2nd Sem' => 16856.00],
            '2nd Year' => ['2nd Sem' => 16856.00],
            '3rd Year' => ['2nd Sem' => 12452.00],
        ],
        'Diploma in Electronics and Computer Technology' => [
            '1st Year' => ['2nd Sem' => 19240.00],
        ],
        'Diploma in Software Development and Programming' => [
            '1st Year' => ['2nd Sem' => 19240.00],
        ],
    ];

    /**
     * Fallback used when a student's course has no entry in $courseTotals.
     */
    private array $fallbackTotals = [
        '1st Year' => ['1st Sem' => 17000.00, '2nd Sem' => 15500.00],
        '2nd Year' => ['1st Sem' => 17500.00, '2nd Sem' => 16000.00],
        '3rd Year' => ['1st Sem' => 18500.00, '2nd Sem' => 17000.00],
        '4th Year' => ['1st Sem' => 19500.00, '2nd Sem' => 18500.00],
    ];

    private array $termDefinitions = [
        1 => ['name' => 'Upon Registration', 'percentage' => 42.15],
        2 => ['name' => 'Prelim',            'percentage' => 17.86],
        3 => ['name' => 'Midterm',           'percentage' => 17.86],
        4 => ['name' => 'Semi-Final',        'percentage' => 14.88],
        5 => ['name' => 'Final',             'percentage' =>  7.25],
    ];

    // ─────────────────────────────────────────────────────────────────────────

    public function run(): void
    {
        $adminId = $this->getOrFindAdminUserId();

        $this->command->info('🗑️  Clearing existing assessments, payment terms, charge transactions…');
        $studentIds = User::where('role', 'student')->pluck('id');
        
        // Delete payment terms through their assessment relationship
        StudentPaymentTerm::whereIn(
            'student_assessment_id',
            StudentAssessment::whereIn('user_id', $studentIds)->pluck('id')
        )->delete();
        
        StudentAssessment::whereIn('user_id', $studentIds)->delete();
        Transaction::whereIn('user_id', $studentIds)->where('kind', 'charge')->delete();
        $this->command->info('✓ Cleared.');
        $this->command->newLine();

        // Seed fees table with one row per course × year × semester
        $this->command->info('💰 Seeding Fees table (single flat Tuition Fee per course/year/sem)…');
        Fee::query()->delete();
        $this->seedFeesTable();
        $this->command->info('✓ Fees seeded: ' . Fee::count() . ' records.');
        $this->command->newLine();

        $students  = User::where('role', 'student')
            ->where('status', User::STATUS_ACTIVE)
            ->whereNotNull('year_level')
            ->get();
        $semesters = ['1st Sem', '2nd Sem'];

        $this->command->info("📋 Creating assessments for {$students->count()} students (excluding graduated)…");
        $created = 0;
        $skipped = 0;

        DB::transaction(function () use ($students, $semesters, $adminId, &$created, &$skipped) {
            foreach ($students as $student) {
                if (empty($student->year_level)) { $skipped++; continue; }

                $totals = $this->courseTotals[$student->course ?? ''] ?? $this->fallbackTotals;

                foreach ($semesters as $semester) {
                    $amount = $totals[$student->year_level][$semester] ?? null;
                    if ($amount === null) { $skipped++; continue; }

                    $this->createStudentAssessment($student, $semester, $adminId, (float) $amount);
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
                ['Fee Records',         Fee::count()],
                ['Assessments',         StudentAssessment::count()],
                ['Payment Terms',       StudentPaymentTerm::count()],
                ['Charge Transactions', Transaction::whereIn('user_id', $studentIds)
                                            ->where('kind', 'charge')->count()],
            ]
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Seed fees table — one flat "Tuition Fee" row per course × year × semester
    // ─────────────────────────────────────────────────────────────────────────

    private function seedFeesTable(): void
    {
        $allCourses = array_merge(
            $this->courseTotals,
            ['_fallback' => $this->fallbackTotals]
        );

        foreach ($this->courseTotals as $course => $yearLevels) {
            foreach ($yearLevels as $yearLevel => $semesters) {
                foreach ($semesters as $semester => $amount) {
                    $courseSlug = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $course), 0, 4));
                    $yrNum      = preg_replace('/[^0-9]/', '', $yearLevel);
                    $semNum     = preg_replace('/[^0-9]/', '', $semester);
                    $code       = "TUI-{$courseSlug}-Y{$yrNum}S{$semNum}";

                    Fee::firstOrCreate(['code' => $code], [
                        'name'        => 'Tuition Fee',
                        'category'    => 'Tuition',
                        'amount'      => $amount,
                        'year_level'  => $yearLevel,
                        'semester'    => $semester,
                        'school_year' => $this->schoolYear,
                        'description' => "Tuition Fee — {$yearLevel} {$semester} ({$course})",
                        'is_active'   => true,
                    ]);
                }
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Create one full assessment for a student + semester
    // ─────────────────────────────────────────────────────────────────────────

    private function createStudentAssessment(
        User   $student,
        string $semester,
        int    $adminId,
        float  $tuitionTotal
    ): void {
        $yearLevel    = $student->year_level;
        $grandTotal   = round($tuitionTotal, 2);

        // Calculate units based on year level
        // 1st year: 48 LEC, 4 LAB | 2nd year: 48 LEC, 3 LAB | 3rd year: 45 LEC, 2 LAB | 4th year: 42 LEC, 2 LAB
        $unitMap = [
            '1st Year' => ['lec' => 48, 'lab' => 4],
            '2nd Year' => ['lec' => 48, 'lab' => 3],
            '3rd Year' => ['lec' => 45, 'lab' => 2],
            '4th Year' => ['lec' => 42, 'lab' => 2],
        ];
        $units = $unitMap[$yearLevel] ?? ['lec' => 48, 'lab' => 3];

        $assessment = StudentAssessment::create([
            'user_id'           => $student->id,
            'assessment_number' => StudentAssessment::generateAssessmentNumber(),
            'year_level'        => $yearLevel,
            'semester'          => $semester,
            'school_year'       => $this->schoolYear,
            'lec_units'         => $units['lec'],
            'lab_units'         => $units['lab'],
            'total_assessment'  => $grandTotal,
            'status'            => 'active',
        ]);

        // DISABLED: Do not create charge transactions during seeding
        // Charges should only be created when admin explicitly creates an assessment
        // via the StudentFeeController::store() endpoint, not automatically during seed.
        /*
        // Single charge transaction per assessment
        $yearNum = (int) explode('-', $this->schoolYear)[0];
        Transaction::create([
            'user_id'   => $student->id,
            'reference' => 'ASMT-' . strtoupper(Str::random(8)),
            'kind'      => 'charge',
            'type'      => 'Tuition',
            'year'      => $yearNum,
            'semester'  => $semester,
            'amount'    => $grandTotal,
            'status'    => 'pending',
            'meta'      => [
                'assessment_id'   => $assessment->id,
                'assessment_type' => 'regular',
                'description'     => "Tuition Fee — {$yearLevel} {$semester} {$this->schoolYear}",
            ],
        ]);
        */

        // 5 payment terms
        $semStart = ($semester === '1st Sem')
            ? Carbon::create(2025, 8, 1)
            : Carbon::create(2026, 1, 5);

        $dueDates = [
            1 => $semStart->copy(),
            2 => $semStart->copy()->addWeeks(6),
            3 => $semStart->copy()->addWeeks(12),
            4 => $semStart->copy()->addWeeks(16),
            5 => $semStart->copy()->addWeeks(19),
        ];

        $allocated = 0.00;
        foreach ($this->termDefinitions as $order => $term) {
            $isLast = ($order === 5);
            $amount = $isLast
                ? round($grandTotal - $allocated, 2)
                : round(($term['percentage'] / 100) * $grandTotal, 2);

            if (!$isLast) $allocated += $amount;

            StudentPaymentTerm::create([
                'student_assessment_id'  => $assessment->id,
                'term_name'              => $term['name'],
                'term_order'             => $order,
                'percentage'             => $term['percentage'],
                'amount'                 => $amount,
                'balance'                => $amount,
                'due_date'               => $dueDates[$order]->toDateString(),
                'status'                 => StudentPaymentTerm::STATUS_PENDING,
                'remarks'                => null,
                'paid_date'              => null,
                'carryover_from_term_id' => null,
                'carryover_amount'       => 0.00,
            ]);
        }
    }
}