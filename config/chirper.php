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

];
