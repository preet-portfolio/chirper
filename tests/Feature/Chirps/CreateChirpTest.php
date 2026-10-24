<?php

use App\Models\Chirp;
use App\Models\User;

test('verified user can post a chirp', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('chirps.store'), ['message' => 'Hello Chirper'])
        ->assertRedirect(route('home'))
        ->assertSessionHas('success', 'Your chirp has been posted!');

    expect($user->chirps()->sole()->message)->toBe('Hello Chirper');
});

test('guest posting a chirp is redirected to login', function () {
    $this->post(route('chirps.store'), ['message' => 'Hello'])
        ->assertRedirect(route('login'));

    expect(Chirp::count())->toBe(0);
});

test('unverified user cannot post a chirp', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->post(route('chirps.store'), ['message' => 'Hello'])
        ->assertRedirect(route('verification.notice'));

    expect(Chirp::count())->toBe(0);
});

test('message is required', function (?string $message) {
    $this->actingAs(User::factory()->create())
        ->post(route('chirps.store'), ['message' => $message])
        ->assertSessionHasErrors(['message' => 'Please write something to chirp!']);

    expect(Chirp::count())->toBe(0);
})->with([
    'missing' => null,
    'empty' => '',
    'whitespace only' => "   \n\t ",
]);

test('message must be a string', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('chirps.store'), ['message' => ['not', 'a', 'string']])
        ->assertSessionHasErrors('message');

    expect(Chirp::count())->toBe(0);
});

test('message longer than 255 characters is rejected', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('chirps.store'), ['message' => str_repeat('a', 256)])
        ->assertSessionHasErrors(['message' => 'Chirps must be 255 characters or less.']);

    expect(Chirp::count())->toBe(0);
});

test('message of exactly 255 characters is accepted', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('chirps.store'), ['message' => str_repeat('a', 255)])
        ->assertSessionHasNoErrors();

    expect(Chirp::count())->toBe(1);
});

test('message is stored trimmed', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('chirps.store'), ['message' => '  padded  ']);

    expect(Chirp::sole()->message)->toBe('padded');
});

test('chirp author comes from the session, not the request', function () {
    $author = User::factory()->create();
    $victim = User::factory()->create();

    $this->actingAs($author)
        ->post(route('chirps.store'), ['message' => 'Mine', 'user_id' => $victim->id]);

    expect(Chirp::sole()->user_id)->toBe($author->id);
});

test('posting is rate limited to 10 chirps per minute per user', function () {
    $user = User::factory()->create();

    foreach (range(1, 10) as $i) {
        $this->actingAs($user)
            ->post(route('chirps.store'), ['message' => "Chirp {$i}"])
            ->assertRedirect(route('home'));
    }

    $this->actingAs($user)
        ->post(route('chirps.store'), ['message' => 'One too many'])
        ->assertTooManyRequests();

    $this->actingAs(User::factory()->create())
        ->post(route('chirps.store'), ['message' => 'Other user is unaffected'])
        ->assertRedirect(route('home'));

    expect(Chirp::count())->toBe(11);
});

test('verified user sees the chirp form on the home page', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertOk()
        ->assertSee('name="message"', escape: false);
});

test('guest does not see the chirp form', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertDontSee('name="message"', escape: false);
});
