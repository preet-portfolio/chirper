<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laravel's `verified` middleware, applied only while config('chirper.require_verified')
 * is on. It's checked per request (not at route registration) so it can be toggled per
 * environment and in tests.
 */
class EnsureEmailIsVerifiedWhenRequired
{
    public function __construct(private EnsureEmailIsVerified $verified) {}

    public function handle(Request $request, Closure $next): ?Response
    {
        if (! config('chirper.require_verified')) {
            return $next($request);
        }

        return $this->verified->handle($request, $next);
    }
}
