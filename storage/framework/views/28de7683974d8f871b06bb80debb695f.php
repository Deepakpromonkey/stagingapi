<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login OTP</title>
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
                                <!-- Lock Icon -->
                                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#4f46e5" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                                </svg>
                            </div>
                        </td>
                    </tr>

                    <!-- Title -->
                    <tr>
                        <td style="padding-bottom: 12px;">
                            <h2 style="margin: 0; color: #111827; font-size: 22px; font-weight: 700; letter-spacing: -0.5px;">Security Verification</h2>
                        </td>
                    </tr>

                    <!-- Instruction Text -->
                    <tr>
                        <td style="padding-bottom: 24px;">
                            <p style="margin: 0; color: #6b7280; font-size: 15px; line-height: 1.5;">Use the One-Time Password (OTP) below to complete your login. Do not share this code with anyone.</p>
                        </td>
                    </tr>

                    <!-- OTP Code Box -->
                    <tr>
                        <td style="padding-bottom: 24px;">
                            <div style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px; display: inline-block; width: 100%; box-sizing: border-box;">
                                <span style="font-family: 'Courier New', Courier, monospace; font-size: 36px; font-weight: 800; color: #4f46e5; letter-spacing: 8px; margin-left: 8px;"><?php echo e($otp); ?></span>
                            </div>
                        </td>
                    </tr>

                    <!-- Timer Warning -->
                    <tr>
                        <td style="padding-bottom: 30px;">
                            <p style="margin: 0; color: #ef4444; font-size: 13px; font-weight: 600;">
                                ⏱️ This code is valid for 10 minutes.
                            </p>
                        </td>
                    </tr>

                    <!-- Divider -->
                    <tr>
                        <td style="border-top: 1px solid #f1f5f9; padding-top: 20px;">
                            <p style="margin: 0; color: #9ca3af; font-size: 13px; line-height: 1.5;">
                                If you didn't request this login, please ignore this email or contact support if you have concerns.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
<?php /**PATH /Applications/XAMPP/xamppfiles/htdocs/dollarTraq-main/resources/views/emails/login-otp.blade.php ENDPATH**/ ?>