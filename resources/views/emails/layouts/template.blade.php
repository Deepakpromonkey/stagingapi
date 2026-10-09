<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
</head>
<body style="margin: 0; padding: 0; background-color: #eef0f3;">
    {{-- The body is an email template, either the company's own or the stock design. --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #eef0f3;">
        <tr>
            <td align="center" style="padding: 32px 12px;">
                {!! $body !!}
            </td>
        </tr>
    </table>
</body>
</html>
