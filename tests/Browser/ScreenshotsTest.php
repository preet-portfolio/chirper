<?php

use App\Models\Chirp;

require_once __DIR__.'/Support/helpers.php';

/*
 * Visual record for review, saved to tests/Browser/Screenshots/ (gitignored).
 * Dark mode, because that is the scheme the stale-CSS bug broke.
 */

beforeEach(fn () => useBuiltAssets());

function seedOtherChirps(): void
{
    Chirp::factory()->create([
        'message' => 'Difference Engine No. 2 is finally built.',
        'created_at' => now()->subHours(3),
        'updated_at' => now()->subHours(3),
    ]);
    Chirp::factory()->create([
        'message' => 'Anyone else testing the board today?',
        'created_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
    ]);
}

test('empty board', function () {
    visit('/')->inDarkMode()
        ->assertSee('No chirps yet. Be the first to chirp!')
        ->assertVisible(DISCLAIMER)
        ->screenshot(filename: 'board-empty');

    expect(__DIR__.'/Screenshots/board-empty.png')->toBeFile();
});

test('board with chirps, as the author of the newest', function () {
    seedOtherChirps();

    $page = visit('/')->inDarkMode();
    postChirp($page, "The Analytical Engine weaves algebraic patterns\njust as the Jacquard loom weaves flowers and leaves.")
        ->assertCount('[data-test="chirp"]', 3)
        ->assertCount('[data-test="chirp"] a[href$="/edit"]', 1)
        ->screenshot(filename: 'board-owner-view');

    expect(__DIR__.'/Screenshots/board-owner-view.png')->toBeFile();
});

test('edit page', function () {
    $page = visit('/')->inDarkMode();
    postChirp($page, 'A chirp about to be edited');

    $page->click('Edit')
        ->assertSee('Edit your chirp')
        ->assertValue('message', 'A chirp about to be edited')
        ->screenshot(filename: 'edit-chirp');

    expect(__DIR__.'/Screenshots/edit-chirp.png')->toBeFile();
});
