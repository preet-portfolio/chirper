<?php

use App\Events\ChirpCreated;
use App\Listeners\SendNewChirpNotifications;
use App\Models\User;
use App\Notifications\NewChirp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

test('posting a chirp notifies other verified users by mail', function () {
    Notification::fake();
    $author = User::factory()->create();
    $others = User::factory()->count(2)->create();

    $this->actingAs($author)->post(route('chirps.store'), ['message' => 'Hello everyone']);

    $chirp = $author->chirps()->sole();
    Notification::assertSentTo(
        $others,
        NewChirp::class,
        fn (NewChirp $notification, array $channels) => $notification->chirp->is($chirp) && $channels === ['mail'],
    );
    Notification::assertSentTimes(NewChirp::class, 2);
});

test('the author is never notified about their own chirp', function () {
    Notification::fake();
    $author = User::factory()->create();
    User::factory()->create();

    $this->actingAs($author)->post(route('chirps.store'), ['message' => 'Hello everyone']);

    Notification::assertNotSentTo($author, NewChirp::class);
    Notification::assertSentTimes(NewChirp::class, 1);
});

test('unverified users are never notified', function () {
    Notification::fake();
    $author = User::factory()->create();
    $unverified = User::factory()->unverified()->create();
    User::factory()->create();

    $this->actingAs($author)->post(route('chirps.store'), ['message' => 'Hello everyone']);

    Notification::assertNotSentTo($unverified, NewChirp::class);
    Notification::assertSentTimes(NewChirp::class, 1);
});

test('recipients are loaded in chunks, and nobody at a chunk boundary is skipped', function () {
    Notification::fake();
    $author = User::factory()->create();
    $recipients = User::factory()->count(SendNewChirpNotifications::CHUNK_SIZE + 1)->create();
    $chirp = $author->chirps()->create(['message' => 'Hello everyone']);

    DB::enableQueryLog();
    app(SendNewChirpNotifications::class)->handle(new ChirpCreated($chirp));
    $recipientQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query) => str_contains($query['query'], '"email_verified_at" is not null'));

    expect($recipientQueries)->toHaveCount(2)
        ->and($recipientQueries->every(fn (array $query) => str_contains($query['query'], 'limit '.SendNewChirpNotifications::CHUNK_SIZE)))->toBeTrue();
    Notification::assertSentTo($recipients, NewChirp::class);
    Notification::assertSentTimes(NewChirp::class, $recipients->count());
});
