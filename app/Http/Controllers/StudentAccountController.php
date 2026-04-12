<?php
namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Notification;
use App\Models\Payment;
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
            ->with(['paymentTerms' => fn($q) => $q->orderBy('term_order')])
            ->orderBy('school_year')
            ->get()
            ->map(function($a) {
                $feeBreakdown = $this->computeTotal($a->lec_units, $a->lab_units);
                return [
                    'id'               => $a->id,
                    'assessment_number'=> $a->assessment_number,
                    'year_level'       => $a->year_level,
                    'semester'         => $a->semester,
                    'school_year'      => $a->school_year,
                    'course'           => $a->course,
                    'total_assessment' => (float) $a->total_assessment,
                    'tuition_fee'      => (float) $feeBreakdown['tuitionFee'],
                    'other_fees'       => (float) ($feeBreakdown['labFee'] + $feeBreakdown['miscFee']),
                    'fee_breakdown'    => [
                        [
                            'category' => 'Tuition',
                            'name'     => 'Lecture Units',
                            'code'     => 'TUI',
                            'units'    => $a->lec_units,
                            'amount'   => $feeBreakdown['tuitionFee'],
                        ],
                        [
                            'category' => 'Laboratory',
                            'name'     => 'Laboratory Units',
                            'code'     => 'LAB',
                            'units'    => $a->lab_units,
                            'amount'   => $feeBreakdown['labFee'],
                        ],
                        [
                            'category' => 'Miscellaneous',
                            'name'     => 'Registration Fee',
                            'code'     => 'REG',
                            'units'    => 1,
                            'amount'   => $feeBreakdown['miscFee'],
                        ],
                    ],
                    'status'           => $a->status,
                    'created_at'       => $a->created_at,
                ];
            });

        $paymentTerms = $assessment
            ? StudentPaymentTerm::where('student_assessment_id', $assessment->id)
                ->orderBy('term_order')
                ->get()
            : collect();

        $transactions = Transaction::where('user_id', $user->id)
            ->where('kind', 'payment')
            ->orderBy('created_at', 'desc')
            ->get();

        // Calculate totalPaid for the current (latest) assessment only
        // This is the sum of all successful payment transactions (status='paid') 
        // for the current assessment, excluding charge transactions.
        $totalPaid = 0;
        if ($assessment) {
            $totalPaid = (float) $transactions
                ->where('status', 'paid')
                ->filter(function ($txn) use ($assessment) {
                    $assessmentId = data_get($txn->meta, 'assessment_id');
                    return $assessmentId === $assessment->id;
                })
                ->sum('amount');
        }

        $notifications = Notification::where(function($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->orWhere('target_role', 'student');
            })
            ->where('is_active', true)
            ->whereNull('dismissed_at')
            ->get();

        // enrolledSubjectsByAssessment
        $assessmentTermIndex = $allAssessments->keyBy(
            fn($a) => $a['school_year'] . '||' . $a['semester']
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
            'transactions'                 => $transactions,
            'totalPaid'                    => $totalPaid,
            'fees'                         => [],
            'latestAssessment'             => $assessment,
            'allAssessments'               => $allAssessments,
            'paymentTerms'                 => $paymentTerms,
            'notifications'                => $notifications,
            'pendingApprovalPayments'      => [],
            'enrolledSubjectsByAssessment' => $enrolledSubjectsByAssessment,
        ]);
    }

    /**
     * Compute fee totals from lecture and lab units.
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
}
