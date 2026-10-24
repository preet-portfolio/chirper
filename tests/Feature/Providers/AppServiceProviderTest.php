<?php

use App\Models\Chirp;
use App\Providers\AppServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/*
| AppServiceProvider sets process-wide statics (Date, DB prohibition, strict
| mode, password defaults). Every test boots a fresh app, which re-runs boot()
| and resets them, but tests that boot "as production" also restore them
| explicitly so nothing leaks if a test fails half-way.
*/

test('dates are immutable', function () {
    $chirp = Chirp::factory()->create();

    expect(now())->toBeInstanceOf(CarbonImmutable::class)
        ->and($chirp->created_at)->toBeInstanceOf(CarbonImmutable::class);
});

test('chirps rate limiter keys per client IP, per minute and per day', function () {
    $limiter = RateLimiter::limiter('chirps');

    expect($limiter)->not->toBeNull();

    /** @var array<int, Limit> $limits */
    $limits = $limiter(Request::create('/chirps', 'POST', server: ['REMOTE_ADDR' => '198.51.100.7']));

    expect($limits)->toHaveCount(2)
        ->and($limits[0]->maxAttempts)->toBe(5)
        ->and($limits[0]->decaySeconds)->toBe(60)
        ->and($limits[0]->key)->toBe('minute:198.51.100.7')
        ->and($limits[1]->maxAttempts)->toBe(50)
        ->and($limits[1]->decaySeconds)->toBe(86400)
        ->and($limits[1]->key)->toBe('day:198.51.100.7');
});

test('in production destructive database commands are prohibited and strict mode is off', function () {
    $this->app['env'] = 'production';

    try {
        (new AppServiceProvider($this->app))->boot();

        expect(Model::preventsLazyLoading())->toBeFalse();

        $this->artisan('db:wipe', ['--force' => true])->assertFailed()->run();
    } finally {
        DB::prohibitDestructiveCommands(false);
        Model::shouldBeStrict();
    }
});

test('outside production strict mode is on', function () {
    // Start from off, so a flag left set by another test can't make this pass.
    Model::shouldBeStrict(false);

    (new AppServiceProvider($this->app))->boot();

    expect(Model::preventsLazyLoading())->toBeTrue()
        ->and(Model::preventsSilentlyDiscardingAttributes())->toBeTrue()
        ->and(Model::preventsAccessingMissingAttributes())->toBeTrue();
});
