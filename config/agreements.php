<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Storage disk
    |--------------------------------------------------------------------------
    |
    | Where broker agreement documents are stored. S3 in every environment
    | except tests, which swap in a fake disk.
    |
    */

    'disk' => env('AGREEMENT_DISK', 's3'),

];
