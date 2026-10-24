<?php

use App\Models\Chirp;
use App\Support\AuthorToken;

test('there are no account links or pages', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertDontSee('Log in')
        ->assertDontSee('Register')
        ->assertDontSee('Log out');

    foreach (['/login', '/register', '/logout', '/dashboard', '/settings/profile', '/forgot-password'] as $path) {
        $this->get($path)->assertNotFound();
    }
});

test('every page carries the public-board disclaimer', function () {
    $token = $this->post(route('chirps.store'), ['message' => 'Mine'])->getCookie(AuthorToken::COOKIE)->getValue();

    $pages = [
        $this->get(route('home')),
        $this->withCookie(AuthorToken::COOKIE, $token)->get(route('chirps.edit', Chirp::sole())),
    ];

    foreach ($pages as $page) {
        $page->assertOk()
            ->assertSee('Public test board')
            ->assertSee("Don't include personal data")
            ->assertSee('deleted after 24 hours');
    }
});
