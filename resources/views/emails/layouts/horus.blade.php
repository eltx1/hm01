<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Horus Media')</title>
</head>
<body style="margin:0;padding:0;background:#050b1e;color:#f6f8ff;font-family:Arial,sans-serif;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%;background:#050b1e;border-collapse:collapse;">
    <tr><td align="center" style="padding:32px 16px;">
        <table role="presentation" width="620" cellspacing="0" cellpadding="0" style="width:100%;max-width:620px;border:1px solid rgba(255,255,255,.12);border-radius:18px;background:#07132e;border-collapse:separate;overflow:hidden;">
            <tr><td style="padding:28px 30px 18px;border-bottom:1px solid rgba(241,183,51,.24);">
                <a href="{{ config('app.url') }}" style="display:inline-block;text-decoration:none;color:#f6f8ff;" aria-label="Horus Media Control Plane">
                    <x-brand.full-logo style="display:block;width:118px;max-width:100%;height:auto;margin:0;" />
                </a>
                <p style="margin:16px 0 0;color:#ffd66b;font-size:11px;font-weight:700;letter-spacing:.13em;text-transform:uppercase;">Horus Media Control Plane</p>
            </td></tr>
            <tr><td style="padding:30px;">
                @yield('content')
            </td></tr>
            <tr><td style="padding:18px 30px;border-top:1px solid rgba(255,255,255,.09);color:#9da9c2;font-size:12px;line-height:1.6;">
                Horus Media · Advertising, monetization, and intelligent programmatic growth.<br>
                Sign in to confirm current status and authorization.
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
