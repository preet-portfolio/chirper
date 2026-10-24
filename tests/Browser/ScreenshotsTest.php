<?php

use App\Models\Chirp;
use App\Models\User;

/*
 * Visual record for review, saved to tests/Browser/Screenshots/ (gitignored).
 * Dark mode, because that is the scheme the stale-CSS bug broke.
 */

beforeEach(function () {
    $this->author = User::factory()->create(['name' => 'Ada Lovelace']);
    $this->chirp = Chirp::factory()->for($this->author)->create([
        'message' => "The Analytical Engine weaves algebraic patterns\njust as the Jacquard loom weaves flowers and leaves.",
    ]);
    Chirp::factory()->for(User::factory()->state(['name' => 'Charles Babbage']))
        ->create(['message' => 'Difference Engine No. 2 is finally built.', 'created_at' => now()->subHour(), 'updated_at' => now()->subHour()]);
});

test('timeline as a guest', function () {
    visit('/')->inDarkMode()
        ->assertSee('Ada Lovelace')
        ->screenshot(filename: 'timeline-guest');

    expect(__DIR__.'/Screenshots/timeline-guest.png')->toBeFile();
});

test('timeline signed in', function () {
    $this->actingAs($this->author);

    visit('/')->inDarkMode()
        ->assertSee('Edit')
        ->screenshot(filename: 'timeline-signed-in');

    expect(__DIR__.'/Screenshots/timeline-signed-in.png')->toBeFile();
});

test('edit page', function () {
    $this->actingAs($this->author);

    visit(route('chirps.edit', $this->chirp))->inDarkMode()
        ->assertSee('Edit your chirp')
        ->screenshot(filename: 'edit-chirp');

    expect(__DIR__.'/Screenshots/edit-chirp.png')->toBeFile();
});
