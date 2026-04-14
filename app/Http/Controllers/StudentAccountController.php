<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Models\Account;
use App\Models\Notification;
use App\Models\StudentAssessment;
use App\Models\StudentEnrollment;
use App\Models\StudentPaymentTerm;
use App\Models\Transaction;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class StudentAccountController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        $account = Account::firstOrCreate(
            ['user_id' => $user->id],
            ['balance' => 0]
        );

        $assessment = StudentAssessment::where('user_id', $user->id)
            ->where('status', 'active')
            ->latest()
            ->first();

        $allAssessments = StudentAssessment::where('user_id', $user->id)
            ->where('status', 'active')
            ->with(['paymentTerms' => fn ($q) => $q->orderBy('term_order')])
            ->orderBy('school_year')
            ->get()
            ->map(function ($a) {
                $feeBreakdown = $this->computeTotal($a->lec_units, $a->lab_units);
                return [
                    'id'                => $a->id,
                    'assessment_number' => $a->assessment_number,
                    'year_level'        => $a->year_level,
                    'semester'          => $a->semester,
                    'school_year'       => $a->school_year,
                    'course'            => $a->course ?? null,
                    'total_assessment'  => (float) $a->total_assessment,
                    'tuition_fee'       => (float) $feeBreakdown['tuitionFee'],
                    'other_fees'        => (float) ($feeBreakdown['labFee'] + $feeBreakdown['miscFee']),

                    // ── Fee Breakdown ───────────────────────────────────────
                    // Labels: Tuition Fee / Laboratory Fee / Miscellaneous Fee
                    // Miscellaneous Fee is a FLAT fee — no unit basis.
                    // 'units' is intentionally null for Miscellaneous so the
                    // AccountOverview table can hide the Units column for that row.
                    'fee_breakdown'     => [
                        [
                            'category' => 'Tuition',
                            'name'     => 'Tuition Fee',
                            'code'     => 'TUI',
                            'units'    => $a->lec_units,
                            'amount'   => $feeBreakdown['tuitionFee'],
                        ],
                        [
                            'category' => 'Laboratory',
                            'name'     => 'Laboratory Fee',
                            'code'     => 'LAB',
                            'units'    => $a->lab_units,
                            'amount'   => $feeBreakdown['labFee'],
                        ],
                        [
                            'category' => 'Miscellaneous',
                            'name'     => 'Miscellaneous Fee',
                            'code'     => 'MISC',
                            'units'    => null,   // flat fee — no unit basis
                            'amount'   => $feeBreakdown['miscFee'],
                        ],
                    ],
                    'status'     => $a->status,
                    'created_at' => $a->created_at,
                ];
            });

        $paymentTerms = $assessment
            ? StudentPaymentTerm::where('student_assessment_id', $assessment->id)
                ->orderBy('term_order')
                ->get()
            : collect();

        // Only payment transactions (kind = payment)
        $transactions = Transaction::where('user_id', $user->id)
            ->where('kind', 'payment')
            ->orderBy('created_at', 'desc')
            ->get();

        // totalPaid: authoritative value derived from payment term balances.
        // Using Transaction.amount sums is unreliable when a payment overflows
        // across multiple terms — the Transaction records the full amount paid,
        // but the term deductions may only apply to one term's balance if spillover
        // is partial. The only authoritative source is: total_assessment − outstanding.
        $totalPaid = 0;
        if ($assessment) {
            $totalAssessment = (float) $assessment->total_assessment;
            $outstanding     = (float) $paymentTerms->sum('balance');
            $totalPaid       = round(max(0, $totalAssessment - $outstanding), 2);
        }

        // Pending approval payments — shown as banners, block duplicate submissions
        $pendingApprovalPayments = $transactions
            ->where('status', PaymentStatus::AWAITING_APPROVAL->value)
            ->map(fn ($txn) => [
                'id'               => $txn->id,
                'reference'        => $txn->reference,
                'amount'           => (float) $txn->amount,
                'selected_term_id' => $txn->meta['selected_term_id'] ?? null,
                'term_name'        => $txn->meta['term_name'] ?? $txn->type ?? 'Payment',
                'created_at'       => $txn->created_at,
            ])
            ->values();

        $notifications = Notification::where(function ($q) use ($user) {
            $q->where('user_id', $user->id)
                ->orWhere('target_role', 'student');
        })
            ->where('is_active', true)
            ->whereNull('dismissed_at')
            ->get();

        // Enrolled subjects by assessment
        $assessmentTermIndex = $allAssessments->keyBy(
            fn ($a) => $a['school_year'] . '||' . $a['semester']
        );

        $enrollmentRows = StudentEnrollment::where('user_id', $user->id)
            ->where('status', 'enrolled')
            ->get(['subject_id', 'school_year', 'semester']);

        $enrolledSubjectsByAssessment = [];
        foreach ($enrollmentRows as $row) {
            $termKey = $row->school_year . '||' . $row->semester;
            if (!isset($assessmentTermIndex[$termKey])) continue;
            $assessmentId = $assessmentTermIndex[$termKey]['id'];
            if (!isset($enrolledSubjectsByAssessment[$assessmentId])) {
                $enrolledSubjectsByAssessment[$assessmentId] = [];
            }
            $enrolledSubjectsByAssessment[$assessmentId][] = (int) $row->subject_id;
        }

        return Inertia::render('Student/AccountOverview', [
            'account'                      => $account,
            'transactions'                 => $transactions->values(),
            'totalPaid'                    => $totalPaid,
            'fees'                         => [],
            'latestAssessment'             => $assessment ? array_merge(
                $assessment->toArray(),
                [
                    // Pass student enrollment/irregular status alongside assessment
                    // is_irregular lives on users, not student_assessments
                    'is_irregular'   => (bool) $user->is_irregular,
                    'middle_initial' => $user->middle_initial,
                    'student_name'   => $user->name,
                ]
            ) : null,
            'allAssessments'               => $allAssessments,
            'paymentTerms'                 => $paymentTerms->values(),
            'notifications'                => $notifications->values(),
            'pendingApprovalPayments'      => $pendingApprovalPayments,
            'enrolledSubjectsByAssessment' => $enrolledSubjectsByAssessment,
        ]);
    }

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
}