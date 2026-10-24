<?php

use App\Models\Chirp;
use App\Models\User;
use Illuminate\Support\Facades\URL;

test('a new user registers, verifies, then posts, edits and deletes a chirp', function () {
    $older = Chirp::factory()->create(['message' => 'An older chirp from someone else']);

    // Register through the real form.
    $page = visit('/register')
        ->fill('name', 'Browser Bob')
        ->fill('email', 'bob@example.com')
        ->fill('password', 'correct-horse-battery-staple')
        ->fill('password_confirmation', 'correct-horse-battery-staple')
        ->click('Create account')
        ->assertPathIs('/')
        ->assertSee('Browser Bob');

    $bob = User::where('email', 'bob@example.com')->sole();
    expect($bob->hasVerifiedEmail())->toBeFalse();

    // Unverified: the form is shown, but posting lands on the verification notice.
    $page->fill('message', 'Hello before verifying')
        ->click('Chirp')
        ->assertRoute('verification.notice')
        ->assertSee('Please verify your email address');

    expect(Chirp::where('user_id', $bob->id)->count())->toBe(0);

    // Follow the same signed link the verification email would contain.
    $page->navigate(URL::temporarySignedRoute('verification.verify', now()->addHour(), [
        'id' => $bob->id,
        'hash' => sha1($bob->email),
    ]))->assertPathIs('/');

    expect($bob->fresh()->hasVerifiedEmail())->toBeTrue();

    // Post: newest first, with the flash.
    $page->fill('message', 'My first real chirp')
        ->click('Chirp')
        ->assertPathIs('/')
        ->assertSee('Your chirp has been posted!')
        ->assertSeeIn('[data-test="chirp"]:first-child', 'My first real chirp')
        ->assertSeeIn('[data-test="chirp"]:first-child', 'Browser Bob')
        ->assertSeeIn('[data-test="chirp"]:nth-child(2)', $older->message)
        ->assertDontSeeIn('[data-test="chirp"]:first-child', 'edited');

    // "edited" compares updated_at with created_at at one-second resolution,
    // so move the clock instead of hoping the edit lands in a later second.
    $this->travel(1)->minute();

    // Edit.
    $page->click('Edit')
        ->assertPathEndsWith('/edit')
        ->assertValue('message', 'My first real chirp')
        ->fill('message', 'My first real chirp, revised')
        ->click('Save')
        ->assertPathIs('/')
        ->assertSee('Chirp updated!')
        ->assertSeeIn('[data-test="chirp"]:first-child', 'My first real chirp, revised')
        ->assertSeeIn('[data-test="chirp"]:first-child', 'edited');

    // Delete, dismissing the confirm() first: Playwright dismisses dialogs by
    // default, so nothing is deleted. This proves the prompt really guards the form.
    $page->click('Delete')
        ->assertPathIs('/')
        ->assertSee('My first real chirp, revised')
        ->assertDontSee('Chirp deleted!');

    expect(Chirp::where('user_id', $bob->id)->count())->toBe(1);

    // The plugin has no dialog API, so accept the prompt by answering confirm()
    // with true, recording its text in sessionStorage to survive the navigation.
    $page->script(<<<'JS'
        () => {
            window.confirm = (message) => {
                sessionStorage.setItem('confirmed', message);
                return true;
            };
        }
        JS);

    $page->click('Delete')
        ->assertPathIs('/')
        ->assertSee('Chirp deleted!')
        ->assertDontSee('My first real chirp, revised')
        ->assertSee($older->message);

    expect($page->script("() => sessionStorage.getItem('confirmed')"))->toBe('Delete this chirp?')
        ->and(Chirp::where('user_id', $bob->id)->count())->toBe(0)
        ->and(Chirp::count())->toBe(1);
});
