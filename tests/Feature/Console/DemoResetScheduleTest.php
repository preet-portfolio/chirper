<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;

/**
 * Rebuild the schedule from routes/console.php with the given demo-reset
 * flag, and return the scheduled events that run chirps:delete-all.
 *
 * @return list<Event>
 */
function demoResetEvents(bool $demoReset): array
{
    config(['chirper.demo_reset' => $demoReset]);

    app()->forgetInstance(Schedule::class);
    ScheduleFacade::clearResolvedInstance(Schedule::class);

    require base_path('routes/console.php');

    return array_values(array_filter(
        app(Schedule::class)->events(),
        fn (Event $event) => str_contains((string) $event->command, 'chirps:delete-all'),
    ));
}

test('demo reset is off by default', function () {
    expect(config('chirper.demo_reset'))->toBeFalse();
});

test('chirps:delete-all is scheduled hourly with --force when demo reset is on', function () {
    $events = demoResetEvents(true);

    expect($events)->toHaveCount(1)
        ->and($events[0]->expression)->toBe('0 * * * *')
        ->and($events[0]->command)->toContain('chirps:delete-all --force');
});

test('chirps:delete-all is not scheduled when demo reset is off', function () {
    expect(demoResetEvents(false))->toBe([]);
});
