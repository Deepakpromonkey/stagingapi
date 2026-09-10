<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approve a different email address</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f6f8; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f4f6f8; padding: 40px 10px;">
        <tr>
            <td align="center">
                <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 520px; background-color: #ffffff; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); padding: 40px 30px;">

                    <tr>
                        <td style="padding-bottom: 8px;">
                            <h2 style="margin: 0; color: #111827; font-size: 22px; font-weight: 700; letter-spacing: -0.5px;">
                                Hello {{ $connectRequest->carrier_legal_name }},
                            </h2>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding-bottom: 20px;">
                            <p style="margin: 0; color: #6b7280; font-size: 15px; line-height: 1.6;">
                                <strong style="color: #111827;">{{ $connectRequest->company->company_name }}</strong>
                                wants to start onboarding you on DollarTraq, but has asked us to
                                send the onboarding link to an address that is not the one on your
                                FMCSA record.
                            </p>
                        </td>
                    </tr>

                    <!-- The address in question, stated plainly. This is the whole decision. -->
                    <tr>
                        <td style="padding-bottom: 20px;">
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px;">
                                <tr>
                                    <td style="padding-bottom: 14px;">
                                        <div style="color: #9ca3af; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; padding-bottom: 4px;">Requested address</div>
                                        <div style="color: #111827; font-size: 15px; font-weight: 600; word-break: break-all;">{{ $requestedEmail }}</div>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding-bottom: 14px;">
                                        <div style="color: #9ca3af; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; padding-bottom: 4px;">Requested by</div>
                                        <div style="color: #111827; font-size: 15px; font-weight: 600;">{{ $brokerName }}</div>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <div style="color: #9ca3af; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; padding-bottom: 4px;">DOT number</div>
                                        <div style="color: #111827; font-size: 15px; font-weight: 600;">{{ $connectRequest->carrier_dot_number ?? '—' }}</div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding-bottom: 20px;">
                            <p style="margin: 0; color: #6b7280; font-size: 15px; line-height: 1.6;">
                                Nothing has been sent to that address. If you recognise it — your
                                dispatch or compliance inbox, for example — approve below and we
                                will send the onboarding link there.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td align="center" style="padding-bottom: 24px;">
                            <a href="{{ $approvalUrl }}"
                               style="background:#2563eb; color:#ffffff; padding:13px 26px; text-decoration:none; border-radius:6px; font-size:15px; font-weight:600; display:inline-block;">
                                Approve this address
                            </a>
                        </td>
                    </tr>

                    <!-- The safe default is stated explicitly: doing nothing is a refusal. -->
                    <tr>
                        <td style="padding-bottom: 20px;">
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 16px;">
                                <tr>
                                    <td>
                                        <p style="margin: 0; color: #92400e; font-size: 13px; line-height: 1.6;">
                                            <strong>Do not recognise this?</strong> Ignore this email. Without your
                                            approval no onboarding link is sent to that address, and nobody can
                                            complete onboarding on your behalf.
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td>
                            <p style="margin: 0; color: #9ca3af; font-size: 12px; line-height: 1.6;">
                                This approval link can only be used once and expires in
                                {{ (int) config('carrier_connect.request_lifetime_hours', 72) }} hours.
                                It was sent to the email address on your FMCSA record.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
