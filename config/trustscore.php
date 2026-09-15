<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Network Graph Checks
    |--------------------------------------------------------------------------
    | Kill switch for the cross-carrier identifier lookups in DT Trust Score
    | v3 (shared phone / email / physical address / roadside VIN). Turning
    | this off makes the NET-* rules abstain instead of firing — the score
    | still returns, it just loses that signal.
    */

    'network_checks' => env('TRUSTSCORE_NETWORK_CHECKS', true),

];
