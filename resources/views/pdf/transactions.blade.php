<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Transaction Receipt — {{ $student->account_id ?? 'Student' }}</title>
    <style>
        @page { margin: 12mm 10mm; }
        * { box-sizing: border-box; }
        body {
            font-family: Arial, sans-serif;
            font-size: 10px;
            color: #222;
            margin: 0;
            padding: 14px;
        }

        /* ── School Header ─────────────────────────────────── */
        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #222;
            padding-bottom: 12px;
        }
        .header h1 {
            margin: 0 0 4px;
            font-size: 16px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .header .address {
            font-size: 9px;
            color: #555;
            margin: 2px 0;
        }
        .header .doc-title {
            margin-top: 10px;
            font-size: 13px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .header .term-label {
            font-size: 11px;
            color: #444;
            margin-top: 3px;
        }

        /* ── Section ───────────────────────────────────────── */
        .section { margin-bottom: 18px; }
        .section-title {
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid #ccc;
            padding-bottom: 4px;
            margin-bottom: 8px;
            color: #333;
        }

        /* ── Info table ────────────────────────────────────── */
        .info-table { width: 100%; border-collapse: collapse; }
        .info-table td { padding: 3px 6px; vertical-align: top; }
        .info-table .lbl { font-weight: bold; width: 25%; color: #444; }

        /* ── Data table ────────────────────────────────────── */
        table.data {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
        }
        table.data th,
        table.data td {
            border: 1px solid #ccc;
            padding: 6px 8px;
            font-size: 10px;
        }
        table.data th {
            background: #f2f2f2;
            font-weight: bold;
            text-transform: uppercase;
        }
        .text-right  { text-align: right; }
        .text-center { text-align: center; }

        .row-charge  { background: #fff5f5; }
        .row-payment { background: #f0fff4; }
        .row-total   { font-weight: bold; background: #f9f9f9; }
        .row-grand   { font-weight: bold; background: #e8e8e8; font-size: 12px; }

        /* ── Summary box ───────────────────────────────────── */
        .summary-box {
            border: 2px solid #333;
            border-radius: 4px;
            padding: 10px 14px;
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 3px 0;
        }
        .summary-row.grand {
            border-top: 2px solid #333;
            margin-top: 4px;
            padding-top: 6px;
            font-size: 14px;
            font-weight: bold;
        }
        .summary-row.credit { color: #065f46; }
        .credit-note {
            margin-top: 6px;
            font-size: 9px;
            color: #065f46;
            font-style: italic;
        }

        /* ── Status badges ─────────────────────────────────── */
        .badge {
            display: inline-block;
            padding: 1px 6px;
            border-radius: 20px;
            font-size: 9px;
            font-weight: bold;
        }
        .badge-paid     { background: #d1fae5; color: #065f46; }
        .badge-pending  { background: #fef9c3; color: #713f12; }
        .badge-awaiting { background: #dbeafe; color: #1e40af; }
        .badge-cancelled{ background: #f3f4f6; color: #374151; }
        .badge-charge   { background: #fee2e2; color: #991b1b; }
        .badge-payment  { background: #d1fae5; color: #065f46; }

        /* ── Footer ────────────────────────────────────────── */
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 9px;
            color: #888;
            border-top: 1px solid #eee;
            padding-top: 8px;
        }
    </style>
</head>
<body>

{{-- ══ School Header ══ --}}
<div class="header">
    <h1>{{ strtoupper(config('school.name', 'Computer Communication Development Institute')) }}</h1>
    <p class="address">
        {{ config('school.main_address') }}
        @if(config('school.annex_address'))
            &nbsp;|&nbsp; {{ config('school.annex_address') }}
        @endif
    </p>
    <p class="address">
        Website: {{ config('school.website') }}
        &nbsp;|&nbsp; Hotline: {{ config('school.hotline') }}
        &nbsp;|&nbsp; CP: {{ config('school.mobile') }}
    </p>
    <p class="doc-title">Transaction Receipt</p>
    <p class="term-label">Term: {{ $termKey }}</p>
</div>

{{-- ══ Student Information ══ --}}
<div class="section">
    <div class="section-title">Student Information</div>
    <table class="info-table">
        <tr>
            {{--
                $student is the User model.
                account_id on User = school-assigned student number (e.g. 2025-0001).
                account->account_number = internal formatted account number (e.g. ACC-2025-0001).
            --}}
            <td class="lbl">Student No.:</td>
            <td>{{ $student->account_id ?? '—' }}</td>
            <td class="lbl">Account No.:</td>
            <td>{{ $student->account->account_number ?? '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Full Name:</td>
            <td>{{ $student->name }}</td>
            <td class="lbl">Course:</td>
            <td>{{ $student->course ?? '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Year Level:</td>
            <td>{{ $student->year_level ?? '—' }}</td>
            <td class="lbl">Email:</td>
            <td>{{ $student->email }}</td>
        </tr>
    </table>
</div>

{{-- ══ Transaction List ══ --}}
<div class="section">
    <div class="section-title">Transactions — {{ $termKey }}</div>

    @if($transactions->isEmpty())
        <p style="text-align:center; color:#999; padding: 12px 0;">No confirmed transactions found for this term.</p>
    @else
    <table class="data">
        <thead>
            <tr>
                <th>Date</th>
                <th>Payment Date</th>
                <th>Reference</th>
                <th>Description</th>
                <th>Method</th>
                <th class="text-center">Kind</th>
                <th class="text-center">Status</th>
                <th class="text-right">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($transactions as $txn)
            @php
                $rowClass = $txn->kind === 'charge' ? 'row-charge' : 'row-payment';

                // Resolve a human-readable description:
                // 1. meta.description (set by StudentPaymentService & manual entries)
                // 2. meta.fee_name    (set by old fee assignment code)
                // 3. type             (category string on the transaction)
                // 4. Fallback dash
                $desc = $txn->meta['description']
                    ?? $txn->meta['fee_name']
                    ?? $txn->type
                    ?? '—';

                // Payment date: use paid_at for confirmed payments, otherwise dash
                $paymentDate = ($txn->kind === 'payment' && $txn->paid_at)
                    ? $txn->paid_at->format('M d, Y')
                    : '—';
            @endphp
            <tr class="{{ $rowClass }}">
                <td>{{ $txn->created_at->format('M d, Y') }}</td>
                <td>{{ $paymentDate }}</td>
                <td style="font-family:monospace;">{{ $txn->reference ?? '—' }}</td>
                <td>{{ $desc }}</td>
                <td>
                    {{ $txn->payment_channel
                        ? strtoupper(str_replace('_', ' ', $txn->payment_channel))
                        : '—' }}
                </td>
                <td class="text-center">
                    <span class="badge {{ $txn->kind === 'charge' ? 'badge-charge' : 'badge-payment' }}">
                        {{ ucfirst($txn->kind) }}
                    </span>
                </td>
                <td class="text-center">
                    @php
                        $badgeClass = match($txn->status) {
                            'paid'              => 'badge-paid',
                            'pending'           => 'badge-pending',
                            'awaiting_approval' => 'badge-awaiting',
                            'cancelled'         => 'badge-cancelled',
                            default             => '',
                        };
                        $statusLabel = match($txn->status) {
                            'awaiting_approval' => 'Awaiting',
                            default             => ucfirst($txn->status),
                        };
                    @endphp
                    <span class="badge {{ $badgeClass }}">{{ $statusLabel }}</span>
                </td>
                <td class="text-right" style="{{ $txn->kind === 'charge' ? 'color:#991b1b;' : 'color:#065f46;' }}">
                    {{ $txn->kind === 'charge' ? '+' : '−' }}₱{{ number_format($txn->amount, 2) }}
                </td>
            </tr>
            @endforeach

            {{-- Subtotals --}}
            <tr class="row-total">
                <td colspan="7" class="text-right">Total Assessed (Charges):</td>
                <td class="text-right" style="color:#991b1b;">₱{{ number_format($totalCharges, 2) }}</td>
            </tr>
            <tr class="row-total">
                <td colspan="7" class="text-right">Total Paid:</td>
                <td class="text-right" style="color:#065f46;">₱{{ number_format($totalPaid, 2) }}</td>
            </tr>
        </tbody>
    </table>
    @endif
</div>

{{-- ══ Balance Summary ══ --}}
<div class="section">
    <div class="section-title">Balance Summary</div>
    <div class="summary-box">
        <div class="summary-row">
            <span>Total Assessment (Charges):</span>
            <span>₱{{ number_format($totalCharges, 2) }}</span>
        </div>
        <div class="summary-row">
            <span>Total Paid:</span>
            <span>₱{{ number_format($totalPaid, 2) }}</span>
        </div>

        @if($netBalance < 0)
            {{-- Student has overpaid — show credit --}}
            <div class="summary-row grand credit">
                <span>Credit Balance:</span>
                <span>₱{{ number_format(abs($netBalance), 2) }}</span>
            </div>
            <p class="credit-note">
                ✔ You have a credit of ₱{{ number_format(abs($netBalance), 2) }}.
                This will be applied to your next assessment.
            </p>
        @elseif($netBalance == 0)
            <div class="summary-row grand" style="color:#065f46;">
                <span>Remaining Balance:</span>
                <span>₱0.00 — Fully Paid</span>
            </div>
        @else
            <div class="summary-row grand">
                <span>Remaining Balance:</span>
                <span>₱{{ number_format($netBalance, 2) }}</span>
            </div>
        @endif
    </div>
</div>

{{-- ══ Footer ══ --}}
<div class="footer">
    <p>Generated on {{ now()->format('F d, Y h:i A') }}</p>
    <p>This is a computer-generated document. Please keep this for your records.</p>
</div>

</body>
</html>