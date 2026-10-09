<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Telling drivers about a new load
    |--------------------------------------------------------------------------
    |
    | When a broker books a load, every driver phone on it hears about it: a
    | push if that driver already has the DollarTraq app, an SMS with the
    | store links below if they don't. See LoadAssignmentNotifier.
    |
    | The switch is for an environment that must never message a real driver.
    | SMS_OVERRIDE_TO (config/services.php) is the other option: everything
    | still sends, but to one tester's phone.
    |
    */

    'notify_on_new_load' => env('DRIVER_LOAD_NOTIFICATIONS', true),

    'play_store_url' => env(
        'DRIVER_APP_PLAY_STORE_URL',
        'https://play.google.com/store/apps/details?id=com.DollarTraq'
    ),

    // No country in the path on purpose: /in/app/... opens the India store
    // page, while this opens the store of whatever country the phone is set
    // to - most drivers are in the US. Same app (id6759337431), and shorter,
    // which matters in a text that is charged per segment.
    'app_store_url' => env(
        'DRIVER_APP_APP_STORE_URL',
        'https://apps.apple.com/app/id6759337431'
    ),

];
