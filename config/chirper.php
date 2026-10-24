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
    | Public Board
    |--------------------------------------------------------------------------
    |
    | Chirps are anonymous and short-lived. The author cookie (which lets the
    | posting browser edit or delete its chirps) outlives them.
    |
    */

    'lifetime_hours' => (int) env('CHIRPER_LIFETIME_HOURS', 24),

    'author_cookie_days' => 7,

];
