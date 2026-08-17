<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enterprise plan enquiry</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f6f8; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f4f6f8; padding: 40px 10px;">
        <tr>
            <td align="center">
                <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 560px; background-color: #ffffff; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); padding: 40px 30px;">

                    <tr>
                        <td style="padding-bottom: 8px;">
                            <h2 style="margin: 0; color: #111827; font-size: 22px; font-weight: 700; letter-spacing: -0.5px;">Enterprise plan enquiry</h2>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding-bottom: 20px;">
                            <p style="margin: 0; color: #6b7280; font-size: 15px; line-height: 1.6;">
                                <strong style="color: #111827;">{{ $company->company_name }}</strong>
                                asked about Enterprise pricing from the plan selection screen.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding-bottom: 20px;">
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px;">

                                @php
                                    $rows = [
                                        'Company' => $company->company_name,
                                        'Business type' => $company->businessTypeLabel(),
                                        'DOT number' => $company->dot_number ?: 'Not provided',
                                        'Contact' => $details['contact_name'],
                                        'Email' => $details['contact_email'],
                                        'Phone' => $details['contact_phone'],
                                        'Monthly loads' => $details['monthly_loads'],
                                    ];
                                @endphp

                                @foreach ($rows as $label => $value)
                                    @if (filled($value))
                                        <tr>
                                            <td style="padding-bottom: 12px;">
                                                <div style="color: #9ca3af; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; padding-bottom: 4px;">{{ $label }}</div>
                                                <div style="color: #111827; font-size: 15px; font-weight: 600;">{{ $value }}</div>
                                            </td>
                                        </tr>
                                    @endif
                                @endforeach

                            </table>
                        </td>
                    </tr>

                    @if (filled($details['message']))
                        <tr>
                            <td style="padding-bottom: 20px;">
                                <div style="color: #9ca3af; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; padding-bottom: 6px;">Message</div>
                                <p style="margin: 0; color: #374151; font-size: 15px; line-height: 1.6;">{{ $details['message'] }}</p>
                            </td>
                        </tr>
                    @endif

                    <tr>
                        <td style="border-top: 1px solid #e5e7eb; padding-top: 16px;">
                            <p style="margin: 0; color: #9ca3af; font-size: 13px; line-height: 1.6;">
                                Reply to this email to reach the contact directly.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
