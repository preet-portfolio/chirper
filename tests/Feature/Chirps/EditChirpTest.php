<?php

use App\Models\Chirp;
use App\Models\User;

test('author can view the edit form for their chirp', function () {
    $chirp = Chirp::factory()->create(['message' => 'Original words']);

    $response = $this->actingAs($chirp->user)
        ->get(route('chirps.edit', $chirp))
        ->assertOk()
        ->assertSee('Original words');

    expect(formSubmission($response, route('chirps.update', $chirp)))
        ->toBe(['method' => 'PUT', 'csrf' => true, 'fields' => ['message']]);
});

test('non-author cannot view the edit form', function () {
    $chirp = Chirp::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('chirps.edit', $chirp))
        ->assertForbidden();
});

test('guest viewing the edit form is redirected to login', function () {
    $this->get(route('chirps.edit', Chirp::factory()->create()))
        ->assertRedirect(route('login'));
});

test('guest cannot probe which chirp ids exist', function () {
    $existing = Chirp::factory()->create();

    $this->get(route('chirps.edit', 999))->assertRedirect(route('login'));
    $this->get(route('chirps.edit', $existing))->assertRedirect(route('login'));
    $this->put(route('chirps.update', 999))->assertRedirect(route('login'));
});

test('editing a missing chirp is not found', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('chirps.edit', 999))
        ->assertNotFound();
});

test('author can update their chirp', function () {
    $chirp = Chirp::factory()->create(['message' => 'Before']);

    $this->actingAs($chirp->user)
        ->put(route('chirps.update', $chirp), ['message' => 'After'])
        ->assertRedirect(route('home'))
        ->assertSessionHas('success', 'Chirp updated!');

    expect($chirp->fresh()->message)->toBe('After');
});

test('non-author cannot update a chirp', function () {
    $chirp = Chirp::factory()->create(['message' => 'Untouched']);

    $this->actingAs(User::factory()->create())
        ->put(route('chirps.update', $chirp), ['message' => 'Hijacked'])
        ->assertForbidden();

    expect($chirp->fresh()->message)->toBe('Untouched');
});

test('non-author gets 403 before validation runs', function () {
    $chirp = Chirp::factory()->create();

    $this->actingAs(User::factory()->create())
        ->put(route('chirps.update', $chirp), ['message' => ''])
        ->assertForbidden();
});

test('guest updating a chirp is redirected to login', function () {
    $chirp = Chirp::factory()->create(['message' => 'Untouched']);

    $this->put(route('chirps.update', $chirp), ['message' => 'Hijacked'])
        ->assertRedirect(route('login'));

    expect($chirp->fresh()->message)->toBe('Untouched');
});

test('unverified author cannot update their chirp', function () {
    $chirp = Chirp::factory()
        ->for(User::factory()->unverified())
        ->create(['message' => 'Untouched']);

    $this->actingAs($chirp->user)
        ->put(route('chirps.update', $chirp), ['message' => 'Changed'])
        ->assertRedirect(route('verification.notice'));

    expect($chirp->fresh()->message)->toBe('Untouched');
});

test('unverified author cannot view the edit form', function () {
    $chirp = Chirp::factory()->for(User::factory()->unverified())->create();

    $this->actingAs($chirp->user)
        ->get(route('chirps.edit', $chirp))
        ->assertRedirect(route('verification.notice'));
});

test('update validates the message', function (mixed $message, string $error) {
    $chirp = Chirp::factory()->create(['message' => 'Untouched']);

    $this->actingAs($chirp->user)
        ->put(route('chirps.update', $chirp), ['message' => $message])
        ->assertSessionHasErrors(['message' => $error]);

    expect($chirp->fresh()->message)->toBe('Untouched');
})->with([
    'empty' => ['', 'Please write something to chirp!'],
    'whitespace only' => ['   ', 'Please write something to chirp!'],
    'too long' => [str_repeat('a', 256), 'Chirps must be 255 characters or less.'],
    'not a string' => [['a'], 'The message field must be a string.'],
]);

test('update cannot reassign the chirp to another user', function () {
    $chirp = Chirp::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($chirp->user)
        ->put(route('chirps.update', $chirp), ['message' => 'Mine', 'user_id' => $other->id])
        ->assertRedirect(route('home'))
        ->assertSessionHasNoErrors();

    expect($chirp->fresh())
        ->message->toBe('Mine')
        ->user_id->toBe($chirp->user_id);
});

test('author sees an edit link on their own chirps only', function () {
    $author = User::factory()->create();
    $mine = Chirp::factory()->for($author)->create();
    $theirs = Chirp::factory()->create();

    $this->actingAs($author)
        ->get(route('home'))
        ->assertSee(route('chirps.edit', $mine))
        ->assertDontSee(route('chirps.edit', $theirs));
});

test('guest sees no edit links', function () {
    $chirp = Chirp::factory()->create();

    $this->get(route('home'))->assertDontSee(route('chirps.edit', $chirp));
});
