<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset Code</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f6f8; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f4f6f8; padding: 40px 10px;">
        <tr>
            <td align="center">
                <!-- Main Container -->
                <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 480px; background-color: #ffffff; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); padding: 40px 30px; text-align: center;">

                    <!-- Header/Icon area -->
                    <tr>
                        <td align="center" style="padding-bottom: 20px;">
                            <div style="display: inline-block; background-color: #eef2ff; border-radius: 50%; padding: 16px;">
                                <!-- Key Icon -->
                                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#4f46e5" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"></path>
                                </svg>
                            </div>
                        </td>
                    </tr>

                    <!-- Title -->
                    <tr>
                        <td style="padding-bottom: 12px;">
                            <h2 style="margin: 0; color: #111827; font-size: 22px; font-weight: 700; letter-spacing: -0.5px;">Reset Your Password</h2>
                        </td>
                    </tr>

                    <!-- Instruction Text -->
                    <tr>
                        <td style="padding-bottom: 24px;">
                            <p style="margin: 0; color: #6b7280; font-size: 15px; line-height: 1.5;">Enter the One-Time Password (OTP) below to confirm it's you, then choose a new password. Do not share this code with anyone.</p>
                        </td>
                    </tr>

                    <!-- OTP Code Box -->
                    <tr>
                        <td style="padding-bottom: 24px;">
                            <div style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px; display: inline-block; width: 100%; box-sizing: border-box;">
                                <span style="font-family: 'Courier New', Courier, monospace; font-size: 36px; font-weight: 800; color: #4f46e5; letter-spacing: 8px; margin-left: 8px;">{{ $otp }}</span>
                            </div>
                        </td>
                    </tr>

                    <!-- Timer Warning -->
                    <tr>
                        <td style="padding-bottom: 30px;">
                            <p style="margin: 0; color: #ef4444; font-size: 13px; font-weight: 600;">
                                ⏱️ This code is valid for {{ $minutes }} minutes.
                            </p>
                        </td>
                    </tr>

                    <!-- Divider -->
                    <tr>
                        <td style="border-top: 1px solid #f1f5f9; padding-top: 20px;">
                            <p style="margin: 0; color: #9ca3af; font-size: 13px; line-height: 1.5;">
                                If you didn't request a password reset, you can safely ignore this email — your password will stay unchanged.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
