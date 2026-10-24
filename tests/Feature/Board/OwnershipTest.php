<?php

use App\Models\Chirp;
use App\Support\AuthorToken;

function postAs(?string $token, string $message = 'Mine'): string
{
    $request = $token ? test()->withCookie(AuthorToken::COOKIE, $token) : test();

    return $request->post(route('chirps.store'), ['message' => $message])
        ->getCookie(AuthorToken::COOKIE)->getValue();
}

test('the posting browser sees Edit and Delete on its own chirp only', function () {
    $mine = postAs(null, 'Mine');
    $theirs = postAs(null, 'Theirs');
    [$myChirp, $theirChirp] = [Chirp::firstWhere('message', 'Mine'), Chirp::firstWhere('message', 'Theirs')];

    $response = $this->withCookie(AuthorToken::COOKIE, $mine)->get(route('home'))
        ->assertSee(route('chirps.edit', $myChirp))
        ->assertDontSee(route('chirps.edit', $theirChirp))
        ->assertDontSee('action="'.route('chirps.destroy', $theirChirp).'"', escape: false)
        ->assertSee('Delete this chirp?');

    expect(formSubmission($response, route('chirps.destroy', $myChirp)))
        ->toBe(['method' => 'DELETE', 'csrf' => true, 'fields' => []]);
});

test('a browser without the cookie sees no Edit or Delete', function () {
    postAs(null);
    $chirp = Chirp::sole();

    $this->get(route('home'))
        ->assertDontSee(route('chirps.edit', $chirp))
        ->assertDontSee('action="'.route('chirps.destroy', $chirp).'"', escape: false);
});

test('the author can open the edit form and update their chirp', function () {
    $token = postAs(null, 'Before');
    $chirp = Chirp::sole();

    $response = $this->withCookie(AuthorToken::COOKIE, $token)->get(route('chirps.edit', $chirp))
        ->assertOk()->assertSee('Before');

    expect(formSubmission($response, route('chirps.update', $chirp)))
        ->toBe(['method' => 'PUT', 'csrf' => true, 'fields' => ['message']]);

    $this->withCookie(AuthorToken::COOKIE, $token)
        ->put(route('chirps.update', $chirp), ['message' => 'After'])
        ->assertRedirect(route('home'))
        ->assertSessionHas('success', 'Chirp updated!');

    expect($chirp->fresh()->message)->toBe('After');
});

test('the author can delete their chirp', function () {
    $token = postAs(null);
    $chirp = Chirp::sole();

    $this->withCookie(AuthorToken::COOKIE, $token)
        ->delete(route('chirps.destroy', $chirp))
        ->assertRedirect(route('home'))
        ->assertSessionHas('success', 'Chirp deleted!');

    $this->assertModelMissing($chirp);
});

test('other browsers cannot edit, update or delete', function (?string $cookie) {
    postAs(null, 'Untouched');
    $chirp = Chirp::sole();
    $request = $cookie === null ? $this : $this->withCookie(AuthorToken::COOKIE, $cookie);

    $request->get(route('chirps.edit', $chirp))->assertForbidden();
    $request->put(route('chirps.update', $chirp), ['message' => 'Hijacked'])->assertForbidden();
    $request->delete(route('chirps.destroy', $chirp))->assertForbidden();

    expect($chirp->fresh()->message)->toBe('Untouched');
})->with([
    'no cookie' => null,
    'someone else\'s token' => str_repeat('x', 40),
    'empty token' => '',
]);

test('a cookie that was not encrypted by the app is ignored', function () {
    $token = postAs(null);
    $chirp = Chirp::sole();

    $this->withUnencryptedCookie(AuthorToken::COOKIE, $token)
        ->delete(route('chirps.destroy', $chirp))
        ->assertForbidden();

    $this->assertModelExists($chirp);
});

test('the stored hash itself does not work as a token', function () {
    postAs(null);
    $chirp = Chirp::sole();

    $this->withCookie(AuthorToken::COOKIE, $chirp->author_token_hash)
        ->delete(route('chirps.destroy', $chirp))
        ->assertForbidden();
});

test('non-owners get 403 before validation runs', function () {
    postAs(null);

    $this->put(route('chirps.update', Chirp::sole()), ['message' => ''])
        ->assertForbidden();
});

test('update validates the message, including personal data', function (mixed $message, string $error) {
    $token = postAs(null, 'Untouched');
    $chirp = Chirp::sole();

    $this->withCookie(AuthorToken::COOKIE, $token)
        ->put(route('chirps.update', $chirp), ['message' => $message])
        ->assertSessionHasErrors(['message' => $error]);

    expect($chirp->fresh()->message)->toBe('Untouched');
})->with([
    'empty' => ['', 'Please write something to chirp!'],
    'too long' => [str_repeat('a', 256), 'Chirps must be 255 characters or less.'],
    'email' => ['reach me at a@b.io', "Please don't post personal data like emails or phone numbers. This board is public."],
]);

test('missing chirps are not found', function () {
    $this->get(route('chirps.edit', 999))->assertNotFound();
    $this->delete(route('chirps.destroy', 999))->assertNotFound();
});

test('expired chirps cannot be edited', function () {
    $token = postAs(null);
    $chirp = Chirp::sole();

    $this->travel(25)->hours();

    $this->withCookie(AuthorToken::COOKIE, $token)->get(route('chirps.edit', $chirp))->assertNotFound();
});

test('editing and deleting share the per-IP rate limit', function () {
    $token = postAs(null, 'Mine');
    $chirp = Chirp::sole();

    foreach (range(1, 4) as $i) {
        $this->withCookie(AuthorToken::COOKIE, $token)
            ->put(route('chirps.update', $chirp), ['message' => "Edit {$i}"])
            ->assertRedirect(route('home'));
    }

    $this->withCookie(AuthorToken::COOKIE, $token)
        ->put(route('chirps.update', $chirp), ['message' => 'Too many'])
        ->assertTooManyRequests();
    $this->withCookie(AuthorToken::COOKIE, $token)
        ->delete(route('chirps.destroy', $chirp))
        ->assertTooManyRequests();
});
