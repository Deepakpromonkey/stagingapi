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
    */

    'types' => [

        'invitation' => [
            'label' => 'Team invitation',
            'description' => 'Sent when a teammate is invited to join the company.',
            'variables' => [
                'first_name' => 'Invited person\'s first name',
                'last_name' => 'Invited person\'s last name',
                'email' => 'Invited person\'s email address',
                'temporary_password' => 'System-generated first-login password',
                'role_name' => 'Role they were given',
                'company_name' => 'Your company name',
                'invited_by' => 'Name of the person who sent the invitation',
                'login_url' => 'Link to the sign-in screen',
                'expires_at' => 'Invitation expiry date',
            ],
        ],

        'login_otp' => [
            'label' => 'Login OTP',
            'description' => 'Sent when a user signs in and two-factor is enabled.',
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
            'variables' => [
                'carrier_name' => 'Carrier legal name',
                'dot_number' => 'Carrier DOT number',
                'company_name' => 'Your company name',
                'sender_name' => 'Name of the person sending the request',
                'connect_url' => 'Link that starts carrier onboarding',
                'expires_at' => 'Date and time the link stops working',
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
