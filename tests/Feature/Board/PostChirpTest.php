<?php

use App\Models\Chirp;
use App\Support\AuthorToken;

test('anyone can post a chirp without an account', function () {
    $this->post(route('chirps.store'), ['message' => 'Hello Chirper'])
        ->assertRedirect(route('home'))
        ->assertSessionHas('success', 'Your chirp has been posted!');

    expect(Chirp::sole()->message)->toBe('Hello Chirper');
});

test('posting gives the browser an encrypted author cookie and stores only its hash', function () {
    $response = $this->post(route('chirps.store'), ['message' => 'Mine']);

    $cookie = $response->getCookie(AuthorToken::COOKIE);
    $token = $cookie->getValue();

    expect($token)->toHaveLength(40)
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and(Chirp::sole()->author_token_hash)->toBe(hash('sha256', $token))
        ->and(Chirp::sole()->author_token_hash)->not->toContain($token);
});

test('the same browser keeps its author token across posts', function () {
    $token = $this->post(route('chirps.store'), ['message' => 'One'])->getCookie(AuthorToken::COOKIE)->getValue();

    $this->withCookie(AuthorToken::COOKIE, $token)->post(route('chirps.store'), ['message' => 'Two']);

    expect(Chirp::pluck('author_token_hash')->unique()->all())->toBe([hash('sha256', $token)]);
});

test('message is required', function (?string $message) {
    $this->post(route('chirps.store'), ['message' => $message])
        ->assertSessionHasErrors(['message' => 'Please write something to chirp!']);

    expect(Chirp::count())->toBe(0);
})->with([
    'missing' => null,
    'empty' => '',
    'whitespace only' => "   \n\t ",
]);

test('message must be a string', function () {
    $this->post(route('chirps.store'), ['message' => ['not', 'a', 'string']])
        ->assertSessionHasErrors('message');

    expect(Chirp::count())->toBe(0);
});

test('message longer than 255 characters is rejected', function () {
    $this->post(route('chirps.store'), ['message' => str_repeat('a', 256)])
        ->assertSessionHasErrors(['message' => 'Chirps must be 255 characters or less.']);
});

test('message of exactly 255 characters is accepted', function () {
    $this->post(route('chirps.store'), ['message' => str_repeat('a', 255)])->assertSessionHasNoErrors();

    expect(Chirp::count())->toBe(1);
});

test('messages that look like personal data are rejected', function (string $message) {
    $this->post(route('chirps.store'), ['message' => $message])
        ->assertSessionHasErrors(['message' => "Please don't post personal data like emails or phone numbers. This board is public."]);

    expect(Chirp::count())->toBe(0);
})->with([
    'email' => 'mail me at jane.doe@example.com',
    'email uppercase' => 'JANE@EXAMPLE.CO.UK',
    'phone plain' => 'call 9876543210',
    'phone formatted' => 'call +1 (415) 555-0132',
    'phone dotted' => 'ring 020.7946.0958',
    'card-like digits' => 'card 4111 1111 1111 1111',
]);

test('ordinary numbers are not mistaken for personal data', function (string $message) {
    $this->post(route('chirps.store'), ['message' => $message])->assertSessionHasNoErrors();

    expect(Chirp::count())->toBe(1);
})->with([
    'year' => 'Laravel 13 shipped in 2026',
    'counts' => 'I have 3 cats and 12 plants',
    'version' => 'PHP 8.5.12 is out',
    'at sign' => 'meet @ the park',
]);

test('a filled honeypot looks successful to bots but saves nothing', function () {
    $this->post(route('chirps.store'), ['message' => 'Buy now', 'website' => 'http://spam.example'])
        ->assertRedirect(route('home'))
        ->assertSessionHas('success', 'Your chirp has been posted!');

    expect(Chirp::count())->toBe(0);
});

test('author hash cannot be supplied by the request', function () {
    $this->post(route('chirps.store'), ['message' => 'Mine', 'author_token_hash' => str_repeat('a', 64)]);

    expect(Chirp::sole()->author_token_hash)->not->toBe(str_repeat('a', 64));
});

test('posting is rate limited to 5 chirps per minute per IP', function () {
    foreach (range(1, 5) as $i) {
        $this->post(route('chirps.store'), ['message' => "Chirp {$i}"])->assertRedirect(route('home'));
    }

    $this->post(route('chirps.store'), ['message' => 'One too many'])->assertTooManyRequests();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->post(route('chirps.store'), ['message' => 'Other IP is unaffected'])
        ->assertRedirect(route('home'));

    expect(Chirp::count())->toBe(6);
});

test('posting is capped at 50 chirps per day per IP', function () {
    foreach (range(1, 50) as $i) {
        $this->travel(2)->minutes();
        $this->post(route('chirps.store'), ['message' => "Chirp {$i}"])->assertRedirect(route('home'));
    }

    $this->travel(2)->minutes();
    $this->post(route('chirps.store'), ['message' => 'Over the daily cap'])->assertTooManyRequests();
});

test('posting prunes chirps older than 24 hours', function () {
    $expired = Chirp::factory()->create(['created_at' => now()->subHours(25)]);
    $fresh = Chirp::factory()->create(['created_at' => now()->subHours(23)]);

    $this->post(route('chirps.store'), ['message' => 'Hello']);

    $this->assertModelMissing($expired);
    $this->assertModelExists($fresh);
});

test('the home page shows the chirp form with a personal-data disclaimer', function () {
    $response = $this->get(route('home'))->assertOk()
        ->assertSee("Don't include personal data")
        ->assertSee('deleted after 24 hours');

    expect(formSubmission($response, route('chirps.store')))
        ->toBe(['method' => 'POST', 'csrf' => true, 'fields' => ['message', 'website']]);
});
