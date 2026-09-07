<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Certificate of Completion</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            color: #0f172a;
            background: #ffffff;
        }
        .frame {
            border: 10px double #d97706;
            border-radius: 24px;
            margin: 28px;
            padding: 40px 48px;
            text-align: center;
        }
        .badge {
            color: #b45309;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 4px;
            text-transform: uppercase;
            margin-bottom: 14px;
        }
        h1 {
            font-size: 34px;
            font-weight: 900;
            margin-bottom: 22px;
        }
        .presented-to {
            color: #64748b;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 3px;
            margin-bottom: 8px;
        }
        .recipient {
            display: inline-block;
            border-bottom: 2px solid #94a3b8;
            padding: 10px 48px;
            margin: 14px 0 22px;
            font-size: 30px;
            font-weight: 800;
            color: #1e3a8a;
        }
        .body {
            color: #475569;
            font-size: 14px;
            line-height: 1.7;
            max-width: 560px;
            margin: 0 auto 18px;
        }
        .course-title {
            font-size: 24px;
            font-weight: 800;
            margin-bottom: 28px;
        }
        .details {
            display: inline-flex;
            gap: 56px;
            width: 560px;
            text-align: left;
            border-top: 1px solid #e2e8f0;
            padding-top: 22px;
            margin-top: 6px;
            font-size: 13px;
        }
        .details .label {
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #94a3b8;
            margin-bottom: 4px;
        }
        .details .value {
            font-weight: 800;
            color: #0f172a;
        }
        .id {
            font-family: 'DejaVu Sans Mono', monospace;
            color: #1d4ed8;
        }
        .footer {
            margin-top: 34px;
            font-size: 10px;
            color: #94a3b8;
            letter-spacing: 1px;
        }
    </style>
</head>
<body>
    <div class="frame">
        <div class="badge">Official Credential of Completion</div>
        <h1>Certificate of Completion</h1>

        <p class="presented-to">This is proudly presented to</p>
        <div class="recipient">{{ $recipientName }}</div>

        <p class="body">
            for successfully completing all required modules, projects, quizzes,
            and practical assignments for the course:
        </p>

        <div class="course-title">{{ $courseTitle }}</div>

        <div class="details">
            <div>
                <div class="label">Instructor</div>
                <div class="value">{{ $instructor }}</div>
            </div>
            <div style="text-align:center">
                <div class="label">Issue Date</div>
                <div class="value">
                    {{ $issuedAt ? $issuedAt->format('F j, Y') : '' }}
                </div>
            </div>
            <div style="text-align:right">
                <div class="label">Credential ID</div>
                <div class="value id">{{ $certificateCode }}</div>
            </div>
        </div>

        <div class="footer">Master In Tech &middot; verify on masterintech.com</div>
    </div>
</body>
</html>