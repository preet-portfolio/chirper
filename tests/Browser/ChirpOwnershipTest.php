<?php

use App\Models\Chirp;

require_once __DIR__.'/Support/helpers.php';

/*
 * Ownership is "the browser holding the chirper_author cookie". Each visit()
 * opens its own Playwright browser context (PendingAwaitablePage calls
 * Browser::newContext), and contexts never share cookies, so two visit()s in
 * one test are two independent browsers. The server is in-process and reuses
 * one container, so interleaving requests from both also proves no author
 * state leaks from one request into the next.
 */

beforeEach(fn () => useBuiltAssets());

test('only the browser that posted a chirp sees Edit and Delete', function () {
    $author = visit('/');
    postChirp($author, 'Posted from the first browser');

    $chirp = Chirp::sole();
    $edit = sprintf('a[href$="/chirps/%d/edit"]', $chirp->id);
    $delete = sprintf('form[action$="/chirps/%d"]', $chirp->id);

    $author->assertCount($edit, 1)->assertCount($delete, 1);

    $stranger = visit('/')
        ->assertSeeIn(FIRST_CHIRP, 'Posted from the first browser')
        ->assertSeeIn(FIRST_CHIRP.' [data-flux-heading]', 'Anonymous')
        ->assertCount($edit, 0)
        ->assertCount($delete, 0)
        ->assertCount('[data-test="chirp"] a', 0)
        ->assertCount('[data-test="chirp"] form', 0);

    // Back and forth: each browser keeps its own view.
    $author->refresh()->assertCount($edit, 1)->assertCount($delete, 1);
    $stranger->refresh()->assertCount($edit, 0)->assertCount($delete, 0);
});

test('a second browser opening the edit URL directly gets 403', function () {
    $author = visit('/');
    postChirp($author, 'Hands off');

    $chirp = Chirp::sole();
    $editUrl = route('chirps.edit', $chirp, absolute: false);

    // Control: the author can open it.
    $author->navigate($editUrl)
        ->assertPathIs($editUrl)
        ->assertValue('message', 'Hands off');

    $stranger = visit($editUrl)
        ->assertPathIs($editUrl)
        ->assertSee('403')
        ->assertNotPresent('textarea[name="message"]');

    // The rendered page can't carry the status, so ask from the same context
    // (same cookie jar): fetch() sends exactly what the stranger's browser would.
    $status = fn ($page) => $page->script('() => fetch('.json_encode($editUrl).', { credentials: "same-origin" }).then(r => r.status)');

    expect($status($stranger))->toBe(403)
        ->and($status($author))->toBe(200)
        ->and($chirp->fresh()->message)->toBe('Hands off');
});
