<?php

use App\Models\Chirp;

require_once __DIR__.'/Support/helpers.php';

beforeEach(fn () => useBuiltAssets());

dataset('personal data', [
    'an email address' => 'Write to me at ada.lovelace@example.com',
    'a phone number' => 'Call me on +44 20 7946 0958 after six',
    'a dotted phone number' => 'Text 555.123.4567 for details',
]);

test('personal data is refused with the message on the field and the text kept', function (string $message) {
    $page = visit('/')
        ->fill('message', $message)
        ->click('Chirp')
        ->assertPathIs('/')
        ->assertVisible('[data-flux-error]')
        ->assertSeeIn('[data-flux-error]', PERSONAL_DATA_ERROR)
        ->assertValue('message', $message)
        ->assertDontSee('Your chirp has been posted!')
        ->assertCount('[data-test="chirp"]', 0)
        ->assertSee('No chirps yet. Be the first to chirp!');

    // Announced to screen readers, not just painted red.
    $page->assertAttribute('[data-flux-error]', 'role', 'alert');

    expect(Chirp::count())->toBe(0);
})->with('personal data');

test('ordinary numbers are not mistaken for personal data', function (string $message) {
    postChirp(visit('/'), $message)
        ->assertSeeIn(FIRST_CHIRP, $message)
        ->assertDontSee(PERSONAL_DATA_ERROR);

    expect(Chirp::sole()->message)->toBe($message);
})->with([
    'a version number' => 'Laravel 13 is out',
    'a room, a time and a date' => 'Room 101 at 9:30 on 25 October 2026',
]);

test('editing a chirp to add personal data shows the error on the edit form', function () {
    $page = visit('/');
    postChirp($page, 'Clean so far');
    $chirp = Chirp::sole();

    $page->click('Edit')
        ->fill('message', 'Actually mail me: me@example.org')
        ->click('Save')
        ->assertPathIs("/chirps/{$chirp->id}/edit")
        ->assertSeeIn('[data-flux-error]', PERSONAL_DATA_ERROR)
        ->assertValue('message', 'Actually mail me: me@example.org');

    expect($chirp->fresh()->message)->toBe('Clean so far');
});

test('the browser truncates a 256 character chirp to 255 and it posts', function () {
    // maxlength="255" stops the 256th character from ever entering the
    // textarea, so the server's max:255 message is unreachable from the UI.
    $page = visit('/')->fill('message', str_repeat('a', 256));

    expect(mb_strlen($page->value('message')))->toBe(255);

    postChirp($page, $page->value('message'))
        ->assertDontSee('Chirps must be 255 characters or less.');

    expect(Chirp::sole()->message)->toBe(str_repeat('a', 255));
});

test('a whitespace-only chirp passes required in the browser but the server rejects it', function () {
    visit('/')
        ->fill('message', '   ')
        ->click('Chirp')
        ->assertPathIs('/')
        ->assertSeeIn('[data-flux-error]', 'Please write something to chirp!');

    expect(Chirp::count())->toBe(0);
});
