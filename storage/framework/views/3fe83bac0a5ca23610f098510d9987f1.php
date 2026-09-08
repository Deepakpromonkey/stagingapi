<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connection request</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f6f8; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f4f6f8; padding: 40px 10px;">
        <tr>
            <td align="center">
                <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 520px; background-color: #ffffff; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); padding: 40px 30px;">

                    <tr>
                        <td style="padding-bottom: 8px;">
                            <h2 style="margin: 0; color: #111827; font-size: 22px; font-weight: 700; letter-spacing: -0.5px;">
                                Hello <?php echo e($connectRequest->carrier_legal_name); ?>,
                            </h2>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding-bottom: 20px;">
                            <p style="margin: 0; color: #6b7280; font-size: 15px; line-height: 1.6;">
                                <strong style="color: #111827;"><?php echo e($connectRequest->company->company_name); ?></strong>
                                would like to connect with you on DollarTraq so they can start
                                tendering loads to your fleet.
                            </p>
                        </td>
                    </tr>

                    <!-- Carrier identifiers, so it is obvious this is meant for them -->
                    <tr>
                        <td style="padding-bottom: 20px;">
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px;">
                                <tr>
                                    <td>
                                        <div style="color: #9ca3af; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; padding-bottom: 4px;">DOT number</div>
                                        <div style="color: #111827; font-size: 15px; font-weight: 600;"><?php echo e($connectRequest->carrier_dot_number ?? '—'); ?></div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding-bottom: 20px;">
                            <p style="margin: 0; color: #6b7280; font-size: 15px; line-height: 1.6;">
                                Onboarding takes about five minutes. You'll confirm your phone
                                number, verify a government ID, connect a bank account for
                                payouts, and sign the broker–carrier agreement.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td align="center" style="padding-bottom: 24px;">
                            <a href="<?php echo e($connectUrl); ?>"
                               style="background:#2563eb; color:#ffffff; padding:13px 26px; text-decoration:none; border-radius:6px; font-size:15px; font-weight:600; display:inline-block;">
                                Start onboarding
                            </a>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding-bottom: 24px;">
                            <p style="margin: 0; color: #b45309; background-color: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 14px; font-size: 13px; line-height: 1.6;">
                                This link is unique to your company and expires on
                                <strong><?php echo e($connectRequest->sent_on->copy()->addHours((int) config('carrier_connect.request_lifetime_hours', 72))->format('d M Y, h:i A')); ?></strong>.
                                Please don't forward it.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="border-top: 1px solid #f1f5f9; padding-top: 20px;">
                            <p style="margin: 0; color: #9ca3af; font-size: 13px; line-height: 1.5;">
                                Sent by <?php echo e($brokerName); ?> at <?php echo e($connectRequest->company->company_name); ?>.
                                If you weren't expecting this, you can ignore this email.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
<?php /**PATH /var/www/dollarTraq-main/resources/views/emails/carrier-connect-invitation.blade.php ENDPATH**/ ?>