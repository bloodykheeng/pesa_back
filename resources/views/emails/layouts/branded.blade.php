<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <title>@yield('title')</title>
</head>
{{-- Tables and inline styles: what email apps (Gmail, Outlook, phone mail) render reliably. --}}
<body style="margin:0;padding:0;background:#f3f6f4;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#0f172a;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f6f4;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden;">
                    <tr>
                        <td style="background:#059669;padding:18px 24px;">
                            <span style="display:inline-block;width:32px;height:32px;line-height:32px;border-radius:8px;background:#ffffff;color:#059669;font-weight:700;text-align:center;font-size:18px;vertical-align:middle;">e</span>
                            <span style="color:#ffffff;font-size:18px;font-weight:700;vertical-align:middle;margin-left:8px;">Elevate Pesa</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px 24px 8px;font-size:15px;line-height:1.6;">
                            @yield('content')
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 24px 24px;font-size:12px;line-height:1.5;color:#64748b;border-top:1px solid #e2e8f0;">
                            @yield('footer', 'You get this email because you have an Elevate Pesa account.')
                            <br>&copy; {{ date('Y') }} Elevate Pesa
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
