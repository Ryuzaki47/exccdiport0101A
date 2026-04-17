<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\StudentAssessment;
use App\Models\StudentPaymentTerm;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Barryvdh\DomPDF\Facade\Pdf;

class FinancialReportsController extends Controller
{
    public function index(Request $request)
    {
        $schoolYear = $request->get('school_year', now()->year . '-' . (now()->year + 1));
        $semester   = $request->get('semester', '1st Sem');
        $year       = (int) explode('-', $schoolYear)[0];

        // ── Summary stats ────────────────────────────────────────────────────

        $totalAssessments = StudentAssessment::where('school_year', $schoolYear)
            ->where('semester', $semester)
            ->count();

        $totalAssessmentAmount = StudentAssessment::where('school_year', $schoolYear)
            ->where('semester', $semester)
            ->sum('total_assessment');

        $totalPaid = Transaction::where('kind', 'payment')
            ->where('status', 'paid')
            ->where('year', $year)
            ->where('semester', $semester)
            ->sum('amount');

        $totalOutstanding = StudentPaymentTerm::whereHas('assessment', function ($q) use ($schoolYear, $semester) {
            $q->where('school_year', $schoolYear)
              ->where('semester', $semester);
        })
            ->where('status', 'pending')
            ->sum('balance');

        // ── Charts ───────────────────────────────────────────────────────────

        $byCourseSummary = StudentAssessment::where('school_year', $schoolYear)
            ->where('semester', $semester)
            ->selectRaw('course, COUNT(*) as student_count, SUM(total_assessment) as total')
            ->groupBy('course')
            ->orderBy('total', 'desc')
            ->get();

        $byMonthSummary = Transaction::where('kind', 'payment')
            ->where('status', 'paid')
            ->where('year', $year)
            ->where('semester', $semester)
            ->selectRaw('MONTH(created_at) as month, SUM(amount) as total')
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(fn ($item) => [
                'month' => Carbon::createFromFormat('m', $item->month)->format('M'),
                'total' => $item->total,
            ]);

        // ── Payment method breakdown ─────────────────────────────────────────

        $paymentMethods = Transaction::where('kind', 'payment')
            ->where('status', 'paid')
            ->where('year', $year)
            ->where('semester', $semester)
            ->selectRaw("COALESCE(payment_channel, 'Unspecified') as method, COUNT(*) as count, SUM(amount) as total")
            ->groupBy('payment_channel')
            ->orderByDesc('total')
            ->get();

        // ── Outstanding balances table ───────────────────────────────────────
        // FIX: was ->with(['student.user', ...]) — StudentAssessment has no
        // student() relationship. The model points directly to User via user().

        $outstandingStudents = StudentAssessment::where('school_year', $schoolYear)
            ->where('semester', $semester)
            ->with(['user', 'paymentTerms'])
            ->get()
            ->map(function ($assessment) {
                $pendingBalance = $assessment->paymentTerms
                    ->where('status', 'pending')
                    ->sum('balance');

                return [
                    'accountId'   => $assessment->user?->account_id ?? 'N/A',
                    'studentName' => $assessment->user?->name ?? 'Unknown Student',
                    'course'      => $assessment->course,
                    'total'       => (float) $assessment->total_assessment,
                    'balance'     => (float) $pendingBalance,
                    'status'      => $pendingBalance > 0 ? 'Pending' : 'Paid',
                ];
            })
            ->filter(fn ($s) => $s['balance'] > 0)
            ->sortByDesc('balance')
            ->take(20)
            ->values();

        $schoolYears = $this->getSchoolYears();
        $semesters   = ['1st Sem', '2nd Sem', 'Summer'];

        return Inertia::render('Accounting/FinancialReports', [
            'summary' => [
                'totalAssessments'      => $totalAssessments,
                'totalAssessmentAmount' => $totalAssessmentAmount,
                'totalPaid'             => $totalPaid,
                'totalOutstanding'      => $totalOutstanding,
            ],
            'charts' => [
                'byCourse' => $byCourseSummary,
                'byMonth'  => $byMonthSummary,
            ],
            'paymentMethods'      => $paymentMethods,
            'outstandingStudents' => $outstandingStudents,
            'filters' => [
                'schoolYear' => $schoolYear,
                'semester'   => $semester,
            ],
            'schoolYears' => $schoolYears,
            'semesters'   => $semesters,
        ]);
    }

    public function export(Request $request)
    {
        $schoolYear = $request->get('school_year', now()->year . '-' . (now()->year + 1));
        $semester   = $request->get('semester', '1st Sem');
        $year       = (int) explode('-', $schoolYear)[0];

        $totalPaid = Transaction::where('kind', 'payment')
            ->where('status', 'paid')
            ->where('year', $year)
            ->where('semester', $semester)
            ->sum('amount');

        $totalAssessmentAmount = StudentAssessment::where('school_year', $schoolYear)
            ->where('semester', $semester)
            ->sum('total_assessment');

        $totalOutstanding = StudentPaymentTerm::whereHas('assessment', function ($q) use ($schoolYear, $semester) {
            $q->where('school_year', $schoolYear)
              ->where('semester', $semester);
        })
            ->where('status', 'pending')
            ->sum('balance');

        $summary = [
            'totalAssessments'      => StudentAssessment::where('school_year', $schoolYear)
                ->where('semester', $semester)
                ->count(),
            'totalAssessmentAmount' => $totalAssessmentAmount,
            'totalPaid'             => $totalPaid,
            'totalOutstanding'      => $totalOutstanding,
        ];

        // FIX: same relationship fix applied here — student.user → user
        $students = StudentAssessment::where('school_year', $schoolYear)
            ->where('semester', $semester)
            ->with(['user', 'paymentTerms'])
            ->get()
            ->map(function ($assessment) {
                $balance = $assessment->paymentTerms->sum('balance');
                $paid    = $assessment->total_assessment - $balance;

                return [
                    'accountId'   => $assessment->user?->account_id ?? 'N/A',
                    'studentName' => $assessment->user?->name ?? 'Unknown Student',
                    'course'      => $assessment->course,
                    'total'       => (float) $assessment->total_assessment,
                    'paid'        => (float) $paid,
                    'balance'     => (float) $balance,
                    'status'      => $balance > 0 ? 'Pending' : 'Paid',
                ];
            })
            ->filter(fn ($s) => $s['balance'] > 0)
            ->sortByDesc('balance')
            ->take(20)
            ->values();

        $pdf = Pdf::loadView('pdf.financial-report', [
            'schoolYear'  => $schoolYear,
            'semester'    => $semester,
            'summary'     => $summary,
            'students'    => $students,
            'generatedAt' => now(),
        ]);

        $filename = 'financial-report-'
            . $schoolYear . '-'
            . str_replace(' ', '-', $semester)
            . '.pdf';

        return $pdf->download($filename);
    }

    private function getSchoolYears(): array
    {
        $years       = [];
        $currentYear = now()->year;

        for ($i = $currentYear - 3; $i <= $currentYear + 2; $i++) {
            $years[] = "{$i}-" . ($i + 1);
        }

        return $years;
    }
}