<?php

use App\Models\Chirp;
use App\Models\User;

test('another user sees no edit or delete controls on a chirp they did not write', function () {
    $theirs = Chirp::factory()->create(['message' => 'Written by the author']);
    $viewer = User::factory()->create();
    $mine = Chirp::factory()->for($viewer)->create(['message' => 'Written by the viewer']);

    $this->actingAs($viewer);

    $page = visit('/')
        ->assertSee($theirs->message)
        ->assertSee($mine->message);

    // Control: the viewer's own chirp does get both controls, so the
    // selectors below aren't vacuously empty.
    $page->assertCount(sprintf('a[href$="/chirps/%d/edit"]', $mine->id), 1)
        ->assertCount(sprintf('form[action$="/chirps/%d"]', $mine->id), 1)
        ->assertCount('[data-test="chirp"] a[href$="/edit"]', 1)
        ->assertCount('[data-test="chirp"] form', 1)
        ->assertCount(sprintf('a[href$="/chirps/%d/edit"]', $theirs->id), 0)
        ->assertCount(sprintf('form[action$="/chirps/%d"]', $theirs->id), 0);
});

test('a guest sees the timeline but no chirp form and no edit or delete controls', function () {
    Chirp::factory()->count(2)->create(['message' => 'A public chirp']);

    visit('/')
        ->assertCount('[data-test="chirp"]', 2)
        ->assertNotPresent('textarea[name="message"]')
        ->assertNotPresent('form[action$="/chirps"]')
        ->assertCount('[data-test="chirp"] a[href$="/edit"]', 0)
        ->assertCount('[data-test="chirp"] form', 0)
        ->assertDontSee('Edit')
        ->assertDontSee('Delete')
        ->assertSee('Log in')
        ->assertSee('Register');
});
