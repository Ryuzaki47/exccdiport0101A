<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Payment Receipt — {{ $transaction->reference }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            color: #222;
            margin: 0;
            padding: 24px;
        }

        /* ── School Header ─────────────────────────────────── */
        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #222;
            padding-bottom: 14px;
        }
        .header h1 {
            margin: 0 0 4px;
            font-size: 15px;
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
            font-size: 14px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .header .ref-label {
            font-size: 10px;
            color: #555;
            margin-top: 3px;
            font-family: monospace;
        }

        /* ── Paid stamp ─────────────────────────────────────── */
        .paid-stamp {
            display: inline-block;
            border: 3px solid #065f46;
            color: #065f46;
            font-size: 20px;
            font-weight: bold;
            letter-spacing: 4px;
            padding: 4px 14px;
            border-radius: 4px;
            transform: rotate(-6deg);
            margin-top: 6px;
            opacity: 0.85;
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
        .info-table td { padding: 4px 6px; vertical-align: top; }
        .info-table .lbl { font-weight: bold; width: 30%; color: #444; }

        /* ── Payment highlight box ──────────────────────────── */
        .payment-box {
            border: 2px solid #059669;
            border-radius: 6px;
            background: #f0fff4;
            padding: 16px 20px;
            margin-bottom: 18px;
        }
        .payment-box .pay-for {
            font-size: 12px;
            color: #374151;
            margin-bottom: 4px;
        }
        .payment-box .pay-label {
            font-size: 11px;
            font-weight: bold;
            color: #065f46;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }
        .payment-box .pay-amount {
            font-size: 28px;
            font-weight: bold;
            color: #065f46;
            margin-bottom: 6px;
        }
        .payment-box .pay-meta {
            font-size: 10px;
            color: #555;
            line-height: 1.8;
        }
        .payment-box .pay-meta span {
            font-weight: bold;
            color: #222;
        }

        /* ── Balance summary ───────────────────────────────── */
        .balance-box {
            border: 1px solid #d1d5db;
            border-radius: 4px;
            padding: 10px 14px;
            background: #f9fafb;
        }
        .balance-row {
            display: flex;
            justify-content: space-between;
            padding: 3px 0;
            font-size: 10px;
        }
        .balance-row.total {
            border-top: 1px solid #9ca3af;
            margin-top: 4px;
            padding-top: 6px;
            font-size: 12px;
            font-weight: bold;
        }
        .balance-row.credit { color: #065f46; }

        /* ── Status badges ─────────────────────────────────── */
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 9px;
            font-weight: bold;
        }
        .badge-paid     { background: #d1fae5; color: #065f46; }
        .badge-pending  { background: #fef9c3; color: #713f12; }
        .badge-awaiting { background: #dbeafe; color: #1e40af; }

        /* ── Footer ────────────────────────────────────────── */
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 9px;
            color: #888;
            border-top: 1px solid #eee;
            padding-top: 8px;
        }
        .footer .note {
            font-style: italic;
            margin-top: 4px;
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
    <p class="doc-title">Official Payment Receipt</p>
    <p class="ref-label">Reference No.: {{ $transaction->reference }}</p>
    @if($transaction->status === 'paid')
        <div><span class="paid-stamp">PAID</span></div>
    @endif
</div>

{{-- ══ Student Information ══ --}}
<div class="section">
    <div class="section-title">Student Information</div>
    <table class="info-table">
        <tr>
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

{{-- ══ Payment Detail ══ --}}
@php
    // What this payment is for — pulled from meta->term_name first (set by StudentPaymentService),
    // then meta->description, then transaction type, then a generic fallback.
    $paymentFor  = $transaction->meta['term_name']    // e.g. "Prelim"
        ?? $transaction->meta['description']           // fallback description
        ?? $transaction->type                          // category string
        ?? 'General Payment';

    $paymentDesc = $transaction->meta['description']
        ?? null;

    $method = $transaction->payment_channel
        ? strtoupper(str_replace('_', ' ', $transaction->payment_channel))
        : 'N/A';

    $academicTerm = trim(($transaction->year ?? '') . ' ' . ($transaction->semester ?? ''))
        ?: 'N/A';

    $paidDate = $transaction->paid_at
        ? $transaction->paid_at->format('F d, Y')
        : $transaction->created_at->format('F d, Y');
@endphp

<div class="section">
    <div class="section-title">Payment Details</div>
    <div class="payment-box">
        <p class="pay-for">Payment for:</p>
        <p class="pay-label">{{ $paymentFor }}</p>
        <p class="pay-amount">&#8369;{{ number_format($transaction->amount, 2) }}</p>
        <p class="pay-meta">
            Academic Term: <span>{{ $academicTerm }}</span><br>
            Payment Method: <span>{{ $method }}</span><br>
            Date Paid: <span>{{ $paidDate }}</span><br>
            Reference No.: <span style="font-family:monospace;">{{ $transaction->reference ?? '—' }}</span><br>
            Status:
            <span class="badge badge-{{ $transaction->status === 'paid' ? 'paid' : ($transaction->status === 'awaiting_approval' ? 'awaiting' : 'pending') }}">
                {{ $transaction->status === 'awaiting_approval' ? 'Awaiting Verification' : ucfirst($transaction->status) }}
            </span>
        </p>
        @if($paymentDesc && $paymentDesc !== $paymentFor)
            <p class="pay-meta" style="margin-top:6px; border-top:1px solid #a7f3d0; padding-top:6px;">
                Note: <span>{{ $paymentDesc }}</span>
            </p>
        @endif
    </div>
</div>

{{-- ══ Account Balance ══ --}}
<div class="section">
    <div class="section-title">Account Balance</div>
    <div class="balance-box">
        <div class="balance-row">
            <span>This Payment Amount:</span>
            <span style="color:#065f46; font-weight:bold;">&#8369;{{ number_format($transaction->amount, 2) }}</span>
        </div>
        <div class="balance-row">
            <span>Total Balance Before This Payment:</span>
            <span>&#8369;{{ number_format($balanceBefore, 2) }}</span>
        </div>
        @if($remainingBalance < 0)
            <div class="balance-row total credit">
                <span>Remaining Balance (Credit):</span>
                <span>−&#8369;{{ number_format(abs($remainingBalance), 2) }}</span>
            </div>
            <p style="font-size:9px; color:#065f46; margin-top:4px; font-style:italic;">
                ✔ You have a credit of &#8369;{{ number_format(abs($remainingBalance), 2) }} that will be applied to your next assessment.
            </p>
        @elseif($remainingBalance == 0)
            <div class="balance-row total" style="color:#065f46;">
                <span>Remaining Balance:</span>
                <span>&#8369;0.00 — Fully Paid</span>
            </div>
        @else
            <div class="balance-row total">
                <span>Remaining Balance:</span>
                <span>&#8369;{{ number_format($remainingBalance, 2) }}</span>
            </div>
        @endif
    </div>
</div>

{{-- ══ Footer ══ --}}
<div class="footer">
    <p>Generated on {{ now()->format('F d, Y h:i A') }}</p>
    <p class="note">This is a computer-generated receipt. No signature is required.</p>
    <p class="note">Please keep this for your records.</p>
</div>

</body>
</html>