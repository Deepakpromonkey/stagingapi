<!DOCTYPE html>
<html>
<body style="margin:0;padding:24px;background:#f4f5f7;font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;color:#111827;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;margin:0 auto;background:#ffffff;border-radius:10px;border-top:4px solid {{ $level === 'critical' ? '#dc2626' : ($level === 'warning' ? '#d97706' : '#16a34a') }};">
        <tr>
            <td style="padding:20px 24px 8px;">
                <h2 style="margin:0;font-size:18px;line-height:1.4;">{{ $headline }}</h2>
            </td>
        </tr>
        <tr>
            <td style="padding:8px 24px 24px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;border-collapse:collapse;">
                    @foreach ($facts as $name => $value)
                        <tr>
                            <td style="padding:6px 12px 6px 0;color:#6b7280;white-space:nowrap;vertical-align:top;">{{ $name }}</td>
                            <td style="padding:6px 0;word-break:break-word;font-family:{{ in_array($name, ['Message', 'Where', 'Request']) ? 'Menlo,Consolas,monospace' : 'inherit' }};">{{ $value }}</td>
                        </tr>
                    @endforeach
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
