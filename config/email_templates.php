<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Template types
    |--------------------------------------------------------------------------
    |
    | The outgoing mails a company can customise, and the placeholders each one
    | can use. The placeholder list is served to the editor so the user knows
    | what is available rather than guessing.
    |
    | A type with a `default_subject` also has a stock design in
    | resources/views/emails/templates/{type}.html. It is what goes out when a
    | company has no active template of its own, and it is copied into every
    | company as an editable template (EmailTemplateService::installDefaults).
    |
    */

    /*
    | Where the images in the email designs (the logo) are served from. Its own
    | setting rather than APP_URL, which also signs the Fleetra token and so
    | cannot simply be repointed on a server that hosts a copy of the API.
    */
    'asset_url' => env('EMAIL_ASSET_URL', env('APP_URL')),

    'types' => [

        'invitation' => [
            'label' => 'Team invitation',
            'description' => 'Sent when a teammate is invited to join the company.',
            'default_subject' => '{{company_name}} added you to their team',
            'variables' => [
                'first_name' => 'Invited person\'s first name',
                'last_name' => 'Invited person\'s last name',
                'email' => 'Invited person\'s email address',
                'role_name' => 'Role they were given',
                'company_name' => 'Your company name',
                'invited_by' => 'Name of the person who sent the invitation',
                'accept_url' => 'Link where they set their password and join',
                'login_url' => 'Link to the sign-in screen',
                'sent_at' => 'Date the invitation was sent',
                'expires_at' => 'Invitation expiry date',
            ],
        ],

        'login_otp' => [
            'label' => 'Login OTP',
            'description' => 'Sent when a user signs in and two-factor is enabled.',
            'default_subject' => 'Your DollarTraq verification code',
            'variables' => [
                'first_name' => 'User\'s first name',
                'otp' => 'Six digit code',
                'minutes' => 'Minutes until the code expires',
                'company_name' => 'Your company name',
            ],
        ],

        'password_reset' => [
            'label' => 'Password reset',
            'description' => 'Sent when a user requests a password reset.',
            'variables' => [
                'first_name' => 'User\'s first name',
                'otp' => 'Six digit code',
                'minutes' => 'Minutes until the code expires',
                'company_name' => 'Your company name',
            ],
        ],

        'carrier_agreement' => [
            'label' => 'Carrier agreement',
            'description' => 'Sent to a carrier with the broker agreement to sign.',
            'variables' => [
                'carrier_name' => 'Carrier legal name',
                'dot_number' => 'Carrier DOT number',
                'mc_number' => 'Carrier MC number',
                'company_name' => 'Your company name',
                'agreement_url' => 'Link to the agreement document',
                'sender_name' => 'Name of the person sending it',
            ],
        ],

        'carrier_connect' => [
            'label' => 'Carrier connection request',
            'description' => 'Sent to a carrier when a broker clicks Connect on their profile.',
            // No default_subject: the carrier onboarding invitation stays on
            // its own mail (CarrierConnectInvitationMail) on production.
            'variables' => [
                'carrier_name' => 'Carrier legal name',
                'dot_number' => 'Carrier DOT number',
                'company_name' => 'Your company name',
                'sender_name' => 'Name of the person sending the request',
                'connect_url' => 'Link that starts carrier onboarding',
                'sent_at' => 'Date the request was sent',
                'expires_at' => 'Date and time the link stops working',
            ],
        ],

        'carrier_report' => [
            'label' => 'Carrier report received',
            'description' => 'Sent to the teammate who filed an incident report, confirming it was received.',
            'default_subject' => 'We received your report on {{carrier_name}}',
            'variables' => [
                'first_name' => 'First name of the person who filed the report',
                'carrier_name' => 'Carrier legal name',
                'dot_number' => 'Carrier DOT number',
                'report_id' => 'Report reference',
                'report_url' => 'Link to the carrier profile where the report is listed',
                'company_name' => 'Your company name',
            ],
        ],

        'carrier_account' => [
            'label' => 'Carrier portal account',
            'description' => 'Sent to a carrier with their portal login once onboarding is complete.',
            'variables' => [
                'carrier_name' => 'Carrier legal name',
                'dot_number' => 'Carrier DOT number',
                'company_name' => 'Your company name',
                'email' => 'The carrier\'s login email',
                'temporary_password' => 'System-generated first-login password',
                'portal_url' => 'Link to the carrier portal sign-in screen',
            ],
        ],

        'custom' => [
            'label' => 'Custom',
            'description' => 'Any other mail your team sends.',
            'variables' => [
                'company_name' => 'Your company name',
                'sender_name' => 'Name of the person sending it',
            ],
        ],

    ],

];
