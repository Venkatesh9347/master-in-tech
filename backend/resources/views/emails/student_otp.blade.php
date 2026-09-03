<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MasterInTech Verification Code</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background-color: #f8fafc;
            margin: 0;
            padding: 24px;
            color: #1e293b;
        }
        .container {
            max-width: 520px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }
        .header {
            background: #0f172a;
            padding: 28px 32px;
            text-align: center;
        }
        .logo {
            font-size: 22px;
            font-weight: 800;
            color: #ffffff;
            letter-spacing: -0.5px;
        }
        .logo-highlight {
            color: #38bdf8;
        }
        .body-content {
            padding: 32px;
        }
        .greeting {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 8px;
            color: #0f172a;
        }
        .text {
            font-size: 14px;
            line-height: 1.6;
            color: #475569;
            margin-bottom: 24px;
        }
        .otp-box {
            background: #f1f5f9;
            border: 2px dashed #2563eb;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            margin-bottom: 24px;
        }
        .otp-code {
            font-family: 'Courier New', Courier, monospace;
            font-size: 36px;
            font-weight: 800;
            letter-spacing: 8px;
            color: #1d4ed8;
        }
        .warning-badge {
            display: inline-block;
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #b91c1c;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            margin-top: 12px;
        }
        .security-notice {
            background: #f8fafc;
            border-left: 4px solid #2563eb;
            padding: 12px 16px;
            font-size: 12px;
            color: #64748b;
            line-height: 1.5;
            border-radius: 4px;
        }
        .footer {
            border-top: 1px solid #e2e8f0;
            padding: 20px 32px;
            font-size: 12px;
            color: #94a3b8;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">Master<span class="logo-highlight">In</span>Tech</div>
        </div>
        <div class="body-content">
            <div class="greeting">Hello, {{ $userName }}</div>
            <p class="text">
                You requested a secure login to your <strong>MasterInTech Student Portal</strong>. Use the 6-digit verification code below to complete your authentication.
            </p>

            <div class="otp-box">
                <div class="otp-code">{{ $otp }}</div>
                <div class="warning-badge">⚠️ Expires in {{ $expirySeconds }} seconds • Single-use only</div>
            </div>

            <div class="security-notice">
                <strong>Security Alert:</strong> If you did not initiate this login request, please disregard this email. Never share this code with anyone. MasterInTech staff will never ask for your verification code.
            </div>
        </div>
        <div class="footer">
            © {{ date('Y') }} MasterInTech Education Portal. All rights reserved.
        </div>
    </div>
</body>
</html>
