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
    // Statuses that represent money still owed
    private const UNPAID_STATUSES = ['pending', 'partial', 'overdue'];

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
            ->whereIn('status', self::UNPAID_STATUSES)
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
        // BUG FIX 1: Use whereIn() to filter unpaid terms at the DB level,
        //            not in PHP after loading all terms.
        // BUG FIX 2: Removed ->take(20) hard cap. Now returns ALL students
        //            with outstanding balances, sorted by balance descending.
        //            Total count is passed so the UI can show "Showing X students".

        $outstandingStudents = StudentAssessment::where('school_year', $schoolYear)
            ->where('semester', $semester)
            ->with([
                'user',
                // Only eager-load the unpaid terms — avoids loading fully-paid
                // students' term history into memory needlessly
                'paymentTerms' => fn ($q) => $q->whereIn('status', self::UNPAID_STATUSES),
            ])
            ->get()
            ->map(function ($assessment) use ($year, $semester) {
                // Sum balances of unpaid terms only (already filtered above)
                $pendingBalance = $assessment->paymentTerms->sum('balance');

                // Get the latest paid PAY- reference for this student + term
                $latestRef = $assessment->user?->transactions()
                    ->where('kind', 'payment')
                    ->where('status', 'paid')
                    ->where('year', $year)
                    ->where('semester', $semester)
                    ->orderByDesc('paid_at')
                    ->value('reference');

                return [
                    'accountId'   => $assessment->user?->account_id ?? 'N/A',
                    'latestRef'   => $latestRef ?? '—',
                    'studentName' => $assessment->user?->name ?? 'Unknown Student',
                    'course'      => $assessment->course ?? $assessment->user?->course ?? 'N/A',
                    'total'       => (float) $assessment->total_assessment,
                    'balance'     => (float) $pendingBalance,
                    'status'      => $pendingBalance > 0 ? 'Pending' : 'Paid',
                ];
            })
            ->filter(fn ($s) => $s['balance'] > 0)
            ->sortByDesc('balance')
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
            ->whereIn('status', self::UNPAID_STATUSES)
            ->sum('balance');

        $summary = [
            'totalAssessments'      => StudentAssessment::where('school_year', $schoolYear)
                ->where('semester', $semester)
                ->count(),
            'totalAssessmentAmount' => $totalAssessmentAmount,
            'totalPaid'             => $totalPaid,
            'totalOutstanding'      => $totalOutstanding,
        ];

        $students = StudentAssessment::where('school_year', $schoolYear)
            ->where('semester', $semester)
            ->with([
                'user',
                'paymentTerms' => fn ($q) => $q->whereIn('status', self::UNPAID_STATUSES),
            ])
            ->get()
            ->map(function ($assessment) use ($year, $semester) {
                $pendingBalance = $assessment->paymentTerms->sum('balance');
                $paid           = $assessment->total_assessment - $pendingBalance;

                $latestRef = $assessment->user?->transactions()
                    ->where('kind', 'payment')
                    ->where('status', 'paid')
                    ->where('year', $year)
                    ->where('semester', $semester)
                    ->orderByDesc('paid_at')
                    ->value('reference');

                return [
                    'accountId'   => $assessment->user?->account_id ?? 'N/A',
                    'latestRef'   => $latestRef ?? '—',
                    'studentName' => $assessment->user?->name ?? 'Unknown Student',
                    'course'      => $assessment->course ?? $assessment->user?->course ?? 'N/A',
                    'total'       => (float) $assessment->total_assessment,
                    'paid'        => (float) $paid,
                    'balance'     => (float) $pendingBalance,
                    'status'      => $pendingBalance > 0 ? 'Pending' : 'Paid',
                ];
            })
            ->filter(fn ($s) => $s['balance'] > 0)
            ->sortByDesc('balance')
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