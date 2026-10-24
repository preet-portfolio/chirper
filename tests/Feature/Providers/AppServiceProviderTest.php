<?php

use App\Models\Chirp;
use App\Providers\AppServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/*
| AppServiceProvider sets process-wide statics (Date, DB prohibition, strict
| mode, password defaults). Every test boots a fresh app, which re-runs boot()
| and resets them, but tests that boot "as production" also restore them
| explicitly so nothing leaks if a test fails half-way.
*/

function passesDefaultPasswordRule(string $password): bool
{
    return Validator::make(['password' => $password], ['password' => Password::default()])->passes();
}

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

test('outside production the password default is the framework minimum of 8', function () {
    expect(passesDefaultPasswordRule('abcdefgh'))->toBeTrue()
        ->and(passesDefaultPasswordRule('abcdefg'))->toBeFalse();
});

test('in production the password default is strict', function (string $password, bool $passes) {
    Http::preventStrayRequests();
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('')]);
    $this->app['env'] = 'production';

    expect(passesDefaultPasswordRule($password))->toBe($passes);
})->with([
    '12 characters, all classes' => ['Chirper#2026', true],
    '11 characters' => ['Chirper#202', false],
    'no uppercase' => ['chirper#2026', false],
    'no lowercase' => ['CHIRPER#2026', false],
    'no number' => ['Chirper#abcd', false],
    'no symbol' => ['Chirper12026', false],
]);

test('in production the password default rejects breached passwords', function () {
    $password = 'Chirper#2026';
    $hash = strtoupper(sha1($password));

    Http::preventStrayRequests();
    Http::fake([
        'api.pwnedpasswords.com/range/'.substr($hash, 0, 5) => Http::response(substr($hash, 5).':42'),
    ]);
    $this->app['env'] = 'production';

    expect(passesDefaultPasswordRule($password))->toBeFalse();
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
    expect(Model::preventsLazyLoading())->toBeTrue()
        ->and(Model::preventsSilentlyDiscardingAttributes())->toBeTrue()
        ->and(Model::preventsAccessingMissingAttributes())->toBeTrue();
});
