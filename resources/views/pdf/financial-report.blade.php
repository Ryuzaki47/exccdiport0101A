<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Financial Report — {{ $schoolYear }} {{ $semester }}</title>
    <style>
        @page {
            margin: 14mm 16mm 14mm 16mm;
            size: A4 landscape;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 10px;
            color: #1a1a1a;
            width: 100%;
        }

        .header {
            text-align: center;
            margin-bottom: 16px;
            border-bottom: 2px solid #4f46e5;
            padding-bottom: 12px;
        }
        .header .school-name  { font-size: 16px; font-weight: bold; color: #111827; }
        .header .report-title { font-size: 11px; color: #4f46e5; margin-top: 3px; font-weight: 600; }
        .header .meta         { font-size: 9px;  color: #9ca3af; margin-top: 3px; }

        .summary-table { width: 100%; border-collapse: separate; border-spacing: 6px; margin-bottom: 18px; }
        .summary-cell {
            width: 25%;
            padding: 10px 12px;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            text-align: center;
        }
        .summary-cell .label { font-size: 8px; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 4px; }
        .summary-cell .value { font-size: 15px; font-weight: bold; color: #111827; }
        .summary-cell .value.green { color: #059669; }
        .summary-cell .value.red   { color: #dc2626; }
        .summary-cell .sub { font-size: 8px; color: #9ca3af; margin-top: 2px; }

        .section-title {
            font-size: 10px;
            font-weight: bold;
            color: #374151;
            margin-bottom: 8px;
            margin-top: 18px;
            border-left: 3px solid #4f46e5;
            padding-left: 8px;
        }

        table.data-table { width: 100%; border-collapse: collapse; font-size: 9.5px; }
        table.data-table thead tr { background: #f3f4f6; }
        table.data-table th {
            padding: 7px 10px;
            text-align: left;
            font-weight: 700;
            color: #6b7280;
            text-transform: uppercase;
            font-size: 8px;
            letter-spacing: 0.05em;
            border-bottom: 2px solid #e5e7eb;
            white-space: nowrap;
        }
        table.data-table td {
            padding: 7px 10px;
            border-bottom: 1px solid #f3f4f6;
            color: #374151;
            vertical-align: middle;
        }
        table.data-table tr:last-child td { border-bottom: none; }
        table.data-table tr:nth-child(even) td { background: #fafafa; }

        .text-right  { text-align: right; }
        .text-center { text-align: center; }

        .col-acct   { width: 10%; white-space: nowrap; }
        .col-ref    { width: 13%; white-space: nowrap; font-family: monospace; font-size: 9px; color: #4f46e5; }
        .col-name   { width: 16%; }
        .col-course { width: 28%; }
        .col-amt    { width: 12%; }
        .col-bal    { width: 12%; }
        .col-status { width: 11%; }

        .badge { display: inline-block; padding: 2px 8px; font-size: 8.5px; font-weight: 600; border-radius: 3px; }
        .badge-amber { background: #fef3c7; color: #92400e; }
        .badge-green { background: #d1fae5; color: #065f46; }

        .empty { text-align: center; padding: 20px; color: #9ca3af; font-size: 11px; }

        .footer {
            margin-top: 24px;
            border-top: 1px solid #e5e7eb;
            padding-top: 8px;
            width: 100%;
            font-size: 8px;
            color: #9ca3af;
        }
        .footer-left  { float: left; }
        .footer-right { float: right; }
        .clearfix::after { content: ''; display: block; clear: both; }
    </style>
</head>
<body>

    <div class="header">
        <div class="school-name">CCDI Account Portal</div>
        <div class="report-title">Financial Report &mdash; {{ $schoolYear }} &bullet; {{ $semester }}</div>
        <div class="meta">Generated {{ now()->format('F j, Y \a\t g:i A') }}</div>
    </div>

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

    <div class="section-title">
        Top Outstanding Balances ({{ $semester }} &bullet; {{ $schoolYear }})
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th class="col-acct">Account ID</th>
                <th class="col-ref">Latest Reference</th>
                <th class="col-name">Student Name</th>
                <th class="col-course">Course</th>
                <th class="col-amt text-right">Total Assessment</th>
                <th class="col-bal text-right">Outstanding Balance</th>
                <th class="col-status text-center">Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse($students as $student)
                <tr>
                    <td class="col-acct" style="font-family: monospace; font-size:9px;">
                        {{ $student['accountId'] }}
                    </td>
                    <td class="col-ref">{{ $student['latestRef'] }}</td>
                    <td class="col-name">{{ $student['studentName'] }}</td>
                    <td class="col-course">{{ $student['course'] ?? 'N/A' }}</td>
                    <td class="col-amt text-right">
                        &#8369;{{ number_format($student['total'], 2) }}
                    </td>
                    <td class="col-bal text-right" style="color:#dc2626; font-weight:700;">
                        &#8369;{{ number_format($student['balance'], 2) }}
                    </td>
                    <td class="col-status text-center">
                        <span class="badge {{ $student['status'] === 'Paid' ? 'badge-green' : 'badge-amber' }}">
                            {{ $student['status'] }}
                        </span>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="empty">
                        No outstanding balances for {{ $semester }} {{ $schoolYear }}.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

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