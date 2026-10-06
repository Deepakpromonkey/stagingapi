<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],  

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    // Readable from the browser on a cross-origin response: the download's
    // filename (drayage export, CSV exports) and how long a 429 lasts.
    'exposed_headers' => ['Content-Disposition', 'Retry-After'],

    'max_age' => 0,

    'supports_credentials' => false,

];