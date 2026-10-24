<?php

use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Describe how a browser would submit the form whose action is $action:
 * the effective HTTP method (honouring _method spoofing), whether it carries
 * a CSRF token, and the names of its other fields.
 *
 * @return array{method: string, csrf: bool, fields: list<string>}
 */
function formSubmission(TestResponse $response, string $action): array
{
    $document = HTMLDocument::createFromString((string) $response->getContent(), LIBXML_NOERROR);

    $form = null;
    foreach ($document->querySelectorAll('form') as $candidate) {
        if ($candidate->getAttribute('action') === $action) {
            $form = $candidate;
        }
    }

    expect($form)->not->toBeNull("No form with action [{$action}] in the response.");

    $method = strtoupper($form->getAttribute('method') ?: 'GET');
    $fields = [];
    $csrf = false;

    foreach ($form->querySelectorAll('[name]') as $field) {
        $name = (string) $field->getAttribute('name');

        match ($name) {
            '_token' => $csrf = true,
            '_method' => $method === 'POST' ? $method = strtoupper((string) $field->getAttribute('value')) : null,
            default => $fields[] = $name,
        };
    }

    return ['method' => $method, 'csrf' => $csrf, 'fields' => $fields];
}
