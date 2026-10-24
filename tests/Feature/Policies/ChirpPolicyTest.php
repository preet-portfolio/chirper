<?php

use App\Models\Chirp;
use App\Models\User;

test('author can update their chirp', function () {
    $chirp = Chirp::factory()->create();

    expect($chirp->user->can('update', $chirp))->toBeTrue();
});

test('another user cannot update the chirp', function () {
    $chirp = Chirp::factory()->create();

    expect(User::factory()->create()->can('update', $chirp))->toBeFalse();
});
