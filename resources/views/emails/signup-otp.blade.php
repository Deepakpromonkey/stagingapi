<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify your email address</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f6f8; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f4f6f8; padding: 40px 10px;">
        <tr>
            <td align="center">
                <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 480px; background-color: #ffffff; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); padding: 40px 30px; text-align: center;">

                    <tr>
                        <td align="center" style="padding-bottom: 20px;">
                            <div style="display: inline-block; background-color: #eef2ff; border-radius: 50%; padding: 16px;">
                                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#4f46e5" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                                    <polyline points="22,6 12,13 2,6"></polyline>
                                </svg>
                            </div>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding-bottom: 12px;">
                            <h2 style="margin: 0; color: #111827; font-size: 22px; font-weight: 700; letter-spacing: -0.5px;">Verify your email address</h2>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding-bottom: 24px;">
                            <p style="margin: 0; color: #6b7280; font-size: 15px; line-height: 1.5;">Enter the code below to confirm this address and finish creating your account. Do not share it with anyone.</p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding-bottom: 24px;">
                            <div style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px; display: inline-block; width: 100%; box-sizing: border-box;">
                                <span style="font-family: 'Courier New', Courier, monospace; font-size: 36px; font-weight: 800; color: #4f46e5; letter-spacing: 8px; margin-left: 8px;">{{ $otp }}</span>
                            </div>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding-bottom: 8px;">
                            <p style="margin: 0; color: #6b7280; font-size: 14px; line-height: 1.5;">This code expires in {{ $minutes }} minutes.</p>
                        </td>
                    </tr>

                    <tr>
                        <td>
                            <p style="margin: 0; color: #9ca3af; font-size: 13px; line-height: 1.5;">If you did not start creating an account, you can ignore this email.</p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
