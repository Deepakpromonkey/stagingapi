<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $request->subject }}</title>
</head>
<body style="margin:0; padding:24px; background:#f6f7f0; font-family:Arial, Helvetica, sans-serif; font-size:14px; line-height:1.6; color:#111827;">

    {{--
        Deliberately close to a plain mail. Agencies answer these by hand, and
        a heavily styled template reads as a mass mailing and gets ignored.
    --}}
    <div style="max-width:600px; margin:0 auto; background:#ffffff; border-radius:8px; padding:28px;">

        <p style="margin:0 0 16px;">Hi,</p>

        <p style="margin:0 0 16px;">
            We need the updated insurance details of the carrier, as our user is
            planning to use this carrier for our next transport.
        </p>

        <p style="margin:0 0 8px;">Carrier details are as below:</p>

        <table cellpadding="0" cellspacing="0" style="margin:0 0 16px; border-collapse:collapse;">
            <tr>
                <td style="padding:4px 12px 4px 0; color:#6b7280;">1. Carrier name</td>
                <td style="padding:4px 0; font-weight:bold;">{{ $request->carrier_name ?: 'NA' }}</td>
            </tr>
            <tr>
                <td style="padding:4px 12px 4px 0; color:#6b7280;">2. Carrier DOT</td>
                <td style="padding:4px 0; font-weight:bold;">{{ $request->dot_number }}</td>
            </tr>
            <tr>
                <td style="padding:4px 12px 4px 0; color:#6b7280;">3. Carrier MC</td>
                <td style="padding:4px 0; font-weight:bold;">{{ $request->carrier_mc ?: 'NA' }}</td>
            </tr>
        </table>

        {{--
            The certificate alone does not answer the questions a load is
            actually held on, so they are asked outright.

            The schedule matters most. On an Any Auto policy every unit the
            carrier runs is covered and there is nothing to list; on Scheduled
            Autos only the units named on the policy are insured, and a truck
            that is not on it is uninsured however good the certificate looks.
            A broker cannot tell which kind it is from a certificate, so the
            question has to be asked.
        --}}
        <p style="margin:0 0 8px;">So that we do not have to come back to you, please confirm:</p>

        <ol style="margin:0 0 16px; padding-left:20px;">
            <li style="margin-bottom:4px;">The policy expiry date.</li>
            <li style="margin-bottom:4px;">Limits for auto liability and cargo.</li>
            <li style="margin-bottom:4px;">
                Whether the auto liability is written <strong>Any Auto</strong> or
                <strong>Scheduled Autos</strong>. If scheduled, please list the VINs
                currently on the policy.
            </li>
            <li style="margin-bottom:4px;">
                Any cargo exclusions, deductibles, or commodity sub-limits that
                apply beneath the general cargo limit.
            </li>
            <li>The insurer behind the policy, and the policy number.</li>
        </ol>

        <p style="margin:0 0 16px;">Appreciate your quick response.</p>

        <p style="margin:0;">Thanks and Regards<br>DollarTraq Team</p>

    </div>

</body>
</html>
