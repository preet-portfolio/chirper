<?php

use App\Models\Chirp;
use App\Models\User;

test('chirps:delete-all deletes every chirp and reports the count', function () {
    Chirp::factory()->count(3)->create();

    $this->artisan('chirps:delete-all')
        ->expectsOutputToContain('Successfully deleted 3 chirps.')
        ->assertSuccessful();

    expect(Chirp::count())->toBe(0);
});

test('chirps:delete-all succeeds when there are no chirps', function () {
    $this->artisan('chirps:delete-all')
        ->expectsOutputToContain('Successfully deleted 0 chirps.')
        ->assertSuccessful();
});

test('chirps:delete-all leaves users untouched', function () {
    $users = User::factory()->count(2)->create();
    Chirp::factory()->count(2)->create();

    $this->artisan('chirps:delete-all')->assertSuccessful();

    expect(User::count())->toBe(2)
        ->and(User::pluck('id')->all())->toEqualCanonicalizing($users->pluck('id')->all());
});

test('chirps:delete-all refuses to run in production without --force', function () {
    $this->app->detectEnvironment(fn () => 'production');
    Chirp::factory()->count(2)->create();

    // Leaving the 'testing' environment also switches Laravel Prompts off its
    // Symfony fallback, so expectsConfirmation() can't intercept the question.
    // Unanswered, confirm() takes its default (no), which is what cron gets.
    $this->artisan('chirps:delete-all')
        ->expectsOutputToContain('Command cancelled.')
        ->doesntExpectOutputToContain('Successfully deleted')
        ->assertFailed();

    expect(Chirp::count())->toBe(2);
});

test('chirps:delete-all runs in production with --force', function () {
    $this->app->detectEnvironment(fn () => 'production');
    Chirp::factory()->count(2)->create();

    $this->artisan('chirps:delete-all', ['--force' => true])
        ->expectsOutputToContain('Successfully deleted 2 chirps.')
        ->assertSuccessful();

    expect(Chirp::count())->toBe(0);
});
