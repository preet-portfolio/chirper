<?php

use App\Models\User;
use Dom\HTMLDocument;
use Illuminate\Testing\TestResponse;

/**
 * The trimmed text of every link in the response whose href is exactly $href.
 *
 * @return list<string>
 */
function linkTexts(TestResponse $response, string $href): array
{
    $document = HTMLDocument::createFromString((string) $response->getContent(), LIBXML_NOERROR);

    $texts = [];
    foreach ($document->querySelectorAll('a[href]') as $link) {
        if ($link->getAttribute('href') === $href) {
            $texts[] = trim((string) $link->textContent);
        }
    }

    return $texts;
}

test('guest sees the timeline with log in and register links', function () {
    $response = $this->get(route('home'))->assertOk();

    expect(linkTexts($response, route('login')))->toBe(['Log in'])
        ->and(linkTexts($response, route('register')))->toBe(['Register']);
});

test('guest sees no log out control', function () {
    $this->get(route('home'))
        ->assertDontSee('Log out')
        ->assertDontSee('action="'.route('logout').'"', escape: false);
});

test('signed-in user sees their name linking to their profile and no guest links', function () {
    $response = $this->actingAs(User::factory()->create(['name' => 'Ada Lovelace']))
        ->get(route('home'))
        ->assertOk();

    expect(linkTexts($response, route('profile.edit')))->toBe(['Ada Lovelace'])
        ->and(linkTexts($response, route('login')))->toBe([])
        ->and(linkTexts($response, route('register')))->toBe([]);
});

test('signed-in user sees a log out form that logs them out', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('home'))->assertSee('Log out');

    $form = formSubmission($response, route('logout'));

    expect($form)->toBe(['method' => 'POST', 'csrf' => true, 'fields' => []]);

    $this->call($form['method'], route('logout'))->assertRedirect(route('home'));

    $this->assertGuest();
});

test('guest-only pages redirect a signed-in user to the timeline', function (string $page) {
    $this->actingAs(User::factory()->create())
        ->get(route($page))
        ->assertRedirect(route('home'));
})->with(['login', 'register']);
