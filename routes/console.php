<?php

use App\Console\Commands\DeleteAllChirps;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The reference app's hourly demo reset. Opt-in only: turning the flag on is
// the explicit consent that --force stands in for under the scheduler.
if (config('chirper.demo_reset')) {
    Schedule::command(DeleteAllChirps::class, ['--force'])->hourly();
}
