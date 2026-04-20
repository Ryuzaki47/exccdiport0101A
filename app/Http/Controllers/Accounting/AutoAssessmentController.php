<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\StudentAssessment;
use App\Models\Subject;
use App\Models\Transaction;
use App\Models\User;
use App\Enums\UserRoleEnum;
use App\Services\AccountService;
use App\Services\AssessmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * AutoAssessmentController
 *
 * Bulk assessment generation for accounting staff.
 *
 * Workflow:
 *   1. Accounting selects: course + year_level + semester + school_year
 *   2. POST /preview → returns a list of students who:
 *        - Match the course/year_level
 *        - Are regular students
 *        - Do NOT already have an active assessment for this semester/school_year
 *        - Their curriculum has subjects in the subjects table
 *   3. POST /generate → creates assessments for all previewed students atomically
 *
 * Only regular students get auto-generated assessments.
 * Irregular students must be assessed manually (they have custom unit loads).
 */
class AutoAssessmentController extends Controller
{
    // ─── Index ────────────────────────────────────────────────────────────────

    public function index(): Response
    {
        // Distinct courses from the subjects table (the curriculum source of truth)
        $availableCourses = Subject::where('is_active', true)
            ->distinct()
            ->orderBy('course')
            ->pluck('course')
            ->values();

        // Current rates snapshot for the form
        $feeRates = AssessmentService::feeRatesForForm();

        return Inertia::render('Accounting/AutoAssess', [
            'availableCourses' => $availableCourses,
            'feeRates'         => $feeRates,
            'currentSchoolYear' => $this->currentSchoolYear(),
        ]);
    }

    // ─── Preview ──────────────────────────────────────────────────────────────

    /**
     * Preview which students will have assessments auto-generated.
     * Returns a dry-run result without writing anything.
     */
    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'course'      => ['required', 'string', 'max:255'],
            'year_level'  => ['required', 'string', 'in:1st Year,2nd Year,3rd Year,4th Year,5th Year'],
            'semester'    => ['required', 'string', 'in:1st,2nd,Summer'],
            'school_year' => ['required', 'string', 'max:20', 'regex:/^\d{4}-\d{4}$/'],
        ]);

        // Check curriculum exists for this combination
        $curriculum = AssessmentService::getCurriculumUnits(
            $validated['course'],
            $validated['year_level'],
            $validated['semester']
        );

        if (empty($curriculum['subjects'])) {
            return response()->json([
                'ok'      => false,
                'message' => "No curriculum found for {$validated['course']} — {$validated['year_level']} — {$validated['semester']} Sem. Cannot auto-generate.",
                'students' => [],
            ]);
        }

        // Find eligible students
        $students = User::where('role', UserRoleEnum::STUDENT)
            ->where('is_active', true)
            ->where('is_irregular', false)
            ->where('course', $validated['course'])
            ->where('year_level', $validated['year_level'])
            ->get();

        // Load rates once
        $rates = AssessmentService::loadRates();

        $preview = [];

        foreach ($students as $student) {
            $hasActive = StudentAssessment::where('user_id', $student->id)
                ->where('semester', $validated['semester'])
                ->where('school_year', $validated['school_year'])
                ->where('status', 'active')
                ->exists();

            if ($hasActive) {
                $preview[] = [
                    'id'         => $student->id,
                    'name'       => $this->buildName($student),
                    'account_id' => $student->account_id,
                    'skip'       => true,
                    'skip_reason' => 'Already has an active assessment for this term',
                    'fees'       => null,
                ];
                continue;
            }

            // Compute fees for this student (no discount by default in bulk)
            $fees = AssessmentService::compute(
                lecUnits:    $curriculum['billable_lec_units'],
                labSubjects: $curriculum['lab_subject_count'],
                rates:       $rates,
            );

            $preview[] = [
                'id'          => $student->id,
                'name'        => $this->buildName($student),
                'account_id'  => $student->account_id,
                'skip'        => false,
                'skip_reason' => null,
                'fees'        => [
                    'tuition_fee'  => $fees['tuition_fee'],
                    'lab_fee'      => $fees['lab_fee'],
                    'misc_fee'     => $fees['misc_fee'],
                    'total'        => $fees['total'],
                ],
            ];
        }

        $eligible = collect($preview)->where('skip', false)->count();
        $skipped  = collect($preview)->where('skip', true)->count();

        return response()->json([
            'ok'         => true,
            'message'    => null,
            'curriculum' => [
                'billable_lec_units' => $curriculum['billable_lec_units'],
                'lab_subject_count'  => $curriculum['lab_subject_count'],
                'nstp_units'         => $curriculum['nstp_units'],
                'pathfit_units'      => $curriculum['pathfit_units'],
                'subject_count'      => count($curriculum['subjects']),
            ],
            'summary' => [
                'total'    => count($preview),
                'eligible' => $eligible,
                'skipped'  => $skipped,
            ],
            'fees_preview' => $eligible > 0
                ? $preview[collect($preview)->search(fn ($p) => ! $p['skip'])]['fees']
                : null,
            'rates'    => [
                'tuition_per_unit'    => $rates['tuition_per_unit'],
                'lab_fee_per_subject' => $rates['lab_fee_per_subject'],
                'misc_total'          => $rates['misc_total'],
            ],
            'students' => $preview,
        ]);
    }

    // ─── Generate ─────────────────────────────────────────────────────────────

    /**
     * Create assessments for all eligible students in this cohort.
     * Skips students who already have an active assessment for the term.
     * Runs in a single transaction — all succeed or all roll back.
     */
    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'course'      => ['required', 'string', 'max:255'],
            'year_level'  => ['required', 'string', 'in:1st Year,2nd Year,3rd Year,4th Year,5th Year'],
            'semester'    => ['required', 'string', 'in:1st,2nd,Summer'],
            'school_year' => ['required', 'string', 'max:20', 'regex:/^\d{4}-\d{4}$/'],
            // Optional: override discount for the whole batch (default: none)
            'discount_type' => ['nullable', 'string', 'in:none,full,nstp'],
        ]);

        $discountType = $validated['discount_type'] ?? 'none';

        $curriculum = AssessmentService::getCurriculumUnits(
            $validated['course'],
            $validated['year_level'],
            $validated['semester']
        );

        if (empty($curriculum['subjects'])) {
            return response()->json([
                'ok'      => false,
                'message' => "No curriculum found for this cohort. Cannot generate.",
                'created' => 0,
                'skipped' => 0,
            ], 422);
        }

        $students = User::where('role', UserRoleEnum::STUDENT)
            ->where('is_active', true)
            ->where('is_irregular', false)
            ->where('course', $validated['course'])
            ->where('year_level', $validated['year_level'])
            ->get();

        if ($students->isEmpty()) {
            return response()->json([
                'ok'      => false,
                'message' => "No active regular students found for {$validated['course']} — {$validated['year_level']}.",
                'created' => 0,
                'skipped' => 0,
            ], 422);
        }

        $rates   = AssessmentService::loadRates();
        $created = 0;
        $skipped = 0;
        $errors  = [];

        DB::transaction(function () use (
            $students, $validated, $curriculum, $rates, $discountType,
            &$created, &$skipped, &$errors
        ) {
            foreach ($students as $student) {
                try {
                    // Lock + check for existing assessment
                    $existing = StudentAssessment::where('user_id', $student->id)
                        ->where('semester', $validated['semester'])
                        ->where('school_year', $validated['school_year'])
                        ->where('status', 'active')
                        ->lockForUpdate()
                        ->first();

                    if ($existing) {
                        $skipped++;
                        continue;
                    }

                    // Archive any previous active assessment
                    StudentAssessment::where('user_id', $student->id)
                        ->where('status', 'active')
                        ->update(['status' => 'completed']);

                    // Compute fees
                    $fees = AssessmentService::compute(
                        lecUnits:    $curriculum['billable_lec_units'],
                        labSubjects: $curriculum['lab_subject_count'],
                        discountType: $discountType,
                        rates:       $rates,
                    );

                    $assessmentNumber = StudentAssessment::generateAssessmentNumber();

                    $assessment = StudentAssessment::create([
                        'assessment_number'  => $assessmentNumber,
                        'user_id'            => $student->id,
                        'course'             => $student->course,
                        'year_level'         => $student->year_level,
                        'semester'           => $validated['semester'],
                        'school_year'        => $validated['school_year'],
                        'lec_units'          => $curriculum['billable_lec_units'],
                        'lab_units'          => $curriculum['lab_subject_count'],
                        'lab_subjects'       => $curriculum['lab_subject_count'],
                        'discount_type'      => $discountType,
                        'discount_percentage' => 0.00,
                        'is_taking_nstp'     => false,
                        'tuition_fee'        => $fees['tuition_fee'],
                        'lab_fee'            => $fees['lab_fee'],
                        'misc_fee'           => $fees['misc_fee'],
                        'total_assessment'   => $fees['total'],
                        'status'             => 'active',
                    ]);

                    // Build payment terms
                    foreach (AssessmentService::buildPaymentTerms($fees['total'], $rates) as $term) {
                        $assessment->paymentTerms()->create($term);
                    }

                    // Audit transaction
                    Transaction::create([
                        'user_id'         => $student->id,
                        'kind'            => 'charge',
                        'status'          => 'paid',
                        'amount'          => $fees['total'],
                        'reference'       => 'ASMT-' . strtoupper(Str::random(8)),
                        'payment_channel' => 'assessment',
                        'year'            => (int) explode('-', $validated['school_year'])[0],
                        'semester'        => $validated['semester'],
                        'meta'            => json_encode([
                            'source'             => 'auto_assessment',
                            'course'             => $student->course,
                            'year_level'         => $student->year_level,
                            'lec_units'          => $curriculum['billable_lec_units'],
                            'lab_subjects'       => $curriculum['lab_subject_count'],
                            'discount_type'      => $discountType,
                            'tuition_fee'        => $fees['tuition_fee'],
                            'lab_fee'            => $fees['lab_fee'],
                            'misc_fee'           => $fees['misc_fee'],
                            'school_year'        => $validated['school_year'],
                            'discount_applied'   => $fees['discount_applied'],
                        ]),
                    ]);

                    AccountService::recalculate($student);
                    $created++;
                } catch (\Throwable $e) {
                    Log::error('AutoAssessment: failed for student', [
                        'student_id' => $student->id,
                        'error'      => $e->getMessage(),
                    ]);
                    // Re-throw to roll back the entire transaction
                    throw $e;
                }
            }
        });

        return response()->json([
            'ok'      => true,
            'message' => "Auto-assessment complete.",
            'created' => $created,
            'skipped' => $skipped,
        ]);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function buildName(User $user): string
    {
        $mi = $user->middle_initial ? ' ' . strtoupper($user->middle_initial) . '.' : '';
        return $user->last_name . ', ' . $user->first_name . $mi;
    }

    private function currentSchoolYear(): string
    {
        $year = (int) date('Y');
        $month = (int) date('n');
        // If we're before June, still in the previous school year's 2nd sem
        return $month >= 6 ? "{$year}-" . ($year + 1) : ($year - 1) . "-{$year}";
    }
}