<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Carrier Portal Invitation</title>
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
                        <td style="padding-bottom: 20px;">
                            <p style="margin: 0; color: #6b7280; font-size: 15px; line-height: 1.6;">
                                You have been added to the DollarTraq carrier portal for
                                <strong style="color: #111827;">{{ $invitation->carrierCompany?->displayName() }}</strong>
                                as <strong style="color: #111827;">{{ $invitation->role->name }}</strong>.
                                Your account is ready — sign in with the credentials below.
                            </p>
                        </td>
                    </tr>

                    <!-- Credentials -->
                    <tr>
                        <td style="padding-bottom: 20px;">
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px;">
                                <tr>
                                    <td style="padding-bottom: 12px;">
                                        <div style="color: #9ca3af; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; padding-bottom: 4px;">Email</div>
                                        <div style="color: #111827; font-size: 15px; font-weight: 600;">{{ $invitation->email }}</div>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <div style="color: #9ca3af; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; padding-bottom: 4px;">Temporary password</div>
                                        <div style="font-family: 'Courier New', Courier, monospace; font-size: 22px; font-weight: 800; color: #4f46e5; letter-spacing: 2px;">{{ $temporaryPassword }}</div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td align="center" style="padding-bottom: 24px;">
                            <a href="{{ $portalUrl }}"
                               style="background:#2563eb; color:#ffffff; padding:13px 26px; text-decoration:none; border-radius:6px; font-size:15px; font-weight:600; display:inline-block;">
                                Sign in
                            </a>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding-bottom: 24px;">
                            <p style="margin: 0; color: #ef4444; font-size: 13px; font-weight: 600;">
                                You will be asked to choose your own password the first time you sign in.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="border-top: 1px solid #f1f5f9; padding-top: 20px;">
                            <p style="margin: 0; color: #9ca3af; font-size: 13px; line-height: 1.5;">
                                Invited by {{ $invitation->creator?->displayName() }}.
                                If you were not expecting this, you can ignore this email or contact them directly.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
