<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $heading }}</title>
</head>
<body style="margin:0;padding:0;background:#0d0f12;font-family:-apple-system,'Segoe UI',Tahoma,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                       style="max-width:560px;background:#14171c;border-radius:20px;overflow:hidden;border:1px solid rgba(255,255,255,.08);">
                    <tr>
                        <td style="padding:28px 28px 0;text-align:center;">
                            @if ($logoUrl)
                                <img src="{{ $logoUrl }}" alt="{{ $clubName }}" height="48" style="max-height:48px;">
                            @elseif ($clubName)
                                <h1 style="margin:0;color:{{ $brandColor }};font-size:20px;">{{ $clubName }}</h1>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 28px 28px;">
                            <h2 style="margin:0 0 12px;color:#fff;font-size:18px;font-weight:600;">{{ $heading }}</h2>
                            <div style="color:#cbd2dc;font-size:15px;line-height:1.7;white-space:pre-line;">{{ $body }}</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 28px 28px;">
                            <div style="height:1px;background:rgba(255,255,255,.08);margin-bottom:16px;"></div>
                            <p style="margin:0;color:#6b7280;font-size:12px;">{{ $clubName ?? config('app.name') }}</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
