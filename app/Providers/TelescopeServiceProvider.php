<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;
use ReflectionClass;

/**
 * Registered by AppServiceProvider in the local environment only (ADR 0007).
 */
class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        // Telescope's tables exist only where Telescope runs: migrations load from the package,
        // so tests, CI and production never create them.
        $this->loadMigrationsFrom(
            dirname((string) (new ReflectionClass(Telescope::class))->getFileName(), 2).'/database/migrations',
        );
    }

    /**
     * Telescope already lets everyone in locally, and this provider never loads anywhere else.
     * The gate denies by default so that stays true if that ever changes.
     */
    protected function gate(): void
    {
        Gate::define('viewTelescope', fn (User $user): bool => false);
    }
}
