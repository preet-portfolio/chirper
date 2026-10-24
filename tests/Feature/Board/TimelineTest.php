<?php

use App\Models\Chirp;
use App\Support\AuthorToken;
use Illuminate\Support\Facades\DB;

test('chirps are shown as Anonymous', function () {
    Chirp::factory()->create(['message' => 'First chirp ever']);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Anonymous')
        ->assertSee('First chirp ever');
});

test('chirps are listed newest first', function () {
    Chirp::factory()->create(['message' => 'Oldest', 'created_at' => now()->subHours(2)]);
    Chirp::factory()->create(['message' => 'Newest', 'created_at' => now()]);
    Chirp::factory()->create(['message' => 'Middle', 'created_at' => now()->subHour()]);

    $this->get(route('home'))->assertSeeInOrder(['Newest', 'Middle', 'Oldest']);
});

test('chirps posted in the same second are ordered by most recent id', function () {
    $this->freezeTime();
    Chirp::factory()->create(['message' => 'Posted first']);
    Chirp::factory()->create(['message' => 'Posted second']);

    $this->get(route('home'))->assertSeeInOrder(['Posted second', 'Posted first']);
});

test('only the 50 newest chirps are shown', function () {
    Chirp::factory()->create(['message' => 'Too old to show', 'created_at' => now()->subHours(12)]);
    Chirp::factory()->count(50)->create();

    $response = $this->get(route('home'))->assertDontSee('Too old to show');

    expect(substr_count($response->getContent(), 'data-test="chirp"'))->toBe(50);
});

test('empty state is shown when there are no chirps', function () {
    $this->get(route('home'))->assertSee('No chirps yet');
});

test('timeline uses a constant number of queries', function () {
    $queriesFor = function (int $chirps) {
        Chirp::query()->delete();
        Chirp::factory()->count($chirps)->create();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('home'))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    expect($queriesFor(20))->toBe($queriesFor(2));
});

test('timeline uses a constant number of queries for a browser that owns chirps', function () {
    $token = str_repeat('t', 40);

    $queriesFor = function (int $chirps) use ($token) {
        Chirp::query()->delete();
        Chirp::factory()->count($chirps)->create();
        Chirp::factory()->ownedBy($token)->count(2)->create();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->withCookie(AuthorToken::COOKIE, $token)->get(route('home'))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    expect($queriesFor(20))->toBe($queriesFor(2));
});

test('chirps older than 24 hours are not shown even before they are pruned', function () {
    Chirp::factory()->create(['message' => 'Expired', 'created_at' => now()->subHours(25)]);
    Chirp::factory()->create(['message' => 'Still fresh', 'created_at' => now()->subHours(23)]);

    $this->get(route('home'))->assertSee('Still fresh')->assertDontSee('Expired');
});

test('chirp messages are escaped', function () {
    Chirp::factory()->create(['message' => '<script>alert("xss")</script>']);

    $this->get(route('home'))
        ->assertSee('<script>alert("xss")</script>')
        ->assertDontSee('<script>alert("xss")</script>', escape: false);
});

test('chirps show when they were posted relative to now', function () {
    Chirp::factory()->create(['created_at' => now()->subMinutes(5)]);

    $this->get(route('home'))->assertSee('5 minutes ago');
});

test('edited chirps are marked as edited', function () {
    Chirp::factory()->create([
        'created_at' => now()->subHour(),
        'updated_at' => now(),
    ]);

    $this->get(route('home'))->assertSee('edited');
});

test('unedited chirps are not marked as edited', function () {
    $this->freezeTime();
    Chirp::factory()->create();

    $this->get(route('home'))->assertDontSee('edited');
});
