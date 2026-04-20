<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Student;
use App\Models\Subject;
use App\Models\StudentAssessment;
use App\Models\StudentPaymentTerm;
use App\Models\Transaction;
use App\Models\User;
use App\Enums\UserRoleEnum;
use App\Enums\PaymentStatus;
use App\Services\AssessmentService;
use App\Services\AccountService;
use App\Services\DiscountService;
use App\Services\StudentPaymentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
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

    private function buildStudentName(User $user): string
    {
        $mi = $user->middle_initial ? ' ' . strtoupper($user->middle_initial) . '.' : '';
        return $user->last_name . ', ' . $user->first_name . $mi;
    }

    // ─────────────────────────────────────────────────────────────
    //  INDEX
    // ─────────────────────────────────────────────────────────────

    public function index(Request $request): Response
    {
        $sortField     = in_array($request->input('sort'), ['name', 'balance']) ? $request->input('sort') : 'name';
        $sortDirection = in_array($request->input('direction'), ['asc', 'desc']) ? $request->input('direction') : 'asc';

        $query = User::where('role', UserRoleEnum::STUDENT)
            ->with(['latestAssessment.paymentTerms', 'account']);

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

        if ($sortField === 'balance') {
            $query
                ->leftJoin('accounts', 'accounts.user_id', '=', 'users.id')
                ->select('users.*', DB::raw('COALESCE(accounts.balance, 0) as computed_balance'))
                ->orderBy('computed_balance', $sortDirection);
        } else {
            $query->select('users.*')
                ->orderBy('last_name', $sortDirection)
                ->orderBy('first_name', $sortDirection);
        }

        $students = $query->paginate(20)->through(fn ($u) => [
            'id'                => $u->id,
            'student_db_id'     => $u->student?->id,
            'account_id'        => $u->account_id,
            'name'              => $this->buildStudentName($u),
            'course'            => $u->course,
            'year_level'        => $u->year_level,
            'is_irregular'      => (bool) $u->is_irregular,
            'status'            => $u->student?->enrollment_status ?? 'pending',
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

        $students->appends($request->only(['search', 'course', 'year_level', 'status', 'sort', 'direction']));

        $courses    = User::where('role', UserRoleEnum::STUDENT)->whereNotNull('course')->distinct()->pluck('course')->sort()->values();
        $yearLevels = User::where('role', UserRoleEnum::STUDENT)->whereNotNull('year_level')->distinct()->pluck('year_level')->sort()->values();

        return Inertia::render('StudentFees/Index', [
            'students'   => $students,
            'filters'    => $request->only(['search', 'course', 'year_level', 'status']),
            'sort'       => $sortField,
            'direction'  => $sortDirection,
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
    //  CREATE  (accounting creates assessment after selecting student)
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
                    'id'           => $student->id,
                    'name'         => $this->buildStudentName($student),
                    'account_id'   => $student->account_id,
                    'course'       => $student->course,
                    'year_level'   => $student->year_level,
                    'is_irregular' => (bool) $student->is_irregular,
                ];
            }
        }

        // Always read from fee_settings — never config()
        $feeRates = AssessmentService::feeRatesForForm();

        return Inertia::render('StudentFees/Create', [
            'preselectedStudent' => $preselectedStudent,
            'feeRates'           => $feeRates,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    //  STORE
    // ─────────────────────────────────────────────────────────────

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id'             => ['required', 'exists:users,id'],
            'semester'            => ['required', 'in:1st,2nd,Summer'],
            'school_year'         => ['required', 'string', 'max:20'],
            'lec_units'           => ['required', 'numeric', 'min:0', 'max:50'],
            'lab_units'           => ['required', 'integer', 'min:0', 'max:20'],
            'discount_type'       => ['required', 'string', 'in:none,full,nstp,percentage'],
            'discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_taking_nstp'      => ['boolean'],
        ]);

        $validated['lec_units']           = (int) $validated['lec_units'];
        $validated['lab_units']           = (int) $validated['lab_units'];
        $validated['is_taking_nstp']      = (bool) ($validated['is_taking_nstp'] ?? false);
        $validated['discount_percentage'] = (float) ($validated['discount_percentage'] ?? 0.0);

        // Enforce: discount_percentage only makes sense when discount_type = 'percentage'
        if ($validated['discount_type'] !== 'percentage') {
            $validated['discount_percentage'] = 0.0;
        }

        try {
            DB::transaction(function () use ($validated) {
                // Prevent duplicate active assessments
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

                // Archive any previous active assessment
                StudentAssessment::where('user_id', $validated['user_id'])
                    ->where('status', 'active')
                    ->update(['status' => 'completed']);

                // Load fee rates from fee_settings table (single source of truth)
                $rates = AssessmentService::loadRates();

                // Compute fees
                $fees = AssessmentService::compute(
                    lecUnits:            $validated['lec_units'],
                    labSubjects:         $validated['lab_units'],
                    discountType:        $validated['discount_type'],
                    isTakingNstp:        $validated['is_taking_nstp'],
                    discountPercentage:  $validated['discount_percentage'],
                    rates:               $rates,
                );

                $student          = User::findOrFail($validated['user_id']);
                $assessmentNumber = StudentAssessment::generateAssessmentNumber();

                $assessment = StudentAssessment::create([
                    'assessment_number'   => $assessmentNumber,
                    'user_id'             => $validated['user_id'],
                    'course'              => $student->course,
                    'semester'            => $validated['semester'],
                    'school_year'         => $validated['school_year'],
                    'lec_units'           => $validated['lec_units'],
                    'lab_units'           => $validated['lab_units'],
                    'lab_subjects'        => $validated['lab_units'],
                    'discount_type'       => $validated['discount_type'],
                    'discount_percentage' => $validated['discount_percentage'],
                    'is_taking_nstp'      => $validated['is_taking_nstp'],
                    'tuition_fee'         => $fees['tuition_fee'],
                    'lab_fee'             => $fees['lab_fee'],
                    'misc_fee'            => $fees['misc_fee'],
                    'year_level'          => $student->year_level,
                    'total_assessment'    => $fees['total'],
                    'status'              => 'active',
                ]);

                // Build payment terms
                foreach (AssessmentService::buildPaymentTerms($fees['total'], $rates) as $term) {
                    $assessment->paymentTerms()->create($term);
                }

                // Audit transaction
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
                        'lec_units'        => $validated['lec_units'],
                        'lab_units'        => $validated['lab_units'],
                        'discount_type'    => $validated['discount_type'],
                        'is_taking_nstp'   => $validated['is_taking_nstp'],
                        'tuition_fee'      => $fees['tuition_fee'],
                        'lab_fee'          => $fees['lab_fee'],
                        'misc_fee'         => $fees['misc_fee'],
                        'school_year'      => $validated['school_year'],
                        'discount_applied' => $fees['discount_applied'],
                    ]),
                ]);

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
        $user = User::with(['latestAssessment.paymentTerms', 'account'])->findOrFail($userId);

        $allAssessmentsRaw = StudentAssessment::where('user_id', $userId)
            ->with('paymentTerms')
            ->orderByDesc('created_at')
            ->get();

        $assessment = $user->latestAssessment;

        $allAssessmentsFormatted = $allAssessmentsRaw->map(function ($a) use ($user) {
            return [
                'id'               => $a->id,
                'course'           => $user->course,
                'semester'         => $a->semester,
                'school_year'      => $a->school_year,
                'year_level'       => $a->year_level ?? $user->year_level,
                'total_assessment' => (float) $a->total_assessment,
                'tuition_fee'      => (float) $a->tuition_fee,
                'lab_fee'          => (float) $a->lab_fee,
                'misc_fee'         => (float) $a->misc_fee,
                'other_fees'       => (float) ($a->lab_fee + $a->misc_fee),
                'lec_units'        => $a->lec_units,
                'lab_units'        => $a->lab_units,
                'discount_type'    => $a->discount_type,
                'is_taking_nstp'   => $a->is_taking_nstp,
                'fee_breakdown'    => [
                    ['category' => 'Tuition',       'name' => 'Tuition Fee',       'code' => 'TUI', 'units' => $a->lec_units,  'amount' => (float) $a->tuition_fee],
                    ['category' => 'Laboratory',    'name' => 'Laboratory Fee',    'code' => 'LAB', 'units' => $a->lab_units,  'amount' => (float) $a->lab_fee],
                    ['category' => 'Miscellaneous', 'name' => 'Miscellaneous Fee', 'code' => 'MISC','units' => null,           'amount' => (float) $a->misc_fee],
                ],
                'status'       => $a->status,
                'paymentTerms' => $a->paymentTerms->sortBy('term_order')->values(),
            ];
        })->values()->all();

        $studentRecord = $user->student;
        $payments      = $studentRecord
            ? \App\Models\Payment::where('student_id', $studentRecord->id)
                ->with('assessment')
                ->orderByDesc('created_at')
                ->get()
                ->map(fn ($p) => [
                    'id'               => $p->id,
                    'assessment_id'    => $p->student_assessment_id,
                    'amount'           => (float) $p->amount,
                    'payment_method'   => $p->payment_method,
                    'reference_number' => $p->paymongo_payment_id
                        ?? ($p->meta['reference_number'] ?? null)
                        ?? ('PAY-' . strtoupper(substr(md5($p->id . $p->created_at), 0, 8))),
                    'description'      => $p->description ?? 'Payment',
                    'status'           => $p->status,
                    'paid_at'          => $p->created_at?->toDateString(),
                    'school_year'      => $p->assessment?->school_year,
                    'semester'         => $p->assessment?->semester,
                ])->all()
            : [];

        $transactions = Transaction::where('user_id', $userId)
            ->where('kind', 'payment')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($t) => [
                'id'         => $t->id,
                'kind'       => $t->kind,
                'type'       => $t->type ?? ucfirst($t->kind),
                'amount'     => (float) $t->amount,
                'reference'  => $t->reference,
                'status'     => $t->status,
                'year'       => $t->year,
                'semester'   => $t->semester,
                'meta'       => $t->meta,
                'created_at' => $t->created_at?->toDateTimeString(),
            ])->all();

        $activeAssessmentFormatted = $assessment ? [
            'id'               => $assessment->id,
            'course'           => $user->course,
            'semester'         => $assessment->semester,
            'school_year'      => $assessment->school_year,
            'year_level'       => $assessment->year_level ?? $user->year_level,
            'lec_units'        => $assessment->lec_units,
            'lab_units'        => $assessment->lab_units,
            'total_assessment' => (float) $assessment->total_assessment,
            'tuition_fee'      => (float) $assessment->tuition_fee,
            'lab_fee'          => (float) $assessment->lab_fee,
            'misc_fee'         => (float) $assessment->misc_fee,
            'other_fees'       => (float) ($assessment->lab_fee + $assessment->misc_fee),
            'fee_breakdown'    => [
                ['category' => 'Tuition',       'name' => 'Tuition Fee',       'code' => 'TUI', 'units' => $assessment->lec_units, 'amount' => (float) $assessment->tuition_fee],
                ['category' => 'Laboratory',    'name' => 'Laboratory Fee',    'code' => 'LAB', 'units' => $assessment->lab_units, 'amount' => (float) $assessment->lab_fee],
                ['category' => 'Miscellaneous', 'name' => 'Miscellaneous Fee', 'code' => 'MISC','units' => null,                   'amount' => (float) $assessment->misc_fee],
            ],
            'status'       => $assessment->status,
            'paymentTerms' => $assessment->paymentTerms->sortBy('term_order')->values(),
        ] : null;

        // Load misc items from fee_settings (not config)
        $miscItems = \App\Models\FeeSetting::whereIn('category', ['miscellaneous', 'other'])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn ($s) => ['label' => $s->label, 'amount' => (float) $s->amount])
            ->all();

        return Inertia::render('StudentFees/Show', [
            'student' => [
                'id'           => $user->id,
                'name'         => $this->buildStudentName($user),
                'account_id'   => $user->account_id,
                'course'       => $user->course,
                'year_level'   => $user->year_level,
                'email'        => $user->email,
                'birthday'     => $user->birthday,
                'phone'        => $user->phone,
                'status'       => $user->status,
                'is_irregular' => (bool) $user->is_irregular,
                'avatar'       => $user->avatar ?? null,
                'account'      => $user->account ? ['balance' => max(0, (float) $user->account->balance)] : null,
            ],
            'assessment'     => $activeAssessmentFormatted,
            'allAssessments' => $allAssessmentsFormatted,
            'transactions'   => $transactions,
            'payments'       => $payments,
            'feeBreakdown'   => $assessment ? [
                ['category' => 'Tuition',       'total' => (float) $assessment->tuition_fee, 'items' => 1],
                ['category' => 'Laboratory',    'total' => (float) $assessment->lab_fee,     'items' => 1],
                ['category' => 'Miscellaneous', 'total' => (float) $assessment->misc_fee,    'items' => 1],
            ] : [],
            'miscItems'                   => $miscItems,
            'backUrl'                     => route('student-fees.index'),
            'enrolledSubjectsByAssessment' => [],
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    //  EDIT
    // ─────────────────────────────────────────────────────────────

    public function edit(int $userId): Response|RedirectResponse
    {
        $authUser = auth()->user();
        $authRole = $authUser->role instanceof \App\Enums\UserRoleEnum
            ? $authUser->role->value
            : (string) $authUser->role;

        if (! in_array($authRole, ['admin', 'accounting'])) {
            return redirect()
                ->route('student-fees.show', $userId)
                ->with('flash.warning', 'Unauthorized to edit assessments.');
        }

        $user = User::findOrFail($userId);

        $assessment = StudentAssessment::where('user_id', $userId)
            ->where('status', 'active')
            ->with('paymentTerms')
            ->first();

        if (! $assessment) {
            return redirect()
                ->route('student-fees.show', $userId)
                ->with('flash.error', 'No active assessment found for this student. Create one first.');
        }

        // Calculate nstp_units from enrolled subjects
        $nstpUnits = 0;
        if ($assessment->is_taking_nstp) {
            $nstpUnits = \DB::table('student_enrollments')
                ->join('subjects', 'student_enrollments.subject_id', '=', 'subjects.id')
                ->where('student_enrollments.user_id', $userId)
                ->where('student_enrollments.school_year', $assessment->school_year)
                ->where('student_enrollments.semester', $assessment->semester)
                ->where('student_enrollments.status', 'enrolled')
                ->where(\DB::raw("UPPER(subjects.code)"), 'like', 'NSTP%')
                ->sum('subjects.lec_units');
        }

        $feeRates = AssessmentService::feeRatesForForm();

        return Inertia::render('StudentFees/Edit', [
            'student' => [
                'id'           => $user->id,
                'name'         => $this->buildStudentName($user),
                'account_id'   => $user->account_id,
                'course'       => $user->course,
                'year_level'   => $user->year_level,
                'is_irregular' => (bool) $user->is_irregular,
            ],
            'assessment' => [
                'id'             => $assessment->id,
                'semester'       => $assessment->semester,
                'school_year'    => $assessment->school_year,
                'lec_units'      => $assessment->lec_units,
                'nstp_units'     => (int) $nstpUnits,
                'lab_units'      => $assessment->lab_units,
                'discount_type'  => $assessment->discount_type ?? 'none',
                'is_taking_nstp' => $assessment->is_taking_nstp ?? false,
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
            'semester'            => ['required', 'in:1st,2nd,Summer'],
            'school_year'         => ['required', 'string', 'max:20'],
            'lec_units'           => ['required', 'numeric', 'min:0', 'max:50'],
            'lab_units'           => ['required', 'integer', 'min:0', 'max:20'],
            'discount_type'       => ['required', 'string', 'in:none,full,nstp,percentage'],
            'discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_taking_nstp'      => ['boolean'],
        ]);

        $validated['lec_units']           = (int) $validated['lec_units'];
        $validated['lab_units']           = (int) $validated['lab_units'];
        $validated['is_taking_nstp']      = (bool) ($validated['is_taking_nstp'] ?? false);
        $validated['discount_percentage'] = (float) ($validated['discount_percentage'] ?? 0.0);

        if ($validated['discount_type'] !== 'percentage') {
            $validated['discount_percentage'] = 0.0;
        }

        $assessment = StudentAssessment::where('user_id', $userId)
            ->where('status', 'active')
            ->firstOrFail();

        $paidTerms = $assessment->paymentTerms()->where('status', '!=', 'unpaid')->count();
        if ($paidTerms > 0) {
            return back()->withErrors([
                'lec_units' => 'Cannot edit this assessment — payments have already been recorded.',
            ]);
        }

        DB::transaction(function () use ($assessment, $validated, $userId) {
            $rates = AssessmentService::loadRates();

            $fees = AssessmentService::compute(
                lecUnits:           $validated['lec_units'],
                labSubjects:        $validated['lab_units'],
                discountType:       $validated['discount_type'],
                isTakingNstp:       $validated['is_taking_nstp'],
                discountPercentage: $validated['discount_percentage'],
                rates:              $rates,
            );

            $assessment->update([
                'semester'            => $validated['semester'],
                'school_year'         => $validated['school_year'],
                'lec_units'           => $validated['lec_units'],
                'lab_units'           => $validated['lab_units'],
                'discount_type'       => $validated['discount_type'],
                'discount_percentage' => $validated['discount_percentage'],
                'is_taking_nstp'      => $validated['is_taking_nstp'],
                'tuition_fee'         => $fees['tuition_fee'],
                'lab_fee'             => $fees['lab_fee'],
                'misc_fee'            => $fees['misc_fee'],
                'total_assessment'    => $fees['total'],
            ]);

            $assessment->paymentTerms()->delete();
            foreach (AssessmentService::buildPaymentTerms($fees['total'], $rates) as $term) {
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
                        'lec_units'        => $validated['lec_units'],
                        'lab_units'        => $validated['lab_units'],
                        'discount_type'    => $validated['discount_type'],
                        'is_taking_nstp'   => $validated['is_taking_nstp'],
                        'tuition_fee'      => $fees['tuition_fee'],
                        'lab_fee'          => $fees['lab_fee'],
                        'misc_fee'         => $fees['misc_fee'],
                        'discount_applied' => $fees['discount_applied'],
                    ]),
                ]);

            AccountService::recalculate(User::findOrFail($userId));
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
            ->select('id', 'last_name', 'first_name', 'middle_initial', 'account_id', 'course', 'year_level', 'is_irregular')
            ->limit(10)
            ->get()
            ->map(fn ($u) => [
                'id'           => $u->id,
                'name'         => $this->buildStudentName($u),
                'account_id'   => $u->account_id,
                'course'       => $u->course,
                'year_level'   => $u->year_level,
                'is_irregular' => (bool) $u->is_irregular,
            ]);

        return response()->json(['students' => $students]);
    }

    // ─────────────────────────────────────────────────────────────
    //  GET CURRICULUM UNITS (for auto-populate on regular students)
    // ─────────────────────────────────────────────────────────────

    public function getCurriculumUnits(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'student_id' => 'required|exists:users,id',
            'semester'   => 'required|string',
        ]);

        $student = User::findOrFail($validated['student_id']);

        // Only auto-populate for regular students
        if ($student->is_irregular) {
            return response()->json([
                'found'        => false,
                'is_irregular' => true,
                'message'      => 'Student is irregular — units must be entered manually.',
            ]);
        }

        if (! $student->course || ! $student->year_level) {
            return response()->json([
                'found'   => false,
                'message' => 'Student has no course or year level assigned.',
            ]);
        }

        $curriculum = AssessmentService::getCurriculumUnits(
            $student->course,
            $student->year_level,
            $validated['semester']
        );

        if (empty($curriculum['subjects'])) {
            return response()->json([
                'found'   => false,
                'message' => "No subjects found for {$student->course} — {$student->year_level} — {$validated['semester']} Sem.",
            ]);
        }

        return response()->json([
            'found'              => true,
            'is_irregular'       => false,
            'billable_lec_units' => $curriculum['billable_lec_units'],
            'lab_subject_count'  => $curriculum['lab_subject_count'],
            'nstp_units'         => $curriculum['nstp_units'],
            'pathfit_units'      => $curriculum['pathfit_units'],
            'subjects'           => $curriculum['subjects'],
            'course'             => $student->course,
            'year_level'         => $student->year_level,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    //  GET LATEST ASSESSMENT DATA (legacy endpoint — kept for compat)
    // ─────────────────────────────────────────────────────────────

    public function getLatestAssessmentData(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate(['student_id' => 'required|exists:users,id']);

        $latest = StudentAssessment::where('user_id', $validated['student_id'])
            ->orderByDesc('created_at')
            ->first();

        if (! $latest) {
            return response()->json(['found' => false]);
        }

        return response()->json([
            'found'       => true,
            'lec_units'   => $latest->lec_units,
            'lab_subjects' => $latest->lab_units,
        ]);
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

        $paymentTerms = $assessment->paymentTerms()->orderBy('term_order')->get();
        $miscItems    = \App\Models\FeeSetting::whereIn('category', ['miscellaneous', 'other'])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn ($s) => ['label' => $s->label, 'amount' => (float) $s->amount])
            ->all();

        $pdf = Pdf::loadView('pdf.student-assessment', [
            'student'      => $user,
            'assessment'   => $assessment,
            'paymentTerms' => $paymentTerms,
            'miscItems'    => $miscItems,
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
        $courses    = \App\Models\Subject::distinct()->pluck('course')->sort()->values();
        $yearLevels = ['1st Year', '2nd Year', '3rd Year', '4th Year'];

        return Inertia::render('StudentFees/CreateStudent', [
            'courses'    => $courses,
            'yearLevels' => $yearLevels,
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
            'is_irregular'   => 'boolean',
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
                'is_irregular'   => (bool) ($request->is_irregular ?? false),
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
            ->with('success', 'Student account created. You can now create an assessment.');
    }

    // ─────────────────────────────────────────────────────────────
    //  EDIT STUDENT (Admin only)
    // ─────────────────────────────────────────────────────────────

    public function editStudent(Student $student): Response
    {
        // Explicitly load user relationship
        $student->load('user');
        
        if (!$student->user) {
            abort(404, 'Student user information not found.');
        }

        $courses    = Subject::distinct()->pluck('course')->sort()->values();
        $yearLevels = ['1st Year', '2nd Year', '3rd Year', '4th Year'];

        return Inertia::render('StudentFees/EditStudent', [
            'student'    => $student,
            'courses'    => $courses,
            'yearLevels' => $yearLevels,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    //  UPDATE STUDENT (Admin only)
    // ─────────────────────────────────────────────────────────────

    public function updateStudent(Request $request, Student $student)
    {
        $validated = $request->validate([
            'student_id'    => 'required|string|unique:students,student_id,' . $student->id,
            'first_name'    => 'required|string|max:255',
            'last_name'     => 'required|string|max:255',
            'middle_initial' => 'nullable|string|max:10',
            'email'         => 'required|email|unique:users,email,' . $student->user_id,
            'course'        => 'required|string|max:255',
            'year_level'    => 'required|string|max:50',
            'birthday'      => 'nullable|date',
            'phone'         => 'nullable|string|max:20',
            'address'       => 'nullable|string|max:255',
        ]);

        if ($student->user) {
            $student->user->update([
                'first_name'     => $validated['first_name'],
                'last_name'      => $validated['last_name'],
                'middle_initial' => $validated['middle_initial'],
                'email'          => $validated['email'],
                'course'         => $validated['course'],
                'year_level'     => $validated['year_level'],
                'birthday'       => $validated['birthday'],
                'phone'          => $validated['phone'],
                'address'        => $validated['address'],
            ]);
        }

        $student->update([
            'student_id' => $validated['student_id'],
        ]);

        return redirect()
            ->route('student-fees.show', $student->user_id)
            ->with('success', 'Student information updated successfully.');
    }

    public function storePayment(Request $request, int $userId)
    {
        $user = $request->user();

        if (! in_array($user->role->value ?? $user->role, ['accounting', 'admin'])) {
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
                    'payment' => 'A payment of that amount for this term was already recorded today.',
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

            return back()->with('success', 'Payment of ₱' . number_format($paidAmount, 2) . ' recorded for ' . $this->buildStudentName($student) . '.');
        } catch (\Exception $e) {
            Log::error('storePayment failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return back()->withErrors(['payment' => 'Payment processing failed: ' . $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────────────────────
    //  PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────

    private function generateUniqueAccountId(): string
    {
        do {
            $id = date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        } while (User::where('account_id', $id)->exists());

        return $id;
    }
}