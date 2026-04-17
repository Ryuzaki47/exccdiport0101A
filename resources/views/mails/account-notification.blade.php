<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>{{ $title }} — CCDI Account Portal</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            background-color: #f4f4f5;
            font-family: 'Segoe UI', Helvetica, Arial, sans-serif;
            color: #18181b;
        }
        .wrapper {
            max-width: 600px;
            margin: 40px auto;
            background: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08);
        }
        .header {
            background-color: #1d4ed8;
            padding: 24px 32px;
            color: #ffffff;
        }
        .header h1 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
            letter-spacing: 0.3px;
        }
        .header p {
            margin: 4px 0 0;
            font-size: 13px;
            opacity: 0.85;
        }
        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 99px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 12px;
        }
        .badge-info    { background: #dbeafe; color: #1d4ed8; }
        .badge-success { background: #dcfce7; color: #15803d; }
        .badge-warning { background: #fef9c3; color: #854d0e; }
        .badge-error   { background: #fee2e2; color: #b91c1c; }
        .body {
            padding: 32px;
        }
        .body p {
            margin: 0 0 16px;
            font-size: 15px;
            line-height: 1.6;
            color: #3f3f46;
        }
        .action-btn {
            display: inline-block;
            margin-top: 8px;
            padding: 12px 28px;
            background-color: #1d4ed8;
            color: #ffffff !important;
            text-decoration: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
        }
        .divider {
            border: none;
            border-top: 1px solid #e4e4e7;
            margin: 28px 0;
        }
        .footer {
            padding: 20px 32px;
            background: #f4f4f5;
            font-size: 12px;
            color: #71717a;
            text-align: center;
            line-height: 1.6;
        }
        .footer a {
            color: #1d4ed8;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="header">
            <h1>CCDI Account Portal</h1>
            <p>Computer Communication Development Institute</p>
        </div>
        <div class="body">
            <span class="badge badge-{{ $type ?? 'info' }}">{{ ucfirst($type ?? 'info') }}</span>

            <p>Hello, <strong>{{ $studentName }}</strong>.</p>

            <p>{{ $message }}</p>

            @if ($actionUrl && $actionLabel)
                <a href="{{ $actionUrl }}" class="action-btn">{{ $actionLabel }}</a>
            @endif

            <hr class="divider" />

            <p style="font-size: 13px; color: #71717a;">
                You can also
                <a href="{{ $dashboardUrl }}" style="color: #1d4ed8;">visit your dashboard</a>
                to review your account details.
            </p>
        </div>
        <div class="footer">
            &copy; {{ date('Y') }} Computer Communication Development Institute.
            All rights reserved.<br />
            This is an automated message from the CCDI Account Portal.
            Please do not reply to this email.
        </div>
    </div>
</body>
</html>