<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\Transaction;
use App\Models\User;
use App\Models\StudentPaymentTerm;
use App\Models\StudentAssessment;
use App\Models\StudentEnrollment;
use App\Models\Workflow;
use App\Enums\UserRoleEnum;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Illuminate\Support\Str;
use App\Services\AccountService;
use App\Services\WorkflowService;
use App\Services\StudentPaymentService;
use App\Events\PaymentRecorded;

class TransactionController extends Controller
{
    public function __construct(protected WorkflowService $workflowService)
    {
    }

    // ─── index ────────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $user = $request->user();

        // BUG FIX #2: Remove non-existent 'super_admin' role
        if (in_array($user->role->value, ['admin', 'accounting'])) {
            $transactions = Transaction::with('user')
                ->orderByDesc('year')
                ->orderByDesc('semester')
                ->get()
                ->groupBy(fn ($txn) => $this->getTransactionGroupKey($txn));

            $currentTerm = $this->getCurrentTerm();
            $allAssessments = [];
            $enrolledSubjectsByAssessment = [];
        } else {
            $transactions = $user->transactions()
                ->with('user')
                ->orderByDesc('year')
                ->orderByDesc('semester')
                ->get()
                ->groupBy(fn ($txn) => $this->getTransactionGroupKey($txn));

            // For students, resolve currentTerm from their latest assessment so the
            // correct term group is auto-expanded even when server-time semester
            // differs from the assessment semester.
            $latestAssessment = \App\Models\StudentAssessment::where('user_id', $user->id)
                ->where('status', 'active')
                ->latest()
                ->first();

            if ($latestAssessment) {
                $yearNum     = explode('-', $latestAssessment->school_year)[0] ?? now()->year;
                $currentTerm = trim("{$yearNum} {$latestAssessment->semester}");
            } else {
                $currentTerm = $this->getCurrentTerm();
            }

            // ── Load all assessments for the student ──
            $allAssessments = \App\Models\StudentAssessment::where('user_id', $user->id)
                ->where('status', '!=', 'cancelled')
                ->orderByDesc('created_at')
                // FIX #5: 'fee_breakdown' is not a real column. Build it from stored units.
                ->get(['id', 'school_year', 'semester', 'year_level', 'lec_units', 'lab_units', 'total_assessment'])
                ->map(fn ($a) => [
                    'id'               => $a->id,
                    'school_year'      => $a->school_year,
                    'semester'         => $a->semester,
                    'year_level'       => $a->year_level,
                    'total_assessment' => (float) $a->total_assessment,
                    // Build fee_breakdown inline from stored unit values + live config rates
                    'fee_breakdown'    => [
                        [
                            'category' => 'Tuition',
                            'name'     => 'Lecture Units',
                            'units'    => $a->lec_units,
                            'amount'   => $a->lec_units * (float) config('fees.tuition_per_lec_unit', 364.00),
                        ],
                        [
                            'category' => 'Laboratory',
                            'name'     => 'Laboratory Units',
                            'units'    => $a->lab_units,
                            'amount'   => $a->lab_units * (float) config('fees.lab_fee_per_unit', 1656.00),
                        ],
                        [
                            'category' => 'Miscellaneous',
                            'name'     => 'Fixed Fees',
                            'units'    => 1,
                            'amount'   => (float) config('fees.misc_fee_fixed', 4700.00),
                        ],
                    ],
                ])
                ->toArray();

            // ── Build enrolledSubjectsByAssessment lookup ──
            // Maps each assessment ID to an array of subject IDs confirmed in student_enrollments
            $assessmentTermIndex = collect($allAssessments)->keyBy(
                fn ($a) => $a['school_year'] . '||' . $a['semester']
            );

            $enrollmentRows = \App\Models\StudentEnrollment::where('user_id', $user->id)
                ->where('status', 'enrolled')
                ->get(['subject_id', 'school_year', 'semester']);

            $enrolledSubjectsByAssessment = [];

            foreach ($enrollmentRows as $row) {
                $termKey = $row->school_year . '||' . $row->semester;
                if (!isset($assessmentTermIndex[$termKey])) {
                    continue;
                }
                $assessmentId = $assessmentTermIndex[$termKey]['id'];
                if (!isset($enrolledSubjectsByAssessment[$assessmentId])) {
                    $enrolledSubjectsByAssessment[$assessmentId] = [];
                }
                $enrolledSubjectsByAssessment[$assessmentId][] = (int) $row->subject_id;
            }
        }

        return Inertia::render('Transactions/Index', [
            // BUG FIX #7: Remove redundant 'auth' prop — already in shared data via HandleInertiaRequests
            'transactionsByTerm' => $transactions,
            'account'            => $user->account,
            'currentTerm'        => $currentTerm,
            'allAssessments'     => $allAssessments,
            'enrolledSubjectsByAssessment' => $enrolledSubjectsByAssessment,
        ]);
    }

    // ─── create / store ───────────────────────────────────────────────────────

    public function create()
    {
        $users = User::select('id', 'first_name', 'last_name', 'middle_initial', 'email')->get();

        return Inertia::render('Transactions/Create', [
            'users' => $users,
        ]);
    }

    public function store(Request $request)
    {
        // BUG FIX #2: Remove non-existent 'super_admin' role
        if (!in_array($request->user()->role->value, ['admin', 'accounting'])) {
            abort(403, 'Unauthorized action.');
        }

        $data = $request->validate([
            'user_id'         => 'required|exists:users,id',
            'amount'          => 'required|numeric|min:0.01',
            'type'            => 'required|in:charge,payment',
            'payment_channel' => 'nullable|string',
        ]);

        $transaction = Transaction::create([
            'user_id'         => $data['user_id'],
            'reference'       => 'SYS-' . Str::upper(Str::random(8)),
            'kind'            => $data['type'],
            'type'            => 'Manual Entry',
            'amount'          => $data['amount'],
            'status'          => $data['type'] === 'payment'
                ? PaymentStatus::PAID->value
                : PaymentStatus::PENDING->value,
            'payment_channel' => $data['payment_channel'] ?? null,
            'year'            => (string) now()->year,
            'semester'        => $this->getCurrentSemesterLabel(),
            'meta'            => [
                'description' => 'Manual entry by ' . $request->user()->name,
            ],
        ]);

        AccountService::recalculate($transaction->user);

        return redirect()->route('transactions.index')
            ->with('success', 'Transaction created successfully!');
    }

    // ─── show ─────────────────────────────────────────────────────────────────

    public function show(Transaction $transaction)
    {
        return Inertia::render('Transactions/Show', [
            'transaction' => $transaction->load('user'),
            'account'     => $transaction->user->account,
        ]);
    }

    // ─── receipt ─────────────────────────────────────────────────────────────

    public function receipt(Request $request, Transaction $transaction)
    {
        $authUser = $request->user();
        // BUG FIX #2: Remove non-existent 'super_admin' role
        $isStaff  = in_array($authUser->role->value, ['admin', 'accounting']);

        if (!$isStaff && $transaction->user_id !== $authUser->id) {
            abort(403, 'You do not have permission to view this receipt.');
        }

        // Only fully paid transactions may generate a receipt PDF.
        if ($transaction->status === PaymentStatus::AWAITING_APPROVAL->value) {
            abort(403, 'Receipt is not available yet. Your payment is still awaiting accounting verification.');
        }

        if ($transaction->kind !== 'payment') {
            abort(400, 'Receipts are only available for payment transactions.');
        }

        $targetUser = $transaction->user->load('account', 'student');

        $currentBalance = (float) ($targetUser->account->balance ?? 0);
        $paymentAmount  = (float) $transaction->amount;

        if ($transaction->status === PaymentStatus::PAID->value) {
            $balanceBefore    = round($currentBalance + $paymentAmount, 2);
            $remainingBalance = round($currentBalance, 2);
        } else {
            $balanceBefore    = round($currentBalance, 2);
            $remainingBalance = round($currentBalance - $paymentAmount, 2);
        }

        $pdf = Pdf::loadView('pdf.receipt', [
            'transaction'      => $transaction,
            'student'          => $targetUser,
            'balanceBefore'    => $balanceBefore,
            'remainingBalance' => $remainingBalance,
        ]);

        $pdf->setPaper('A4', 'portrait');

        $studentId = $targetUser->account_id ?? 'unknown';
        $ref       = str_replace(['/', ' '], '-', $transaction->reference ?? (string) $transaction->id);
        $filename  = "receipt-{$studentId}-{$ref}.pdf";

        return $pdf->download($filename);
    }

    // ─── download ─────────────────────────────────────────────────────────────

    public function download(Request $request)
    {
        $authUser = $request->user();
        // BUG FIX #2: Remove non-existent 'super_admin' role
        $isStaff  = in_array($authUser->role->value, ['admin', 'accounting']);

        if ($isStaff && $request->filled('user_id')) {
            $targetUser = User::with('account', 'student')->findOrFail((int) $request->user_id);
        } else {
            $targetUser = $authUser->load('account', 'student');
        }

        $query = Transaction::where('user_id', $targetUser->id)
            ->with('fee')
            ->orderBy('year', 'desc')
            ->orderBy('created_at', 'desc');

        $termKey = $request->input('term');
        if ($termKey && $termKey !== 'All Terms') {
            $parts   = explode(' ', $termKey, 2);
            $termYear = $parts[0] ?? null;
            $termSem  = $parts[1] ?? null;

            if ($termYear && $termSem) {
                $query->where('year', $termYear)
                      ->where('semester', $termSem);
            }
        }

        // Only include confirmed paid payments in the PDF.
        // kind='charge' rows no longer exist — assessment totals come from StudentAssessment.
        $transactions = $query->get()->filter(
            fn ($txn) => $txn->kind === 'payment' && $txn->status === PaymentStatus::PAID->value
        );

        if ($transactions->isEmpty()) {
            abort(403, 'No confirmed payments available for this term. Awaiting-approval payments cannot be downloaded yet.');
        }

        // Derive total assessed from StudentAssessment for the requested term.
        $assessmentQuery = StudentAssessment::where('user_id', $targetUser->id)
            ->where('status', 'active');

        if ($termKey && $termKey !== 'All Terms') {
            $parts    = explode(' ', $termKey, 2);
            $termYear = $parts[0] ?? null;
            $termSem  = $parts[1] ?? null;
            if ($termYear && $termSem) {
                $syEnd = (int) $termYear + 1;
                $assessmentQuery->where('school_year', "{$termYear}-{$syEnd}")
                                ->where('semester', $termSem);
            }
        }

        $totalCharges = (float) $assessmentQuery->sum('total_assessment');
        $totalPaid    = $transactions->sum('amount');
        $netBalance   = round($totalCharges - $totalPaid, 2);

        $pdf = Pdf::loadView('pdf.transactions', [
            'transactions' => $transactions,
            'student'      => $targetUser,
            'termKey'      => $termKey ?: 'All Terms',
            'totalCharges' => $totalCharges,
            'totalPaid'    => $totalPaid,
            'netBalance'   => $netBalance,
        ]);

        $pdf->setPaper('A4', 'portrait');

        $accountId = $targetUser->account_id ?? 'unknown';
        $termSlug  = $termKey ? str_replace([' ', '/'], '-', $termKey) : 'all-terms';
        $filename  = "transactions-{$accountId}-{$termSlug}.pdf";

        return $pdf->download($filename);
    }

    // ─── payNow ───────────────────────────────────────────────────────────────

    /**
     * Process a payment submission from a student or staff.
     *
     * Students' payments require accounting approval:
     *   1. Transaction created with status = 'awaiting_approval'
     *   2. WorkflowInstance + WorkflowApproval records are created
     *   3. Accounting users see the pending approval in /approvals
     *
     * Staff (admin/accounting) bypass approval and are marked 'paid' immediately.
     */
    public function payNow(Request $request)
    {
        $user      = $request->user();
        $isStudent = $user->role === UserRoleEnum::STUDENT;

        $allowedMethods = $isStudent
            ? ['gcash', 'bank_transfer', 'credit_card', 'debit_card']
            : ['cash', 'gcash', 'bank_transfer', 'credit_card', 'debit_card'];

        $data = $request->validate([
            'amount'           => 'required|numeric|min:0.01',
            'payment_method'   => ['required', 'string', Rule::in($allowedMethods)],
            'paid_at'          => 'required|date',
            'description'      => 'nullable|string|max:255',
            'selected_term_id' => 'required|exists:student_payment_terms,id',
        ]);

        try {
            $term = StudentPaymentTerm::findOrFail((int) $data['selected_term_id']);

            // Security: prevent cross-user payment — term must belong to this user
            // Access user_id through assessment relationship
            $termUserId = $term->assessment?->user_id;
            if (!$termUserId || (int) $termUserId !== (int) $user->id) {
                throw ValidationException::withMessages(['payment' => 'Invalid payment term selected.']);
            }

            $termBalance = round((float) $term->balance, 2);
            $paidAmount  = round((float) $data['amount'], 2);

            if ($termBalance <= 0) {
                throw ValidationException::withMessages(['payment' => 'This payment term has already been fully paid.']);
            }

            // No upper-limit on payment amount — payments that exceed the selected term's balance
            // are allocated sequentially across all unpaid terms with any excess documented.
            // This aligns with StudentFeeController pattern for admin/accounting side.
            //
            // if ($paidAmount > $termBalance) { ... } ← REMOVED
            // Overpayment is allowed and handled by StudentPaymentService::processPayment()

            // Prevent duplicate awaiting_approval submissions for the same term
            if ($isStudent) {
                $alreadyPending = Transaction::where('user_id', $user->id)
                    ->where('status', PaymentStatus::AWAITING_APPROVAL->value)
                    ->where('kind', 'payment')
                    ->whereJsonContains('meta->selected_term_id', (int) $data['selected_term_id'])
                    ->exists();

                if ($alreadyPending) {
                    throw ValidationException::withMessages(['payment' => 'A payment for this term is already awaiting approval.']);
                }
            }

            $paymentService   = new StudentPaymentService();
            $requiresApproval = $isStudent;

            // Always derive year and semester from the term's own assessment so
            // the transaction is grouped under the correct term in history.
            $assessment      = $term->assessment;
            $transactionYear = $assessment
                ? explode('-', $assessment->school_year)[0]
                : (string) now()->year;
            $transactionSem  = $assessment?->semester ?? $this->getCurrentSemesterLabel();

            // For students: create pending transaction and redirect to proof upload
            if ($isStudent) {
                $transaction = Transaction::create([
                    'user_id'          => $user->id,
                    'kind'             => 'payment',
                    'type'             => 'payment_submission',
                    'amount'           => $paidAmount,
                    'status'           => PaymentStatus::PENDING->value,
                    'payment_channel'  => $data['payment_method'],
                    'paid_at'          => $data['paid_at'],
                    'year'             => $transactionYear,
                    'semester'         => $transactionSem,
                    'meta'             => [
                        'payment_method'   => $data['payment_method'],
                        'description'      => $data['description'] ?? null,
                        'selected_term_id' => (int) $data['selected_term_id'],
                        'term_name'        => $term->term_name,
                        'requires_proof'   => true,
                    ],
                ]);

                return redirect()->route('payment.proof.show', $transaction->id)
                    ->with('success', 'Payment submitted. Please upload proof of payment.');
            }

            // For staff: process payment immediately without proof
            $result = $paymentService->processPayment($user, $paidAmount, [
                'payment_method'   => $data['payment_method'],
                'paid_at'          => $data['paid_at'],
                'description'      => $data['description'] ?? null,
                'selected_term_id' => (int) $data['selected_term_id'],
                'term_name'        => $term->term_name,
                'year'             => $transactionYear,
                'semester'         => $transactionSem,
            ], false);

            // Post-processing for immediately-approved payments (staff side)
            event(new PaymentRecorded(
                $user,
                $result['transaction_id'] ?? null,
                (float) $data['amount'],
                $result['transaction_reference'] ?? 'N/A'
            ));
            // Year-level promotion is handled automatically by AccountService::recalculate()
            // which is called inside StudentPaymentService::processPayment().

            return back()->with('success', 'Payment recorded successfully!');

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('payNow failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return back()->withErrors(['payment' => 'Payment processing failed: ' . $e->getMessage()]);
        }
    }

    // ─── destroy ─────────────────────────────────────────────────────────────

    public function destroy(Transaction $transaction)
    {
        $transaction->delete();

        return redirect()->route('transactions.index')
            ->with('success', 'Transaction deleted successfully.');
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Find the active payment_approval workflow and start a workflow instance
     * for the given transaction so accounting users can approve or reject it.
     */
    private function startPaymentApprovalWorkflow(int $transactionId, int $userId): void
    {
        $workflow = Workflow::active()
            ->where('type', 'payment_approval')
            ->first();

        if (!$workflow) {
            throw new \Exception(
                'No active payment_approval workflow found. ' .
                'Please run: php artisan db:seed --class=PaymentApprovalWorkflowSeeder'
            );
        }

        $transaction = Transaction::findOrFail($transactionId);
        $this->workflowService->startWorkflow($workflow, $transaction, $userId);
    }

    private function getTransactionGroupKey(Transaction $txn): string
    {
        if (!empty($txn->year) && !empty($txn->semester)) {
            return "{$txn->year} {$txn->semester}";
        }

        if (empty($txn->year) && empty($txn->semester)) {
            return $this->getCurrentTerm();
        }

        $label = trim("{$txn->year} {$txn->semester}");
        return $label ?: $this->getCurrentTerm();
    }

    private function getCurrentTerm(): string
    {
        return now()->year . ' ' . $this->getCurrentSemesterLabel();
    }

    private function getCurrentSemesterLabel(): string
    {
        $month = now()->month;
        return ($month >= 6 && $month <= 10) ? '1st Sem' : '2nd Sem';
    }
}