Hello {{ $invitation->first_name }},

{{ $invitation->creator->first_name }} {{ $invitation->creator->last_name }} has added you to {{ $invitation->company->company_name }} on dollarTraq as {{ $invitation->role->name }}.

Choose a password to finish setting up your account:

{{ $acceptUrl }}

This link works once and expires on {{ $invitation->expires_at->format('j F Y') }}. If you weren't expecting it, you can ignore this email — no account can be opened without it.
