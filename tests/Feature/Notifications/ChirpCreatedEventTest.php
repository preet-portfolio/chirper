<?php

use App\Events\ChirpCreated;
use App\Listeners\SendNewChirpNotifications;
use App\Models\Chirp;
use App\Models\User;
use Illuminate\Support\Facades\Event;

test('posting a chirp dispatches ChirpCreated carrying that chirp', function () {
    Event::fake([ChirpCreated::class]);
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('chirps.store'), ['message' => 'Hello events']);

    $chirp = $user->chirps()->sole();
    Event::assertDispatchedTimes(ChirpCreated::class, 1);
    Event::assertDispatched(ChirpCreated::class, fn (ChirpCreated $event) => $event->chirp->is($chirp));
});

test('a rejected chirp dispatches no event', function () {
    Event::fake([ChirpCreated::class]);

    $this->actingAs(User::factory()->create())->post(route('chirps.store'), ['message' => '']);

    Event::assertNotDispatched(ChirpCreated::class);
});

test('factory-created chirps dispatch no event, so seeding never emails anyone', function () {
    Event::fake([ChirpCreated::class]);

    Chirp::factory()->count(3)->create();

    Event::assertNotDispatched(ChirpCreated::class);
});

test('ChirpCreated is handled by the new-chirp notification listener', function () {
    Event::fake();

    Event::assertListening(ChirpCreated::class, SendNewChirpNotifications::class);
});
