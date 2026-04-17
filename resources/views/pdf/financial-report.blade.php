<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Financial Report — {{ $schoolYear }} {{ $semester }}</title>
    <style>
        /* ── Page setup ──────────────────────────────────────────────────────── */
        @page {
            margin: 18mm 15mm 18mm 15mm;
            size: A4 portrait;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 11px;
            color: #1a1a1a;
            width: 100%;
        }

        /* ── Header ──────────────────────────────────────────────────────────── */
        .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #4f46e5; padding-bottom: 14px; }
        .header .school-name { font-size: 17px; font-weight: bold; color: #111827; }
        .header .report-title { font-size: 12px; color: #4f46e5; margin-top: 4px; font-weight: 600; }
        .header .meta { font-size: 10px; color: #9ca3af; margin-top: 4px; }

        /* ── Summary: use a plain table instead of display:table (dompdf compat) */
        .summary-table { width: 100%; border-collapse: separate; border-spacing: 5px; margin-bottom: 20px; }
        .summary-cell { width: 25%; padding: 12px 10px; background: #f9fafb; border: 1px solid #e5e7eb; text-align: center; }
        .summary-cell .label { font-size: 9px; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 5px; }
        .summary-cell .value { font-size: 14px; font-weight: bold; color: #111827; }
        .summary-cell .value.green { color: #059669; }
        .summary-cell .value.red   { color: #dc2626; }
        .summary-cell .sub { font-size: 9px; color: #9ca3af; margin-top: 3px; }

        /* ── Section title ───────────────────────────────────────────────────── */
        .section-title {
            font-size: 11px;
            font-weight: bold;
            color: #374151;
            margin-bottom: 10px;
            margin-top: 22px;
            border-left: 3px solid #4f46e5;
            padding-left: 8px;
        }

        /* ── Data table ──────────────────────────────────────────────────────── */
        table.data-table { width: 100%; border-collapse: collapse; font-size: 10px; }
        table.data-table thead tr { background: #f3f4f6; }
        table.data-table th {
            padding: 8px 9px;
            text-align: left;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            font-size: 9px;
            letter-spacing: 0.04em;
            border-bottom: 2px solid #e5e7eb;
        }
        table.data-table td { padding: 7px 9px; border-bottom: 1px solid #f3f4f6; color: #374151; }
        table.data-table tr:last-child td { border-bottom: none; }
        table.data-table tr:nth-child(even) td { background: #fafafa; }
        .text-right  { text-align: right; }
        .text-center { text-align: center; }

        /* ── Badge ───────────────────────────────────────────────────────────── */
        .badge { display: inline-block; padding: 2px 7px; font-size: 9px; font-weight: 600; }
        .badge-amber { background: #fef3c7; color: #92400e; }

        /* ── Empty ───────────────────────────────────────────────────────────── */
        .empty { text-align: center; padding: 22px; color: #9ca3af; font-size: 11px; }

        /* ── Footer ──────────────────────────────────────────────────────────── */
        .footer {
            margin-top: 32px;
            border-top: 1px solid #e5e7eb;
            padding-top: 10px;
            width: 100%;
            font-size: 9px;
            color: #9ca3af;
        }
        .footer-left  { float: left; }
        .footer-right { float: right; }
        .clearfix::after { content: ''; display: block; clear: both; }
    </style>
</head>
<body>

    {{-- ── Header ─────────────────────────────────────────────────────────── --}}
    <div class="header">
        <div class="school-name">CCDI Account Portal</div>
        <div class="report-title">Financial Report — {{ $schoolYear }} &bullet; {{ $semester }}</div>
        <div class="meta">Generated {{ now()->format('F j, Y \a\t g:i A') }}</div>
    </div>

    {{-- ── Summary cards (real <table> for dompdf width reliability) ───────── --}}
    <table class="summary-table">
        <tr>
            <td class="summary-cell">
                <div class="label">Total Assessments</div>
                <div class="value">{{ $summary['totalAssessments'] }}</div>
                <div class="sub">students assessed</div>
            </td>
            <td class="summary-cell">
                <div class="label">Total Assessment Amount</div>
                <div class="value">&#8369;{{ number_format($summary['totalAssessmentAmount'], 2) }}</div>
                @php
                    $collectionRate = $summary['totalAssessmentAmount'] > 0
                        ? round(($summary['totalPaid'] / $summary['totalAssessmentAmount']) * 100)
                        : 0;
                @endphp
                <div class="sub">{{ $collectionRate }}% collected</div>
            </td>
            <td class="summary-cell">
                <div class="label">Total Paid</div>
                <div class="value green">&#8369;{{ number_format($summary['totalPaid'], 2) }}</div>
                <div class="sub">collected so far</div>
            </td>
            <td class="summary-cell">
                <div class="label">Outstanding</div>
                <div class="value red">&#8369;{{ number_format($summary['totalOutstanding'], 2) }}</div>
                <div class="sub">remaining balance</div>
            </td>
        </tr>
    </table>

    {{-- ── Outstanding balances table ─────────────────────────────────────── --}}
    <div class="section-title">
        Top Outstanding Balances ({{ $semester }} &bullet; {{ $schoolYear }})
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th>Account ID</th>
                <th>Student Name</th>
                <th>Course</th>
                <th class="text-right">Total Assessment</th>
                <th class="text-right">Outstanding Balance</th>
                <th class="text-center">Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse($students as $student)
                <tr>
                    <td>{{ $student['accountId'] }}</td>
                    <td>{{ $student['studentName'] }}</td>
                    <td>{{ $student['course'] ?? 'N/A' }}</td>
                    <td class="text-right">&#8369;{{ number_format($student['total'], 2) }}</td>
                    <td class="text-right" style="color:#dc2626; font-weight:700;">
                        &#8369;{{ number_format($student['balance'], 2) }}
                    </td>
                    <td class="text-center">
                        <span class="badge badge-amber">Pending</span>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="empty">
                        No outstanding balances for {{ $semester }} {{ $schoolYear }}.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{-- ── Footer ──────────────────────────────────────────────────────────── --}}
    <div class="footer clearfix">
        <div class="footer-left">
            CCDI Account Portal &bullet; Financial Report &bullet; {{ $schoolYear }} {{ $semester }}
        </div>
        <div class="footer-right">
            Printed: {{ now()->format('Y-m-d H:i') }}
        </div>
    </div>

</body>
</html>