<?php

use App\Providers\TelescopeServiceProvider;
use Illuminate\Foundation\Providers\FoundationServiceProvider;
use Illuminate\Support\Facades\Process;

/**
 * Boot the app in a fresh process under the given environment and report what Telescope did.
 *
 * @param  array<string, string|false>  $env  false unsets a variable inherited from phpunit.xml
 * @return array{providers: list<class-string>, enabled: bool, routes: list<string>}
 */
function bootTelescopeProbe(string $environment, array $env = []): array
{
    $probe = <<<'PHP'
        echo json_encode([
            'providers' => array_keys(app()->getLoadedProviders()),
            'enabled' => (bool) config('telescope.enabled'),
            'routes' => collect(app('router')->getRoutes()->getRoutes())
                ->map->uri()->filter(fn ($uri) => str_starts_with($uri, 'telescope'))->values(),
        ]);
        PHP;

    $result = Process::path(base_path())
        ->env(['APP_ENV' => $environment, ...$env])
        ->run([PHP_BINARY, 'artisan', 'tinker', '--execute='.$probe])
        ->throw();

    return json_decode(trim($result->output()), true, flags: JSON_THROW_ON_ERROR);
}

test('telescope is not registered while testing', function () {
    expect(app()->getProviders(TelescopeServiceProvider::class))->toBe([]);
});

test('telescope is registered when the app boots locally', function () {
    expect(bootTelescopeProbe('local', ['TELESCOPE_ENABLED' => 'false'])['providers'])
        ->toContain(TelescopeServiceProvider::class);
});

test('telescope is not registered, enabled or routed when the app boots in production', function () {
    $production = bootTelescopeProbe('production', ['TELESCOPE_ENABLED' => false]);

    expect($production['providers'])
        ->toContain(FoundationServiceProvider::class)
        ->not->toContain(TelescopeServiceProvider::class)
        ->and($production['enabled'])->toBeFalse()
        ->and($production['routes'])->toBe([]);
});
