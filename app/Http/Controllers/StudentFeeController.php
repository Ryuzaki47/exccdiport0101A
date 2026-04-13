<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\StudentAssessment;
use App\Models\StudentPaymentTerm;
use App\Models\Transaction;
use App\Models\User;
use App\Enums\UserRoleEnum;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class StudentFeeController extends Controller
{
    // ─────────────────────────────────────────────────────────────
    //  HELPERS
    // ─────────────────────────────────────────────────────────────

    /**
     * Compute the total assessment from raw unit inputs.
     *
     * Formula (AY 2025-2026):
     *   Tuition  = lec_units × ₱364
     *   Lab Fee  = lab_units × ₱1,656
     *   Misc Fee = ₱4,700 (fixed)
     *   Total    = tuition + lab_fee + misc_fee
     */
    private function computeTotal(int $lecUnits, int $labUnits): array
    {
        $tuitionPerUnit = (float) config('fees.tuition_per_lec_unit', 364.00);
        $labFeePerUnit  = (float) config('fees.lab_fee_per_unit', 1656.00);
        $miscFeeFixed   = (float) config('fees.misc_fee_fixed', 4700.00);

        $tuitionFee = $lecUnits * $tuitionPerUnit;
        $labFee     = $labUnits * $labFeePerUnit;
        $miscFee    = $miscFeeFixed;
        $total      = $tuitionFee + $labFee + $miscFee;

        return compact('tuitionFee', 'labFee', 'miscFee', 'total');
    }

    /**
     * Build payment terms from config percentages and a total amount.
     * Returns an array ready to insert into student_payment_terms.
     */
    private function buildPaymentTerms(float $total): array
    {
        $termConfigs = config('fees.payment_terms', []);
        $terms       = [];

        foreach ($termConfigs as $config) {
            $amount = round($total * ($config['percentage'] / 100), 2);

            $terms[] = [
                'term_name'   => $config['term_name'],
                'term_order'  => $config['term_order'],
                'percentage'  => $config['percentage'],
                'amount'      => $amount,
                'balance'     => $amount,   // balance = unpaid portion (source of truth)
                'status'      => 'unpaid',
                'due_date'    => null,
                'paid_date'   => null,
                'created_at'  => now(),
                'updated_at'  => now(),
            ];
        }

        return $terms;
    }

    // ─────────────────────────────────────────────────────────────
    //  INDEX — list all assessed students
    // ─────────────────────────────────────────────────────────────

    public function index(Request $request): Response
    {
        $query = User::where('role', UserRoleEnum::STUDENT)
            ->with([
                'latestAssessment.paymentTerms',
                'account',
            ]);

        // Search filter
        if ($request->filled('search')) {
            $q = $request->search;
            $query->where(function ($q2) use ($q) {
                $q2->where('last_name', 'like', "%{$q}%")
                ->orWhere('first_name', 'like', "%{$q}%")
                ->orWhere('account_id', 'like', "%{$q}%");
            });
        }

        // Course filter
        if ($request->filled('course')) {
            $query->where('course', $request->course);
        }

        // Year level filter
        if ($request->filled('year_level')) {
            $query->where('year_level', $request->year_level);
        }

        // Status filter
        if ($request->filled('status')) {
            $query->whereHas('student', fn($q) => $q->where('enrollment_status', $request->status));
        }

        $students = $query->paginate(20)->through(fn($u) => [
            'id'                => $u->id,
            'account_id'        => $u->account_id,
            'name'              => $u->last_name . ', ' . $u->first_name,
            'course'            => $u->course,
            'year_level'        => $u->year_level,
            'status'            => $u->student?->enrollment_status ?? 'pending',
            'remaining_balance' => $u->account?->balance ?? 0,
            'account'           => $u->account ? ['balance' => $u->account->balance] : null,
            'latestAssessment'  => $u->latestAssessment ? [
                'id'             => $u->latestAssessment->id,
                'total_assessment' => $u->latestAssessment->total_assessment,
                'paymentTerms'   => $u->latestAssessment->paymentTerms->map(fn($t) => [
                    'id'         => $t->id,
                    'term_name'  => $t->term_name,
                    'term_order' => $t->term_order,
                    'amount'     => $t->amount,
                    'balance'    => $t->balance,
                    'status'     => $t->status,
                    'due_date'   => $t->due_date,
                ])->values()->all(),
            ] : null,
        ]);

        // Dropdown options for filters
        $courses    = User::where('role', UserRoleEnum::STUDENT)
                        ->whereNotNull('course')
                        ->distinct()->pluck('course')->sort()->values();
        $yearLevels = User::where('role', UserRoleEnum::STUDENT)
                        ->whereNotNull('year_level')
                        ->distinct()->pluck('year_level')->sort()->values();

        return Inertia::render('StudentFees/Index', [
            'students'   => $students,
            'filters'    => $request->only(['search', 'course', 'year_level', 'status']),
            'courses'    => $courses,
            'yearLevels' => $yearLevels,
            'statuses'   => [
                'active'    => 'Active',
                'graduated' => 'Graduated',
                'suspended' => 'Suspended',
                'dropped'   => 'Dropped',
                'pending'   => 'Pending',
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    //  CREATE — show the "create assessment" form
    // ─────────────────────────────────────────────────────────────

    public function create(Request $request): Response
    {
        // Pre-load the selected student if ?student_id= is passed
        $preselectedStudent = null;
        if ($request->filled('student_id')) {
            $student = User::where('role', UserRoleEnum::STUDENT)
                ->where('id', $request->student_id)
                ->first();

            if ($student) {
                $preselectedStudent = [
                    'id'         => $student->id,
                    'name'       => $student->last_name . ', ' . $student->first_name,
                    'account_id' => $student->account_id,
                    'course'     => $student->course,
                    'year_level' => $student->year_level,
                ];
            }
        }

        // Pass fee rates to the frontend so it can compute live preview
        $feeRates = [
            'tuition_per_lec_unit' => config('fees.tuition_per_lec_unit', 364.00),
            'lab_fee_per_unit'     => config('fees.lab_fee_per_unit', 1656.00),
            'misc_fee_fixed'       => config('fees.misc_fee_fixed', 4700.00),
            'payment_terms'        => config('fees.payment_terms', []),
        ];

        return Inertia::render('StudentFees/Create', [
            'preselectedStudent' => $preselectedStudent,
            'feeRates'           => $feeRates,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    //  STORE — save the new assessment (no subjects, just units)
    // ─────────────────────────────────────────────────────────────

    public function store(Request $request)
    {

        $validated = $request->validate([
            'user_id'     => ['required', 'exists:users,id'],
            'semester'    => ['required', 'in:1st,2nd,Summer'],
            'school_year' => ['required', 'string', 'max:20'],  // e.g. "2025-2026"
            'lec_units'   => ['required', 'integer', 'min:0', 'max:30'],
            'lab_units'   => ['required', 'integer', 'min:0', 'max:10'],
        ]);

        // Ensure this student doesn't already have an active assessment for the same semester
        $existing = StudentAssessment::where('user_id', $validated['user_id'])
            ->where('semester', $validated['semester'])
            ->where('school_year', $validated['school_year'])
            ->where('status', 'active')
            ->first();

        if ($existing) {
            return back()->withErrors([
                'user_id' => 'This student already has an active assessment for ' .
                             $validated['semester'] . ' Sem, SY ' . $validated['school_year'] . '.',
            ]);
        }

        DB::transaction(function () use ($validated) {
            // 1. Archive any previous active assessment for this student
            StudentAssessment::where('user_id', $validated['user_id'])
                ->where('status', 'active')
                ->update(['status' => 'completed']);

            // 2. Compute fees
            $fees = $this->computeTotal(
                (int) $validated['lec_units'],
                (int) $validated['lab_units']
            );

            // 3. Fetch student to get year_level
            $student = \App\Models\User::findOrFail($validated['user_id']);

            // 4. Create the assessment record
            $assessment = StudentAssessment::create([
                'assessment_number' => 'ASMT-' . date('Y') . '-' . strtoupper(Str::random(6)),
                'user_id'      => $validated['user_id'],
                'semester'     => $validated['semester'],
                'school_year'  => $validated['school_year'],
                'lec_units'    => $validated['lec_units'],
                'lab_units'    => $validated['lab_units'],
                'year_level'   => $student->year_level,
                'total_assessment' => $fees['total'],
                'status'       => 'active',
            ]);

            // 5. Build and insert payment terms
            $terms = $this->buildPaymentTerms($fees['total']);

            foreach ($terms as $term) {
                $assessment->paymentTerms()->create($term);
            }

            // 6. Record a charge transaction for the ledger (ASMT- prefix = assessment debit)
            Transaction::create([
                'user_id'         => $validated['user_id'],
                'kind'            => 'charge',
                'status'          => 'paid',  // "paid" here means "posted to ledger"
                'amount'          => $fees['total'],
                'reference'       => 'ASMT-' . strtoupper(Str::random(8)),
                'payment_channel' => 'assessment',
                'year'            => now()->year,
                'semester'        => $validated['semester'],
                'meta'            => json_encode([
                    'lec_units'   => $validated['lec_units'],
                    'lab_units'   => $validated['lab_units'],
                    'tuition_fee' => $fees['tuitionFee'],
                    'lab_fee'     => $fees['labFee'],
                    'misc_fee'    => $fees['miscFee'],
                    'school_year' => $validated['school_year'],
                ]),
            ]);
        });

        return redirect()
            ->route('student-fees.show', $validated['user_id'])
            ->with('success', 'Assessment created successfully.');
    }

    // ─────────────────────────────────────────────────────────────
    //  SHOW — view a student's current assessment & payment terms
    // ─────────────────────────────────────────────────────────────

    public function show(int $userId): Response
    {

        $user = User::with([
            'latestAssessment.paymentTerms',
        ])->findOrFail($userId);

        $assessment = $user->latestAssessment;

        $feeBreakdown = null;
        if ($assessment) {
            $feeBreakdown = $this->computeTotal(
                $assessment->lec_units,
                $assessment->lab_units
            );
        }

        return Inertia::render('StudentFees/Show', [
            'student'      => [
                'id'         => $user->id,
                'name'       => $user->last_name . ', ' . $user->first_name,
                'account_id' => $user->account_id,
                'course'     => $user->course,
                'year_level' => $user->year_level,
                'avatar'     => $user->avatar,
            ],
            'assessment'   => $assessment ? [
                'id'           => $assessment->id,
                'course'       => $user->course,
                'semester'     => $assessment->semester,
                'school_year'  => $assessment->school_year,
                'year_level'   => $user->year_level,
                'total_assessment' => (float) $assessment->total_assessment,
                'tuition_fee'  => $feeBreakdown['tuitionFee'] ?? 0,
                'other_fees'   => ($feeBreakdown['labFee'] ?? 0) + ($feeBreakdown['miscFee'] ?? 0),
                'fee_breakdown' => [
                    [
                        'category' => 'Tuition',
                        'name'     => 'Lecture Units',
                        'code'     => 'TUI',
                        'units'    => $assessment->lec_units,
                        'amount'   => $feeBreakdown['tuitionFee'] ?? 0,
                    ],
                    [
                        'category' => 'Laboratory',
                        'name'     => 'Laboratory Units',
                        'code'     => 'LAB',
                        'units'    => $assessment->lab_units,
                        'amount'   => $feeBreakdown['labFee'] ?? 0,
                    ],
                    [
                        'category' => 'Miscellaneous',
                        'name'     => 'Registration Fee',
                        'code'     => 'REG',
                        'units'    => 1,
                        'amount'   => $feeBreakdown['miscFee'] ?? 0,
                    ],
                ],
                'status'       => $assessment->status,
                'paymentTerms' => $assessment->paymentTerms->sortBy('term_order')->values(),
            ] : null,
            'allAssessments' => [],
            'transactions' => [],
            'payments' => [],
            'feeBreakdown' => [
                [
                    'category' => 'Tuition',
                    'total' => $feeBreakdown['tuitionFee'] ?? 0,
                    'items' => 1,
                ],
                [
                    'category' => 'Laboratory',
                    'total' => $feeBreakdown['labFee'] ?? 0,
                    'items' => 1,
                ],
                [
                    'category' => 'Miscellaneous',
                    'total' => $feeBreakdown['miscFee'] ?? 0,
                    'items' => 1,
                ],
            ],
            'backUrl' => route('student-fees.index'),
            'enrolledSubjectsByAssessment' => [],
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    //  EDIT — reopen assessment for correction
    // ─────────────────────────────────────────────────────────────

    public function edit(int $userId): Response
    {

        $user       = User::findOrFail($userId);
        $assessment = StudentAssessment::where('user_id', $userId)
            ->where('status', 'active')
            ->with('paymentTerms')
            ->firstOrFail();

        $feeRates = [
            'tuition_per_lec_unit' => config('fees.tuition_per_lec_unit', 364.00),
            'lab_fee_per_unit'     => config('fees.lab_fee_per_unit', 1656.00),
            'misc_fee_fixed'       => config('fees.misc_fee_fixed', 4700.00),
            'payment_terms'        => config('fees.payment_terms', []),
        ];

        return Inertia::render('StudentFees/Edit', [
            'student' => [
                'id'         => $user->id,
                'name'       => $user->last_name . ', ' . $user->first_name,
                'account_id' => $user->account_id,
                'course'     => $user->course,
                'year_level' => $user->year_level,
            ],
            'assessment' => [
                'id'           => $assessment->id,
                'semester'     => $assessment->semester,
                'school_year'  => $assessment->school_year,
                'lec_units'    => $assessment->lec_units,
                'lab_units'    => $assessment->lab_units,
            ],
            'feeRates' => $feeRates,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    //  UPDATE — recalculate assessment with new unit values
    // ─────────────────────────────────────────────────────────────

    public function update(Request $request, int $userId)
    {

        $validated = $request->validate([
            'semester'     => ['required', 'in:1st,2nd,Summer'],
            'school_year'  => ['required', 'string', 'max:20'],
            'lec_units'    => ['required', 'integer', 'min:0', 'max:30'],
            'lab_units'    => ['required', 'integer', 'min:0', 'max:10'],
        ]);

        $assessment = StudentAssessment::where('user_id', $userId)
            ->where('status', 'active')
            ->firstOrFail();

        // Only allow update if no payments have been made yet
        $paidTerms = $assessment->paymentTerms()
            ->where('status', '!=', 'unpaid')
            ->count();

        if ($paidTerms > 0) {
            return back()->withErrors([
                'lec_units' => 'Cannot edit this assessment — payments have already been recorded. Please contact the admin.',
            ]);
        }

        DB::transaction(function () use ($assessment, $validated, $userId) {
            // Recompute fees
            $fees = $this->computeTotal(
                (int) $validated['lec_units'],
                (int) $validated['lab_units']
            );

            // Update the assessment
            $assessment->update([
                'semester'     => $validated['semester'],
                'school_year'  => $validated['school_year'],
                'lec_units'    => $validated['lec_units'],
                'lab_units'    => $validated['lab_units'],
                'total_assessment' => $fees['total'],
            ]);

            // Delete old terms and regenerate
            $assessment->paymentTerms()->delete();
            $terms = $this->buildPaymentTerms($fees['total']);
            foreach ($terms as $term) {
                $assessment->paymentTerms()->create($term);
            }

            // Update the charge transaction
            Transaction::where('user_id', $userId)
                ->where('kind', 'charge')
                ->where('semester', $validated['semester'])
                ->where('payment_channel', 'assessment')
                ->latest()
                ->first()
                ?->update([
                    'amount' => $fees['total'],
                    'meta'   => json_encode([
                        'lec_units'   => $validated['lec_units'],
                        'lab_units'   => $validated['lab_units'],
                        'tuition_fee' => $fees['tuitionFee'],
                        'lab_fee'     => $fees['labFee'],
                        'misc_fee'    => $fees['miscFee'],
                        'school_year' => $validated['school_year'],
                        'updated_at'  => now()->toISOString(),
                    ]),
                ]);
        });

        return redirect()
            ->route('student-fees.show', $userId)
            ->with('success', 'Assessment updated successfully.');
    }

    // ─────────────────────────────────────────────────────────────
    //  SEARCH — live search for students (used by Create.vue)
    // ─────────────────────────────────────────────────────────────

    public function search(Request $request): \Illuminate\Http\JsonResponse
    {
        $q = $request->get('q', '');

        $students = User::where('role', UserRoleEnum::STUDENT)
            ->where(function ($query) use ($q) {
                $query->where('last_name', 'like', "%{$q}%")
                    ->orWhere('first_name', 'like', "%{$q}%")
                    ->orWhere('account_id', 'like', "%{$q}%");
            })
            ->where('is_active', true)
            ->select('id', 'last_name', 'first_name', 'account_id', 'course', 'year_level')
            ->limit(10)
            ->get()
            ->map(fn ($u) => [
                'id'         => $u->id,
                'name'       => $u->last_name . ', ' . $u->first_name,
                'account_id' => $u->account_id,
                'course'     => $u->course,
                'year_level' => $u->year_level,
            ]);

        return response()->json(['students' => $students]);
    }

    // ─────────────────────────────────────────────────────────────
    //  EXPORT PDF — download student assessment as PDF
    // ─────────────────────────────────────────────────────────────

    public function exportPdf(Request $request, int $userId)
    {
        $user = User::with('account', 'student')
            ->findOrFail($userId);

        // Fetch the assessment (either specified by query param or the latest active)
        $assessmentId = $request->query('assessment_id');
        
        if ($assessmentId) {
            // Cast to integer to handle potential string format
            $assessmentId = (int) $assessmentId;
            $assessment = StudentAssessment::where('id', $assessmentId)
                ->where('user_id', $userId)
                ->with('paymentTerms')
                ->firstOrFail();
        } else {
            $assessment = StudentAssessment::where('user_id', $userId)
                ->where('status', 'active')
                ->with('paymentTerms')
                ->latest()
                ->firstOrFail();
        }

        // Build fee breakdown from the assessment data
        $fees = $this->computeTotal($assessment->lec_units, $assessment->lab_units);
        
        $assessment->fee_breakdown = [
            [
                'category' => 'Tuition',
                'name'     => 'Lecture Units',
                'amount'   => $fees['tuitionFee'],
            ],
            [
                'category' => 'Laboratory',
                'name'     => 'Laboratory Units',
                'amount'   => $fees['labFee'],
            ],
            [
                'category' => 'Miscellaneous',
                'name'     => 'Registration Fee',
                'amount'   => $fees['miscFee'],
            ],
        ];

        // Get sorted payment terms
        $paymentTerms = $assessment->paymentTerms()
            ->orderBy('term_order')
            ->get();

        // Load and render PDF
        $pdf = Pdf::loadView('pdf.student-assessment', [
            'student'      => $user,
            'assessment'   => $assessment,
            'paymentTerms' => $paymentTerms,
        ]);

        $pdf->setPaper('A4', 'portrait');

        $filename = 'assessment-' . ($user->account_id ?? 'student') . '-' . $assessment->id . '.pdf';

        return $pdf->download($filename);
    }
}