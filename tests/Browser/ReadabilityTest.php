<?php

use App\Models\Chirp;
use App\Models\User;
use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\Webpage;

/*
 * Feature tests can't see colours: a stale Tailwind build once left chirp text
 * near-black on the dark background with every feature test green. These tests
 * read what Chromium actually paints and apply the WCAG 2.x contrast formula.
 */

/**
 * The colour Chromium paints the text of $selector in, and the colour behind it,
 * both as opaque sRGB [r, g, b] in 0-255. Every CSS colour (oklch, hex, alpha...)
 * is normalised by painting it on a canvas. Backgrounds are composited from the
 * root down, starting from the canvas's white, so transparent layers count.
 *
 * @param  Webpage|AwaitableWebpage  $page
 * @return array{fg: array{int, int, int}, bg: array{int, int, int}}
 */
function paintedColours(object $page, string $selector): array
{
    $script = <<<'JS'
        (selector) => {
            const element = document.querySelector(selector);
            if (!element) throw new Error(`No element matches ${selector}`);

            const ctx = document.createElement('canvas').getContext('2d', { willReadFrequently: true });
            const toRgba = (css) => {
                ctx.clearRect(0, 0, 1, 1);
                ctx.fillStyle = css;
                ctx.fillRect(0, 0, 1, 1);
                const [r, g, b, a] = ctx.getImageData(0, 0, 1, 1).data;
                return [r, g, b, a / 255];
            };
            const over = ([r, g, b, a], base) => [
                r * a + base[0] * (1 - a),
                g * a + base[1] * (1 - a),
                b * a + base[2] * (1 - a),
            ];

            const layers = [];
            for (let node = element; node; node = node.parentElement) {
                layers.unshift(getComputedStyle(node).backgroundColor);
            }

            const bg = layers.reduce((base, css) => over(toRgba(css), base), [255, 255, 255]);
            const fg = over(toRgba(getComputedStyle(element).color), bg);

            return { fg: fg.map(Math.round), bg: bg.map(Math.round) };
        }
        JS;

    return $page->script("() => ({$script})(".json_encode($selector).')');
}

/**
 * WCAG 2.x contrast ratio between two sRGB colours (1 to 21).
 *
 * @param  array{int, int, int}  $a
 * @param  array{int, int, int}  $b
 */
function contrastRatio(array $a, array $b): float
{
    $luminance = function (array $rgb): float {
        [$r, $g, $b] = array_map(function (int $channel): float {
            $c = $channel / 255;

            return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, $rgb);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    };

    [$lighter, $darker] = [max($luminance($a), $luminance($b)), min($luminance($a), $luminance($b))];

    return round(($lighter + 0.05) / ($darker + 0.05), 2);
}

test('contrast helper matches known WCAG values', function () {
    expect(contrastRatio([0, 0, 0], [255, 255, 255]))->toBe(21.0)
        ->and(contrastRatio([255, 255, 255], [255, 255, 255]))->toBe(1.0)
        // #767676 on white is the classic "just passes AA" grey.
        ->and(contrastRatio([118, 118, 118], [255, 255, 255]))->toBe(4.54);
});

dataset('colour schemes', [
    'dark mode' => ['dark', true],
    'light mode' => ['light', false],
]);

dataset('chirp text', [
    'message' => '[data-test="chirp"] > p',
    'author name' => '[data-test="chirp"] [data-flux-heading]',
]);

test('chirp text is readable', function (string $scheme, bool $expectDarkClass, string $selector) {
    Chirp::factory()->for(User::factory()->state(['name' => 'Ada Lovelace']))
        ->create(['message' => 'Can you read me?']);

    $visit = visit('/');
    $page = ($scheme === 'dark' ? $visit->inDarkMode() : $visit->inLightMode())
        ->assertSee('Can you read me?');

    // @fluxAppearance swaps the layout's class="dark" for the visitor's scheme;
    // make sure we are measuring the scheme we think we are.
    expect($page->script("() => document.documentElement.classList.contains('dark')"))->toBe($expectDarkClass);

    ['fg' => $fg, 'bg' => $bg] = paintedColours($page, $selector);
    $ratio = contrastRatio($fg, $bg);

    expect($ratio)->toBeGreaterThanOrEqual(
        4.5,
        sprintf('%s: rgb(%s) on rgb(%s) is %.2f:1, below WCAG AA 4.5:1', $selector, implode(',', $fg), implode(',', $bg), $ratio),
    );
})->with('colour schemes')->with('chirp text');
