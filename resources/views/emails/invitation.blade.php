{{--
    No credential appears in this email by design. It used to print a
    temporary password next to a "Sign in" button, which is the exact shape
    of a credential-harvesting mail -- Microsoft Defender quarantined it as
    phishing regardless of SPF/DKIM/DMARC all passing. A one-time link that
    expires carries nothing worth stealing.

    Table layout and inline styles throughout: this has to survive Outlook.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invitation</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f6f8; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f4f6f8; padding: 40px 10px;">
        <tr>
            <td align="center">
                <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 520px; background-color: #ffffff; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); padding: 40px 30px;">

                    <tr>
                        <td style="padding-bottom: 8px;">
                            <h2 style="margin: 0; color: #111827; font-size: 22px; font-weight: 700; letter-spacing: -0.5px;">Hello {{ $invitation->first_name }},</h2>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding-bottom: 24px;">
                            <p style="margin: 0; color: #6b7280; font-size: 15px; line-height: 1.6;">
                                {{ $invitation->creator->first_name }} {{ $invitation->creator->last_name }}
                                has added you to
                                <strong style="color: #111827;">{{ $invitation->company->company_name }}</strong>
                                on dollarTraq as <strong style="color: #111827;">{{ $invitation->role->name }}</strong>.
                                Choose a password to finish setting up your account.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td align="center" style="padding-bottom: 20px;">
                            <a href="{{ $acceptUrl }}"
                               style="background:#2563eb; color:#ffffff; padding:13px 26px; text-decoration:none; border-radius:6px; font-size:15px; font-weight:600; display:inline-block;">
                                Choose your password
                            </a>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding-bottom: 24px;">
                            <p style="margin: 0 0 6px 0; color: #9ca3af; font-size: 12px; line-height: 1.5;">
                                Or paste this address into your browser:
                            </p>
                            <p style="margin: 0; color: #4b5563; font-size: 12px; line-height: 1.5; word-break: break-all;">
                                {{ $acceptUrl }}
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="border-top: 1px solid #f1f5f9; padding-top: 20px;">
                            <p style="margin: 0; color: #9ca3af; font-size: 13px; line-height: 1.5;">
                                This link works once and expires on
                                {{ $invitation->expires_at->format('j F Y') }}.
                                If you weren't expecting it, you can ignore this email — no account can be
                                opened without it.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
