<?php

use App\Models\Chirp;
use App\Models\User;

test('email verification is required to chirp by default', function () {
    expect(config('chirper.require_verified'))->toBeTrue();

    $this->actingAs(User::factory()->unverified()->create())
        ->post(route('chirps.store'), ['message' => 'Hello'])
        ->assertRedirect(route('verification.notice'));
});

describe('with verification not required', function () {
    beforeEach(fn () => config(['chirper.require_verified' => false]));

    test('an unverified user can post a chirp', function () {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->post(route('chirps.store'), ['message' => 'Hello'])
            ->assertRedirect(route('home'));

        expect($user->chirps()->sole()->message)->toBe('Hello');
    });

    test('an unverified author can edit and delete their chirp', function () {
        $chirp = Chirp::factory()->for(User::factory()->unverified())->create();

        $this->actingAs($chirp->user)->get(route('chirps.edit', $chirp))->assertOk();
        $this->actingAs($chirp->user)
            ->put(route('chirps.update', $chirp), ['message' => 'Edited'])
            ->assertRedirect(route('home'));
        $this->actingAs($chirp->user)
            ->delete(route('chirps.destroy', $chirp))
            ->assertRedirect(route('home'));

        $this->assertModelMissing($chirp);
    });

    test('guests still cannot chirp', function () {
        $this->post(route('chirps.store'), ['message' => 'Hello'])->assertRedirect(route('login'));
    });

    test('authorization still applies', function () {
        $chirp = Chirp::factory()->create();

        $this->actingAs(User::factory()->unverified()->create())
            ->put(route('chirps.update', $chirp), ['message' => 'Hijack'])
            ->assertForbidden();
    });
});
