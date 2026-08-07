<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Incident report</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f6f8; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f4f6f8; padding: 40px 10px;">
        <tr>
            <td align="center">
                <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 620px; background-color: #ffffff; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); padding: 40px 30px;">

                    <tr>
                        <td style="padding-bottom: 8px;">
                            <h2 style="margin: 0; color: #111827; font-size: 22px; font-weight: 700; letter-spacing: -0.5px;">
                                Incident report
                            </h2>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding-bottom: 24px;">
                            <p style="margin: 0; color: #6b7280; font-size: 15px; line-height: 1.6;">
                                <strong style="color: #111827;">{{ $companyName }}</strong>
                                has filed an incident report against
                                <strong style="color: #111827;">{{ $report->carrier_legal_name ?? 'this carrier' }}</strong>@if ($report->carrier_dot_number) (DOT {{ $report->carrier_dot_number }})@endif.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding-bottom: 24px;">
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px;">
                                <tr>
                                    <td style="padding-bottom: 16px;">
                                        <div style="color: #9ca3af; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; padding-bottom: 4px;">Incident date</div>
                                        <div style="color: #111827; font-size: 15px; font-weight: 600;">{{ $report->incident_date?->format('d M Y') ?? '—' }}</div>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding-bottom: 16px;">
                                        <div style="color: #9ca3af; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; padding-bottom: 4px;">Origin</div>
                                        <div style="color: #111827; font-size: 15px; font-weight: 600;">{{ $report->origin_city }}, {{ $report->origin_state }}, {{ $report->origin_country }}</div>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <div style="color: #9ca3af; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; padding-bottom: 4px;">Destination</div>
                                        <div style="color: #111827; font-size: 15px; font-weight: 600;">{{ $report->destination_city }}, {{ $report->destination_state }}, {{ $report->destination_country }}</div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding-bottom: 8px;">
                            <div style="color: #9ca3af; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Reported incidents</div>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding-bottom: 24px;">
                            <ul style="margin: 0; padding-left: 20px; color: #111827; font-size: 14px; line-height: 1.8;">
                                @foreach ($incidentLabels as $label)
                                    <li>{{ $label }}</li>
                                @endforeach
                            </ul>
                        </td>
                    </tr>

                    @if ($report->comments)
                        <tr>
                            <td style="padding-bottom: 8px;">
                                <div style="color: #9ca3af; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Comments</div>
                            </td>
                        </tr>

                        <tr>
                            <td style="padding-bottom: 24px;">
                                <p style="margin: 0; color: #374151; font-size: 14px; line-height: 1.7; white-space: pre-line;">{{ $report->comments }}</p>
                            </td>
                        </tr>
                    @endif

                    @if ($report->is_private)
                        <tr>
                            <td style="padding-bottom: 24px;">
                                <p style="margin: 0; color: #b45309; background-color: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 14px; font-size: 13px; line-height: 1.6;">
                                    This report has been marked <strong>private</strong>. It is kept
                                    inside {{ $companyName }} and is not shared with other brokers on
                                    DollarTraq.
                                </p>
                            </td>
                        </tr>
                    @endif

                    <tr>
                        <td style="border-top: 1px solid #f1f5f9; padding-top: 20px;">
                            <p style="margin: 0; color: #9ca3af; font-size: 13px; line-height: 1.5;">
                                Filed by {{ $brokerName }} at {{ $companyName }} on
                                {{ $report->created_at?->format('d M Y, h:i A') }}.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
