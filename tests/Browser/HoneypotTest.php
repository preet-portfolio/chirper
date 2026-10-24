<?php

use App\Models\Chirp;

require_once __DIR__.'/Support/helpers.php';

/*
 * The `website` honeypot only works if people never fill it: it must not be
 * painted, and keyboard users must never land in it.
 */

beforeEach(fn () => useBuiltAssets());

test('the honeypot field is present but not visible', function () {
    $page = visit('/')
        ->assertPresent('input[name="website"]')
        ->assertMissing('input[name="website"]');

    $box = $page->script(<<<'JS'
        () => {
            const input = document.querySelector('input[name="website"]');
            const rect = input.getBoundingClientRect();
            return { width: rect.width, height: rect.height, display: getComputedStyle(input.closest('.hidden')).display };
        }
        JS);

    expect($box)->toBe(['width' => 0, 'height' => 0, 'display' => 'none']);
});

test('tabbing from the message field never reaches the honeypot', function () {
    $page = visit('/');

    // Press real Tab keys, each on whatever element currently has focus
    // (the plugin presses keys through a locator, so tag the focused element).
    $describeFocus = <<<'JS'
        () => {
            document.querySelector('[data-tab-probe]')?.removeAttribute('data-tab-probe');
            const el = document.activeElement;
            if (!el || el === document.body) return null;
            el.setAttribute('data-tab-probe', '');
            return el.getAttribute('name') || `${el.tagName.toLowerCase()}:${el.textContent.trim()}`;
        }
        JS;

    $page->keys('textarea[name="message"]', 'Tab');
    $stops = [];

    for ($i = 0; $i < 25; $i++) {
        $focused = $page->script($describeFocus);

        if ($focused === null || in_array($focused, $stops, true)) {
            break;
        }

        $stops[] = $focused;
        $page->keys('[data-tab-probe]', 'Tab');
    }

    // The very next stop after the message is the submit button, and the
    // honeypot never appears anywhere in the tab order.
    expect($stops)->not->toBeEmpty()
        ->and($stops[0])->toBe('button:Chirp')
        ->and($stops)->not->toContain('website');

    expect($page->script('() => document.activeElement?.name'))->not->toBe('website')
        ->and(Chirp::count())->toBe(0);
});
