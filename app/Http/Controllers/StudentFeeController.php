<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Student;
use App\Models\StudentAssessment;
use App\Models\StudentPaymentTerm;
use App\Models\Transaction;
use App\Models\User;
use App\Enums\UserRoleEnum;
use App\Enums\PaymentStatus;
use App\Services\StudentPaymentService;
use App\Services\AccountService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class StudentFeeController extends Controller
{
    // ─────────────────────────────────────────────────────────────
    //  HELPERS
    // ─────────────────────────────────────────────────────────────

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
                'balance'     => $amount,
                'status'      => 'unpaid',
                'due_date'    => null,
                'paid_date'   => null,
                'created_at'  => now(),
                'updated_at'  => now(),
            ];
        }

        return $terms;
    }

    /**
     * Build the student's display name including middle initial.
     * Format: "Last Name, First Name M."
     */
    private function buildStudentName(User $user): string
    {
        $mi   = $user->middle_initial ? ' ' . strtoupper($user->middle_initial) . '.' : '';
        return $user->last_name . ', ' . $user->first_name . $mi;
    }

    // ─────────────────────────────────────────────────────────────
    //  INDEX
    // ─────────────────────────────────────────────────────────────

    public function index(Request $request): Response
    {
        $query = User::where('role', UserRoleEnum::STUDENT)
            ->with([
                'latestAssessment.paymentTerms',
                'account',
            ]);

        if ($request->filled('search')) {
            $q = $request->search;
            $query->where(function ($q2) use ($q) {
                $q2->where('last_name', 'like', "%{$q}%")
                   ->orWhere('first_name', 'like', "%{$q}%")
                   ->orWhere('account_id', 'like', "%{$q}%");
            });
        }

        if ($request->filled('course')) {
            $query->where('course', $request->course);
        }

        if ($request->filled('year_level')) {
            $query->where('year_level', $request->year_level);
        }

        if ($request->filled('status')) {
            $query->whereHas('student', fn ($q) => $q->where('enrollment_status', $request->status));
        }

        $students = $query->paginate(20)->through(fn ($u) => [
            'id'                => $u->id,
            'account_id'        => $u->account_id,
            // ✅ FIXED: middle initial included in all name constructions
            'name'              => $this->buildStudentName($u),
            'course'            => $u->course,
            'year_level'        => $u->year_level,
            'status'            => $u->student?->enrollment_status ?? 'pending',
            // ✅ FIXED: balance always non-negative
            'remaining_balance' => max(0, (float) ($u->account?->balance ?? 0)),
            'account'           => $u->account ? ['balance' => max(0, (float) $u->account->balance)] : null,
            'latestAssessment'  => $u->latestAssessment ? [
                'id'               => $u->latestAssessment->id,
                'total_assessment' => $u->latestAssessment->total_assessment,
                'paymentTerms'     => $u->latestAssessment->paymentTerms->map(fn ($t) => [
                    'id'         => $t->id,
                    'term_name'  => $t->term_name,
                    'term_order' => $t->term_order,
                    'amount'     => $t->amount,
                    'balance'    => max(0, (float) $t->balance),
                    'status'     => $t->status,
                    'due_date'   => $t->due_date,
                ])->values()->all(),
            ] : null,
        ]);

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
    //  CREATE
    // ─────────────────────────────────────────────────────────────

    public function create(Request $request): Response
    {
        $preselectedStudent = null;
        if ($request->filled('student_id')) {
            $student = User::where('role', UserRoleEnum::STUDENT)
                ->where('id', $request->student_id)
                ->first();

            if ($student) {
                $preselectedStudent = [
                    'id'         => $student->id,
                    // ✅ FIXED: middle initial included
                    'name'       => $this->buildStudentName($student),
                    'account_id' => $student->account_id,
                    'course'     => $student->course,
                    'year_level' => $student->year_level,
                ];
            }
        }

        $feeRates = [
            'tuition_per_lec_unit' => config('fees.tuition_per_lec_unit', 364.00),
            'lab_fee_per_unit'     => config('fees.lab_fee_per_unit', 1656.00),
            'misc_fee_fixed'       => config('fees.misc_fee_fixed', 4700.00),
            'misc_items'           => config('fees.misc_items', []),
            'payment_terms'        => config('fees.payment_terms', []),
        ];

        return Inertia::render('StudentFees/Create', [
            'preselectedStudent' => $preselectedStudent,
            'feeRates'           => $feeRates,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    //  STORE
    //
    //  DUPLICATE GUARD: lockForUpdate() + RuntimeException ensures
    //  no two concurrent requests can create assessments for the same
    //  student / semester / school_year combination.
    // ─────────────────────────────────────────────────────────────

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id'     => ['required', 'exists:users,id'],
            'semester'    => ['required', 'in:1st,2nd,Summer'],
            'school_year' => ['required', 'string', 'max:20'],
            'lec_units'   => ['required', 'integer', 'min:0', 'max:30'],
            'lab_units'   => ['required', 'integer', 'min:0', 'max:10'],
        ]);

        try {
            DB::transaction(function () use ($validated) {

                // ── DUPLICATE GUARD ──────────────────────────────────────────
                // Lock the student's rows before checking so concurrent
                // requests cannot both pass and both insert.
                $existing = StudentAssessment::where('user_id', $validated['user_id'])
                    ->where('semester', $validated['semester'])
                    ->where('school_year', $validated['school_year'])
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    throw new \RuntimeException(
                        'DUPLICATE_ASSESSMENT:' .
                        $validated['semester'] . ' Sem, SY ' . $validated['school_year']
                    );
                }

                // Archive any other active assessment for this student.
                // This runs BEFORE generateAssessmentNumber() so the
                // archived record is already committed within the transaction
                // when the generator queries MAX().
                StudentAssessment::where('user_id', $validated['user_id'])
                    ->where('status', 'active')
                    ->update(['status' => 'completed']);

                // Compute fees
                $fees    = $this->computeTotal(
                    (int) $validated['lec_units'],
                    (int) $validated['lab_units']
                );
                $student = User::findOrFail($validated['user_id']);

                // Generate assessment number AFTER archive so the MAX()
                // query inside the generator sees all existing records
                // (including just-archived ones) and returns the correct
                // next sequence number.
                $assessmentNumber = StudentAssessment::generateAssessmentNumber();

                // Create assessment
                $assessment = StudentAssessment::create([
                    'assessment_number' => $assessmentNumber,
                    'user_id'           => $validated['user_id'],
                    'semester'          => $validated['semester'],
                    'school_year'       => $validated['school_year'],
                    'lec_units'         => $validated['lec_units'],
                    'lab_units'         => $validated['lab_units'],
                    'year_level'        => $student->year_level,
                    'total_assessment'  => $fees['total'],
                    'status'            => 'active',
                ]);

                // Build and insert payment terms
                foreach ($this->buildPaymentTerms($fees['total']) as $term) {
                    $assessment->paymentTerms()->create($term);
                }

                // Post charge transaction to ledger
                Transaction::create([
                    'user_id'         => $validated['user_id'],
                    'kind'            => 'charge',
                    'status'          => 'paid',
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

                // Recalculate account balance
                AccountService::recalculate($student);
            });
        } catch (\RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'DUPLICATE_ASSESSMENT:')) {
                $detail = str_replace('DUPLICATE_ASSESSMENT:', '', $e->getMessage());
                return back()->withErrors([
                    'user_id' => "This student already has an active assessment for {$detail}.",
                ]);
            }
            throw $e;
        }

        return redirect()
            ->route('student-fees.show', $validated['user_id'])
            ->with('success', 'Assessment created successfully.');
    }

    // ─────────────────────────────────────────────────────────────
    //  SHOW
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
            'student' => [
                'id'         => $user->id,
                // ✅ FIXED: middle initial included
                'name'       => $this->buildStudentName($user),
                'account_id' => $user->account_id,
                'course'     => $user->course,
                'year_level' => $user->year_level,
                'avatar'     => $user->avatar ?? null,
                // ✅ FIXED: balance always non-negative
                'account'    => $user->account
                    ? ['balance' => max(0, (float) $user->account->balance)]
                    : null,
            ],
            'assessment' => $assessment ? [
                'id'               => $assessment->id,
                'course'           => $user->course,
                'semester'         => $assessment->semester,
                'school_year'      => $assessment->school_year,
                'year_level'       => $user->year_level,
                'total_assessment' => (float) $assessment->total_assessment,
                'tuition_fee'      => $feeBreakdown['tuitionFee'] ?? 0,
                'other_fees'       => ($feeBreakdown['labFee'] ?? 0) + ($feeBreakdown['miscFee'] ?? 0),
                // ✅ FIXED: Correct labels — Tuition Fee / Laboratory Fee / Miscellaneous Fee
                // ✅ FIXED: Miscellaneous has units = null (flat fee, not unit-based)
                'fee_breakdown'    => [
                    [
                        'category' => 'Tuition',
                        'name'     => 'Tuition Fee',
                        'code'     => 'TUI',
                        'units'    => $assessment->lec_units,
                        'amount'   => $feeBreakdown['tuitionFee'] ?? 0,
                    ],
                    [
                        'category' => 'Laboratory',
                        'name'     => 'Laboratory Fee',
                        'code'     => 'LAB',
                        'units'    => $assessment->lab_units,
                        'amount'   => $feeBreakdown['labFee'] ?? 0,
                    ],
                    [
                        'category' => 'Miscellaneous',
                        'name'     => 'Miscellaneous Fee',
                        'code'     => 'MISC',
                        'units'    => null,   // flat fee — no unit basis
                        'amount'   => $feeBreakdown['miscFee'] ?? 0,
                    ],
                ],
                'status'       => $assessment->status,
                'paymentTerms' => $assessment->paymentTerms->sortBy('term_order')->values(),
            ] : null,
            'allAssessments'              => [],
            'transactions'                => [],
            'payments'                    => [],
            'feeBreakdown'                => [
                ['category' => 'Tuition',       'total' => $feeBreakdown['tuitionFee'] ?? 0, 'items' => 1],
                ['category' => 'Laboratory',    'total' => $feeBreakdown['labFee'] ?? 0,     'items' => 1],
                ['category' => 'Miscellaneous', 'total' => $feeBreakdown['miscFee'] ?? 0,    'items' => 1],
            ],
            'miscItems'                   => config('fees.misc_items', []),
            'backUrl'                     => route('student-fees.index'),
            'enrolledSubjectsByAssessment' => [],
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    //  EDIT
    //
    //  FIX: Admin-only guard. Accounting gets a redirect to show.
    // ─────────────────────────────────────────────────────────────

    public function edit(int $userId): Response
    {
        $authUser = auth()->user();
        $authRole = $authUser->role instanceof \App\Enums\UserRoleEnum
            ? $authUser->role->value
            : (string) $authUser->role;

        if ($authRole !== 'admin') {
            return redirect()
                ->route('student-fees.show', $userId)
                ->with('flash.warning', 'Only administrators can edit assessments.');
        }

        $user       = User::findOrFail($userId);
        $assessment = StudentAssessment::where('user_id', $userId)
            ->where('status', 'active')
            ->with('paymentTerms')
            ->firstOrFail();

        $feeRates = [
            'tuition_per_lec_unit' => config('fees.tuition_per_lec_unit', 364.00),
            'lab_fee_per_unit'     => config('fees.lab_fee_per_unit', 1656.00),
            'misc_fee_fixed'       => config('fees.misc_fee_fixed', 4700.00),
            'misc_items'           => config('fees.misc_items', []),
            'payment_terms'        => config('fees.payment_terms', []),
        ];

        return Inertia::render('StudentFees/Edit', [
            'student' => [
                'id'         => $user->id,
                // ✅ FIXED: middle initial included
                'name'       => $this->buildStudentName($user),
                'account_id' => $user->account_id,
                'course'     => $user->course,
                'year_level' => $user->year_level,
            ],
            'assessment' => [
                'id'          => $assessment->id,
                'semester'    => $assessment->semester,
                'school_year' => $assessment->school_year,
                'lec_units'   => $assessment->lec_units,
                'lab_units'   => $assessment->lab_units,
            ],
            'feeRates' => $feeRates,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    //  UPDATE
    // ─────────────────────────────────────────────────────────────

    public function update(Request $request, int $userId)
    {
        $validated = $request->validate([
            'semester'    => ['required', 'in:1st,2nd,Summer'],
            'school_year' => ['required', 'string', 'max:20'],
            'lec_units'   => ['required', 'integer', 'min:0', 'max:30'],
            'lab_units'   => ['required', 'integer', 'min:0', 'max:10'],
        ]);

        $assessment = StudentAssessment::where('user_id', $userId)
            ->where('status', 'active')
            ->firstOrFail();

        $paidTerms = $assessment->paymentTerms()
            ->where('status', '!=', 'unpaid')
            ->count();

        if ($paidTerms > 0) {
            return back()->withErrors([
                'lec_units' => 'Cannot edit this assessment — payments have already been recorded.',
            ]);
        }

        DB::transaction(function () use ($assessment, $validated, $userId) {
            $fees = $this->computeTotal(
                (int) $validated['lec_units'],
                (int) $validated['lab_units']
            );

            $assessment->update([
                'semester'         => $validated['semester'],
                'school_year'      => $validated['school_year'],
                'lec_units'        => $validated['lec_units'],
                'lab_units'        => $validated['lab_units'],
                'total_assessment' => $fees['total'],
            ]);

            $assessment->paymentTerms()->delete();
            foreach ($this->buildPaymentTerms($fees['total']) as $term) {
                $assessment->paymentTerms()->create($term);
            }

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

            AccountService::recalculate(User::find($userId));
        });

        return redirect()
            ->route('student-fees.show', $userId)
            ->with('success', 'Assessment updated successfully.');
    }

    // ─────────────────────────────────────────────────────────────
    //  SEARCH
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
            ->select('id', 'last_name', 'first_name', 'middle_initial', 'account_id', 'course', 'year_level')
            ->limit(10)
            ->get()
            ->map(fn ($u) => [
                'id'         => $u->id,
                // ✅ FIXED: middle initial included
                'name'       => $this->buildStudentName($u),
                'account_id' => $u->account_id,
                'course'     => $u->course,
                'year_level' => $u->year_level,
            ]);

        return response()->json(['students' => $students]);
    }

    // ─────────────────────────────────────────────────────────────
    //  EXPORT PDF
    // ─────────────────────────────────────────────────────────────

    public function exportPdf(Request $request, int $userId)
    {
        $user = User::with('account', 'student')->findOrFail($userId);

        $assessmentId = $request->query('assessment_id');

        if ($assessmentId) {
            $assessment = StudentAssessment::where('id', (int) $assessmentId)
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

        $fees = $this->computeTotal($assessment->lec_units, $assessment->lab_units);

        $assessment->fee_breakdown = [
            ['category' => 'Tuition',       'name' => 'Tuition Fee',       'amount' => $fees['tuitionFee']],
            ['category' => 'Laboratory',    'name' => 'Laboratory Fee',    'amount' => $fees['labFee']],
            ['category' => 'Miscellaneous', 'name' => 'Miscellaneous Fee', 'amount' => $fees['miscFee']],
        ];

        $paymentTerms = $assessment->paymentTerms()->orderBy('term_order')->get();

        $pdf = Pdf::loadView('pdf.student-assessment', [
            'student'      => $user,
            'assessment'   => $assessment,
            'paymentTerms' => $paymentTerms,
            'miscItems'    => config('fees.misc_items', []),
        ]);

        $pdf->setPaper('A4', 'portrait');

        $filename = 'assessment-' . ($user->account_id ?? 'student') . '-' . $assessment->id . '.pdf';

        return $pdf->download($filename);
    }

    // ─────────────────────────────────────────────────────────────
    //  CREATE STUDENT
    // ─────────────────────────────────────────────────────────────

    public function createStudent(): Response
    {
        $courses = User::where('role', UserRoleEnum::STUDENT)
            ->distinct()
            ->pluck('course')
            ->sort()
            ->values();

        return Inertia::render('StudentFees/CreateStudent', [
            'courses'    => $courses,
            'yearLevels' => ['1st Year', '2nd Year', '3rd Year', '4th Year'],
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    //  STORE STUDENT
    // ─────────────────────────────────────────────────────────────

    public function storeStudent(Request $request)
    {
        $request->validate([
            'last_name'      => 'required|string|max:255',
            'first_name'     => 'required|string|max:255',
            'middle_initial' => 'nullable|string|max:10',
            'email'          => 'required|string|lowercase|email|max:255|unique:' . User::class,
            'birthday'       => 'required|date',
            'year_level'     => 'required|string|max:50',
            'course'         => 'required|string|max:255',
            'address'        => 'required|string|max:255',
            'phone'          => 'required|string|max:20',
        ]);

        DB::beginTransaction();
        try {
            $accountId = $this->generateUniqueAccountId();

            $user = User::create([
                'last_name'      => $request->last_name,
                'first_name'     => $request->first_name,
                'middle_initial' => $request->middle_initial,
                'email'          => $request->email,
                'password'       => Hash::make(Str::random(16)),
                'birthday'       => $request->birthday,
                'year_level'     => $request->year_level,
                'course'         => $request->course,
                'address'        => $request->address,
                'phone'          => $request->phone,
                'account_id'     => $accountId,
                'status'         => User::STATUS_ACTIVE,
                'role'           => UserRoleEnum::STUDENT,
            ]);

            Student::create([
                'user_id'           => $user->id,
                'student_id'        => $accountId,
                'enrollment_status' => 'active',
            ]);

            Account::create([
                'user_id'        => $user->id,
                'account_number' => $accountId,
                'balance'        => 0,
            ]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        return redirect()
            ->route('student-fees.index')
            ->with('success', 'Student account created successfully. You can now create an assessment for them.');
    }

    // ─────────────────────────────────────────────────────────────
    //  STORE PAYMENT (Accounting side)
    // ─────────────────────────────────────────────────────────────

    public function storePayment(Request $request, int $userId)
    {
        $user = $request->user();

        if ($user->role !== UserRoleEnum::ACCOUNTING) {
            abort(403, 'Only accounting staff can record payments.');
        }

        $student = User::findOrFail($userId);
        if (! $student->student) {
            abort(404, 'Student account not found.');
        }

        $validated = $request->validate([
            'amount'         => 'required|numeric|min:0.01',
            'payment_method' => 'required|string|in:cash,gcash,bank_transfer,credit_card,debit_card',
            'assessment_id'  => 'required|exists:student_assessments,id',
            'payment_date'   => 'required|date',
        ]);

        try {
            $assessment = StudentAssessment::findOrFail((int) $validated['assessment_id']);

            if ($assessment->user_id !== $student->id) {
                abort(403, 'Assessment does not belong to this student.');
            }

            $term = StudentPaymentTerm::where('student_assessment_id', $assessment->id)
                ->where('balance', '>', 0)
                ->orderBy('term_order')
                ->first();

            if (! $term) {
                return back()->withErrors(['payment' => 'No outstanding payment terms found for this assessment.']);
            }

            $duplicateExists = Transaction::where('user_id', $student->id)
                ->where('kind', 'payment')
                ->whereIn('status', [PaymentStatus::PAID->value, PaymentStatus::AWAITING_APPROVAL->value])
                ->whereJsonContains('meta->selected_term_id', $term->id)
                ->whereDate('created_at', now()->toDateString())
                ->where('amount', round((float) $validated['amount'], 2))
                ->exists();

            if ($duplicateExists) {
                return back()->withErrors([
                    'payment' => 'A payment of that amount for this term was already recorded today. Check the transaction history before proceeding.',
                ]);
            }

            $paymentService = new StudentPaymentService();
            $paidAmount     = round((float) $validated['amount'], 2);

            $paymentService->processPayment($student, $paidAmount, [
                'payment_method'   => $validated['payment_method'],
                'paid_at'          => $validated['payment_date'],
                'description'      => 'Recorded by accounting staff',
                'selected_term_id' => (int) $term->id,
                'term_name'        => $term->term_name,
                'year'             => explode('-', $assessment->school_year)[0],
                'semester'         => $assessment->semester,
            ], false);

            return back()->with('success', 'Payment of ₱' . number_format($paidAmount, 2) . ' recorded successfully for ' . $this->buildStudentName($student) . '.');

        } catch (\Exception $e) {
            Log::error('storePayment failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            return back()->withErrors(['payment' => 'Payment processing failed: ' . $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────────────────────
    //  HELPER: Generate unique account ID
    // ─────────────────────────────────────────────────────────────

    private function generateUniqueAccountId(): string
    {
        do {
            $id = date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        } while (User::where('account_id', $id)->exists());

        return $id;
    }
}