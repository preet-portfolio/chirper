<?php

use App\Providers\AppServiceProvider;
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

// In-process twins of the subprocess tests above: coverage (and so mutation
// testing) can't see code that only runs in a child process.
test('AppServiceProvider registers Telescope in the local environment', function () {
    $this->app['env'] = 'local';

    (new AppServiceProvider($this->app))->register();

    expect($this->app->getProviders(Laravel\Telescope\TelescopeServiceProvider::class))->not->toBeEmpty()
        ->and($this->app->getProviders(TelescopeServiceProvider::class))->not->toBeEmpty();
});

test('AppServiceProvider does not register Telescope outside local', function (string $environment) {
    $this->app['env'] = $environment;

    (new AppServiceProvider($this->app))->register();

    expect($this->app->getProviders(Laravel\Telescope\TelescopeServiceProvider::class))->toBeEmpty()
        ->and($this->app->getProviders(TelescopeServiceProvider::class))->toBeEmpty();
})->with(['testing', 'production']);
