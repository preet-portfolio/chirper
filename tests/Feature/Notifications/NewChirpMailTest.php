<?php

use App\Models\User;
use App\Notifications\NewChirp;
use Dom\HTMLDocument;

test('the email subject names the author', function () {
    $author = User::factory()->create(['name' => 'Ada Lovelace']);
    $chirp = $author->chirps()->create(['message' => 'Hello']);

    $mail = (new NewChirp($chirp))->toMail(User::factory()->create());

    expect($mail->subject)->toBe('New Chirp from Ada Lovelace');
});

test('the email body shows the message and links to the timeline', function () {
    $chirp = User::factory()->create()->chirps()->create(['message' => 'Hello from the mail body']);

    $html = (string) (new NewChirp($chirp))->toMail(User::factory()->create())->render();

    $button = HTMLDocument::createFromString($html, LIBXML_NOERROR)->querySelector('a.button');

    expect($html)->toContain('Hello from the mail body')
        ->and($button?->getAttribute('href'))->toBe(route('home'))
        ->and(trim((string) $button?->textContent))->toBe('Read it on Chirper');
});

test('html in the message and the author name is escaped in the email', function () {
    $author = User::factory()->create(['name' => '<b>Mallory</b>']);
    $chirp = $author->chirps()->create(['message' => '<script>alert("x")</script>']);

    $html = (string) (new NewChirp($chirp))->toMail(User::factory()->create())->render();

    expect($html)->toContain('&lt;script&gt;')
        ->not->toContain('<script>')
        ->toContain('&lt;b&gt;Mallory&lt;/b&gt;')
        ->not->toContain('<b>Mallory');
});

test('markdown links in the message are not turned into links in the email', function () {
    $chirp = User::factory()->create()->chirps()->create(['message' => '[Claim your prize](https://evil.test/phish)']);

    $html = (string) (new NewChirp($chirp))->toMail(User::factory()->create())->render();

    expect($html)->not->toContain('href="https://evil.test/phish"')
        ->toContain('[Claim your prize](https://evil.test/phish)');
});
