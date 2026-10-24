<?php

use App\Models\Chirp;
use App\Models\User;

test('author can delete their chirp', function () {
    $chirp = Chirp::factory()->create();

    $this->actingAs($chirp->user)
        ->delete(route('chirps.destroy', $chirp))
        ->assertRedirect(route('home'))
        ->assertSessionHas('success', 'Chirp deleted!');

    $this->assertModelMissing($chirp);
});

test('deleting a chirp leaves other chirps alone', function () {
    $chirp = Chirp::factory()->create();
    $sibling = Chirp::factory()->for($chirp->user)->create();
    $other = Chirp::factory()->create();

    $this->actingAs($chirp->user)->delete(route('chirps.destroy', $chirp));

    $this->assertModelExists($sibling);
    $this->assertModelExists($other);
});

test('non-author cannot delete a chirp', function () {
    $chirp = Chirp::factory()->create();

    $this->actingAs(User::factory()->create())
        ->delete(route('chirps.destroy', $chirp))
        ->assertForbidden();

    $this->assertModelExists($chirp);
});

test('guest deleting a chirp is redirected to login', function () {
    $chirp = Chirp::factory()->create();

    $this->delete(route('chirps.destroy', $chirp))->assertRedirect(route('login'));
    $this->delete(route('chirps.destroy', 999))->assertRedirect(route('login'));

    $this->assertModelExists($chirp);
});

test('unverified author cannot delete their chirp', function () {
    $chirp = Chirp::factory()->for(User::factory()->unverified())->create();

    $this->actingAs($chirp->user)
        ->delete(route('chirps.destroy', $chirp))
        ->assertRedirect(route('verification.notice'));

    $this->assertModelExists($chirp);
});

test('deleting a missing chirp is not found', function () {
    $this->actingAs(User::factory()->create())
        ->delete(route('chirps.destroy', 999))
        ->assertNotFound();
});

test('author sees a delete button with a confirmation on their own chirps only', function () {
    $author = User::factory()->create();
    $mine = Chirp::factory()->for($author)->create();
    $theirs = Chirp::factory()->create();

    $response = $this->actingAs($author)
        ->get(route('home'))
        ->assertDontSee('action="'.route('chirps.destroy', $theirs).'"', escape: false)
        ->assertSee('Delete this chirp?');

    expect(formSubmission($response, route('chirps.destroy', $mine)))
        ->toBe(['method' => 'DELETE', 'csrf' => true, 'fields' => []]);
});

test('guest sees no delete buttons', function () {
    $chirp = Chirp::factory()->create();

    $this->get(route('home'))
        ->assertDontSee('action="'.route('chirps.destroy', $chirp).'"', escape: false);
});
