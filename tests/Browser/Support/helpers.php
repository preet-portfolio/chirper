<?php

/*
 * Shared helpers for the browser suite. Pest only auto-loads tests/Pest.php
 * and tests/Helpers*, so each browser test file require_once's this file.
 */

use Illuminate\Support\Facades\Vite;
use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\Webpage;

const DISCLAIMER = '[data-test="disclaimer"]';
const FIRST_CHIRP = '[data-test="chirp"]:first-child';
const PERSONAL_DATA_ERROR = "Please don't post personal data like emails or phone numbers. This board is public.";

/**
 * Serve the built assets from public/build even while `npm run dev` is running.
 *
 * A running Vite dev server leaves public/hot behind, and @vite would then point
 * Chromium at http://[::1]:5173 instead of the manifest. The browser server runs
 * in this process and reuses the test's container, so swapping the hot file on
 * the Vite singleton applies to every request the browser makes. That keeps the
 * contrast checks measuring the CSS that ships, not whatever the dev server compiles.
 */
function useBuiltAssets(): void
{
    Vite::useHotFile(storage_path('framework/testing/no-vite-dev-server'));
}

/**
 * Post a chirp through the real form in this browser context.
 *
 * @param  Webpage|AwaitableWebpage  $page
 * @return Webpage|AwaitableWebpage
 */
function postChirp(object $page, string $message): object
{
    return $page->fill('message', $message)
        ->click('Chirp')
        ->assertPathIs('/')
        ->assertSee('Your chirp has been posted!');
}

/**
 * The plugin has no dialog API (and Playwright dismisses dialogs by default),
 * so answer confirm() with true, recording its text in sessionStorage so the
 * test can read it back after the form submission navigates away.
 *
 * @param  Webpage|AwaitableWebpage  $page
 */
function acceptNextConfirm(object $page): void
{
    $page->script(<<<'JS'
        () => {
            window.confirm = (message) => {
                sessionStorage.setItem('confirmed', message);
                return true;
            };
        }
        JS);
}

/**
 * The colour Chromium paints the text of $selector in, and the colour behind it,
 * both as opaque sRGB [r, g, b] in 0-255. Every CSS colour (oklch, color-mix,
 * alpha...) is normalised by painting it on a canvas. Backgrounds are composited
 * from the root down, starting from the canvas's white, so transparent layers
 * (like the callout's translucent dark-mode tint) count.
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
