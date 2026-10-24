<?php

use App\Models\Chirp;

require_once __DIR__.'/Support/helpers.php';

beforeEach(fn () => useBuiltAssets());

test('a visitor posts, edits and deletes an anonymous chirp in one browser', function () {
    $older = Chirp::factory()->create([
        'message' => 'An older chirp from another browser',
        'created_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
    ]);

    $page = visit('/')
        ->assertVisible(DISCLAIMER)
        ->assertSeeIn(DISCLAIMER, 'Public test board')
        ->assertSeeIn(DISCLAIMER, "Don't include personal data")
        ->assertSeeIn(DISCLAIMER, 'deleted after 24 hours')
        // Someone else's chirp: no controls before we have posted anything.
        ->assertCount('[data-test="chirp"] a[href$="/edit"]', 0);

    // Post: newest first, anonymous, with the flash.
    postChirp($page, 'My first anonymous chirp')
        ->assertSeeIn(FIRST_CHIRP, 'My first anonymous chirp')
        ->assertSeeIn(FIRST_CHIRP.' [data-flux-heading]', 'Anonymous')
        ->assertSeeIn('[data-test="chirp"]:nth-child(2)', $older->message)
        ->assertDontSeeIn(FIRST_CHIRP, 'edited')
        // The cookie makes this browser the author of the new chirp only.
        ->assertCount('[data-test="chirp"] a[href$="/edit"]', 1)
        ->assertCount('[data-test="chirp"] form', 1)
        ->assertCount(FIRST_CHIRP.' a[href$="/edit"]', 1);

    $chirp = Chirp::where('message', 'My first anonymous chirp')->sole();

    // Only the hash of the browser's token is stored; nothing identifies the poster.
    expect($chirp->author_token_hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($chirp->getAttributes())->not->toHaveKeys(['user_id', 'ip', 'name', 'email']);

    // "edited" compares updated_at with created_at at one-second resolution,
    // so move the clock instead of hoping the edit lands in a later second.
    $this->travel(1)->minute();

    $page->click('Edit')
        ->assertPathIs("/chirps/{$chirp->id}/edit")
        ->assertVisible(DISCLAIMER)
        ->assertValue('message', 'My first anonymous chirp')
        ->fill('message', 'My first anonymous chirp, revised')
        ->click('Save')
        ->assertPathIs('/')
        ->assertSee('Chirp updated!')
        ->assertSeeIn(FIRST_CHIRP, 'My first anonymous chirp, revised')
        ->assertSeeIn(FIRST_CHIRP.' [data-flux-heading]', 'Anonymous')
        ->assertSeeIn(FIRST_CHIRP, 'edited');

    // Dismissing confirm() (Playwright's default) must keep the chirp:
    // this proves the prompt really guards the form.
    $page->click('Delete')
        ->assertPathIs('/')
        ->assertSee('My first anonymous chirp, revised')
        ->assertDontSee('Chirp deleted!');

    expect(Chirp::whereKey($chirp->id)->exists())->toBeTrue();

    acceptNextConfirm($page);

    $page->click('Delete')
        ->assertPathIs('/')
        ->assertSee('Chirp deleted!')
        ->assertDontSee('My first anonymous chirp, revised')
        ->assertSee($older->message)
        ->assertCount('[data-test="chirp"]', 1);

    expect($page->script("() => sessionStorage.getItem('confirmed')"))->toBe('Delete this chirp?')
        ->and(Chirp::whereKey($chirp->id)->exists())->toBeFalse()
        ->and(Chirp::count())->toBe(1);
});

test('a chirp leaves the board once it is older than the configured lifetime', function () {
    $page = visit('/');
    postChirp($page, 'Here today');

    $this->travel(config('chirper.lifetime_hours'))->hours();
    $this->travel(1)->minute();

    $page->refresh()
        ->assertDontSee('Here today')
        ->assertSee('No chirps yet. Be the first to chirp!')
        ->assertVisible(DISCLAIMER);
});
