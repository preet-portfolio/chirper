<?php

use App\Models\Chirp;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;

test('deleting a user deletes their chirps', function () {
    $user = User::factory()->has(Chirp::factory()->count(3))->create();
    $other = Chirp::factory()->create();

    $user->delete();

    expect(Chirp::pluck('id')->all())->toBe([$other->id]);
});

test('chirp belongs to its author', function () {
    $chirp = Chirp::factory()->create();

    expect($chirp->user)->toBeInstanceOf(User::class)
        ->and($chirp->user->chirps->pluck('id')->all())->toBe([$chirp->id]);
});

test('user_id is not mass assignable', function () {
    expect(fn () => new Chirp(['message' => 'x', 'user_id' => 1]))
        ->toThrow(MassAssignmentException::class);
});
