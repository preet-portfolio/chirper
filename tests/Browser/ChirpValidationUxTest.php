<?php

use App\Models\Chirp;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('the browser truncates a 256 character chirp to 255 and it posts', function () {
    // maxlength="255" stops the 256th character from ever entering the
    // textarea, so the server's max:255 message is unreachable from the UI.
    $page = visit('/')->fill('message', str_repeat('a', 256));

    expect(mb_strlen($page->value('message')))->toBe(255);

    $page->click('Chirp')
        ->assertSee('Your chirp has been posted!')
        ->assertDontSee('Chirps must be 255 characters or less.');

    expect(Chirp::sole()->message)->toBe(str_repeat('a', 255));
});

test('a 256 character chirp that bypasses maxlength gets the server message', function () {
    // Defence in depth: someone who strips the attribute (devtools, curl)
    // still hits the FormRequest, and the error renders on the field.
    $page = visit('/');
    $page->script("() => document.querySelector('textarea[name=message]').removeAttribute('maxlength')");

    $page->fill('message', str_repeat('a', 256))
        ->click('Chirp')
        ->assertPathIs('/')
        ->assertSee('Chirps must be 255 characters or less.')
        ->assertValue('message', str_repeat('a', 256));

    expect(Chirp::count())->toBe(0);
});

test('an empty chirp is blocked by the required attribute before any request', function () {
    $page = visit('/')->click('Chirp');

    // Native constraint validation stops the submit: no navigation, no
    // server error, and the field reports valueMissing.
    expect($page->script("() => document.querySelector('textarea[name=message]').validity.valueMissing"))->toBeTrue()
        ->and($page->script("() => document.querySelector('textarea[name=message]').validationMessage"))->not->toBe('');

    $page->assertPathIs('/')
        ->assertDontSee('Please write something to chirp!');

    expect(Chirp::count())->toBe(0);
});

test('a whitespace-only chirp passes required in the browser but the server rejects it', function () {
    visit('/')
        ->fill('message', '   ')
        ->click('Chirp')
        ->assertPathIs('/')
        ->assertSee('Please write something to chirp!');

    expect(Chirp::count())->toBe(0);
});

test('a whitespace-only edit shows the error on the edit form and keeps the chirp', function () {
    $chirp = Chirp::factory()->for($this->user)->create(['message' => 'Keep me']);

    visit(route('chirps.edit', $chirp))
        ->fill('message', '   ')
        ->click('Save')
        ->assertPathEndsWith("/chirps/{$chirp->id}/edit")
        ->assertSee('Please write something to chirp!');

    expect($chirp->fresh()->message)->toBe('Keep me');
});
