<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Demo Reset
    |--------------------------------------------------------------------------
    |
    | When true, routes/console.php schedules chirps:delete-all hourly, the
    | reference app's demo reset. Off by default so a real deployment never
    | wipes chirps unless it explicitly opts in.
    |
    */

    'demo_reset' => (bool) env('CHIRPER_DEMO_RESET', false),

    /*
    |--------------------------------------------------------------------------
    | Require Verified Email
    |--------------------------------------------------------------------------
    |
    | When true, users must verify their email before they can chirp. The
    | reference Bootcamp has no verification; deployments without an outbound
    | mail provider turn this off so new users aren't stuck at the notice.
    |
    */

    'require_verified' => (bool) env('CHIRPER_REQUIRE_VERIFIED', true),

];
