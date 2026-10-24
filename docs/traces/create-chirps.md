# Trace: `POST /chirps` (`chirps.store`)

Laravel 13.38.0, Livewire v4.4.8, Fortify v1.41.0 (from `composer.lock`). Paths are relative to
`vendor/laravel/framework/src/Illuminate/` unless they start with `vendor/` or `app/`.

```
Browser   index.php   App/Kernel   Global MW      web group       auth/verified/throttle   FormRequest     Controller    Eloquent/PDO
  | POST /chirps |          |            |               |                  |                    |               |             |
  |------------->| handleRequest         |               |                  |                    |               |             |
  |              |--------->| Pipeline -> Trim, EmptyToNull               |                    |               |             |
  |              |          |----------->| Router::dispatch -> match route  |                    |               |             |
  |              |          |            |-------------->| Cookies, StartSession, Errors, CSRF, Bindings      |               |             |
  |              |          |            |               |----------------->| check() / verified / limiter hit    |               |             |
  |              |          |            |               |                  |--- container->make(StoreChirpRequest)            |             |
  |              |          |            |               |                  |                    | afterResolving: validateResolved   |
  |              |          |            |               |                  |                    |-------------->| chirps()->create()
  |              |          |            |               |                  |                    |               |------------>| INSERT chirps
  |              |          |            |               |                  |                    |               |<------------| lastInsertId
  |              |          |            |               |                  |   + X-RateLimit-*  | 302 + flash   |             |
  |              |          |            |               |<-- saveSession() + session cookie     |               |             |
  |<-------------|  send(); kernel->terminate()          |               |                  |                    |               |             |
```

## 1. Entry point and global middleware

`public/index.php` calls `$app->handleRequest(Request::capture());`. `Foundation/Application.php:1224-1230`:

```php
$kernel = $this->make(HttpKernelContract::class);
$response = $kernel->handle($request)->send();
$kernel->terminate($request, $response);
```

`Foundation/Http/Kernel.php:137-144`. `handle()` calls `sendRequestThroughRouter()`, and any `Throwable` goes
to `renderException`. `Kernel.php:172-175` sends the request through the global stack:

```php
return (new Pipeline($this->app))->send($request)
    ->through($this->app->shouldSkipMiddleware() ? [] : $this->middleware)
    ->then($this->dispatchToRouter());
```

`bootstrap/app.php` uses an empty `withMiddleware`, so the defaults apply. They are listed at
`Foundation/Configuration/Middleware.php:453-463`: ValidatePathEncoding, InvokeDeferredCallbacks, TrustProxies,
HandleCors, PreventRequestsDuringMaintenance, ValidatePostSize, **TrimStrings**, **ConvertEmptyStringsToNull**.
Even with no custom middleware, `withMiddleware` registers `redirectGuestsTo(fn () => route('login'))`
(`Foundation/Configuration/ApplicationBuilder.php:261-262`).

- `Foundation/Http/Middleware/TrimStrings.php:65-69`: `if ($this->shouldSkip($key, $except) || ! is_string($value)) {...} return Str::trim($value);`
- `Foundation/Http/Middleware/ConvertEmptyStringsToNull.php:43`: `return $value === '' ? null : $value;`

**Effect on messages.** `"   Hello SQL   "` is stored as `"Hello SQL"` (see the SQL bindings in section 5). A
whitespace-only message becomes `""` and then `null`, so `required` fails. It would also fail without these two
middleware, because `validateRequired` trims the value itself (`Validation/Concerns/ValidatesAttributes.php:2174-2177`):

```php
if (is_null($value)) { return false; }
elseif (is_string($value) && trim($value) === '') { return false; }
```

So TrimStrings is the reason messages are stored trimmed. It is not the only reason whitespace-only input fails.

## 2. Routing, the web group, and route middleware

The router matches the route in `Routing/Router.php:785-796` (`findRoute` → `$this->routes->match($request)`),
then runs it in `runRouteWithinStack` (`Router.php:823-840`). That method builds a second `Pipeline` from
`gatherRouteMiddleware($route)` and ends with `$route->run()`.

The real middleware list, from `php artisan route:list --path=chirps -v`:

```
 POST chirps .. chirps.store › ChirpController@store
 ⇂ web
 ⇂ Illuminate\Auth\Middleware\Authenticate
 ⇂ Illuminate\Auth\Middleware\EnsureEmailIsVerified
 ⇂ Illuminate\Routing\Middleware\ThrottleRequests:chirps
```

The aliases are defined at `Foundation/Configuration/Middleware.php:806` (`'auth' => Authenticate::class`), `:815`
(`throttle`) and `:818` (`verified`). The middleware is sorted by `$middlewarePriority` (`Foundation/Http/Kernel.php:103-114`):
StartSession, then AuthenticatesRequests, then ThrottleRequests, then SubstituteBindings.

**web group** (`Foundation/Configuration/Middleware.php:485-493`): EncryptCookies, AddQueuedCookiesToResponse,
StartSession, ShareErrorsFromSession, **PreventRequestForgery**, SubstituteBindings.
In 13.x the CSRF class is `PreventRequestForgery`. `ValidateCsrfToken` still exists only as a deprecated subclass:
`Foundation/Http/Middleware/ValidateCsrfToken.php:6-8` (`@deprecated Use PreventRequestForgery instead.` /
`class ValidateCsrfToken extends PreventRequestForgery`).

CSRF check, `Foundation/Http/Middleware/PreventRequestForgery.php:97-111`:

```php
if ($this->isReading($request) || $this->runningUnitTests() || $this->inExceptArray($request) ||
    $this->hasValidOrigin($request) || $this->tokensMatch($request)) { ... }
throw new TokenMismatchException('CSRF token mismatch.');
```

- **Why tests skip CSRF:** `runningUnitTests()` (`:130-132`) returns `runningInConsole() && runningUnitTests()`,
  which is true under Pest because phpunit.xml sets `APP_ENV=testing`.
- `hasValidOrigin` (`:143-158`) also accepts a request with `Sec-Fetch-Site: same-origin` before it compares tokens.

**auth.** `Auth/Middleware/Authenticate.php:75-87` loops over the guards with `if ($this->auth->guard($guard)->check())`.
If none pass, it calls `unauthenticated()` (`:99-104`), which throws `AuthenticationException` carrying `redirectTo()`
(the `route('login')` callback above). `Foundation/Exceptions/Handler.php:860-866` turns that into
`redirect()->guest($redirectTo)`, which stores `url.intended` and returns a 302 to `/login`.
In a real request, `check()` → `SessionGuard::user()` (`Auth/SessionGuard.php:171`) loads the user with
`$this->provider->retrieveById($id)` (`:189`), which runs `where(id)->first()` (`Auth/EloquentUserProvider.php:52-58`).
In tests, `actingAs` calls `setUser` (`Foundation/Testing/Concerns/InteractsWithAuthentication.php:49`), so that
query never runs. This is why it is missing from the SQL in section 5.

**verified.** `Auth/Middleware/EnsureEmailIsVerified.php:31-41`:

```php
if (! $request->user() || ($request->user() instanceof MustVerifyEmail &&
    ! $request->user()->hasVerifiedEmail())) {
    return $request->expectsJson() ? abort(403, ...) : Redirect::guest(URL::route($redirectToRoute ?: 'verification.notice'));
```

`App\Models\User` implements `MustVerifyEmail` (`app/Models/User.php:34`), so this check is active.

**throttle:chirps.** The app registers the limiter with
`RateLimiter::for('chirps', fn ($request) => Limit::perMinute(10)->by((string) $request->user()?->id))`.
`Cache/RateLimiter.php:49-53` stores the closure in `$this->limiters[$name]`. Then, in `Routing/Middleware/ThrottleRequests.php`:

- `:87-90`: a single string argument that names a registered limiter takes the named path:
  `if (is_string($maxAttempts) && func_num_args() === 3 && ! is_null($limiter = $this->limiter->limiter($maxAttempts)))`
- `:121` calls the closure. `:134` builds the key: `self::$shouldHashKeys ? md5($limiterName.$limit->key) : ...`.
  `$shouldHashKeys` defaults to `true` (`:34`), so the key is `md5('chirps'.$userId)`.
- `:156-159`: `if ($this->limiter->tooManyAttempts(...)) throw $this->buildException(...)`. Otherwise `:162-164`
  calls `hit()` *before* `$next`, so failed validations also count toward the limit.
- `:256` throws `new ThrottleRequestsException('Too Many Attempts.', null, $headers)`. That class extends Symfony's
  `TooManyRequestsHttpException` (`Http/Exceptions/ThrottleRequestsException.php:8`), whose constructor calls
  `parent::__construct(429, ...)` (`vendor/symfony/http-kernel/Exception/TooManyRequestsHttpException.php:30`).
  `Retry-After` and `X-RateLimit-*` headers are added at `:309-315`.

## 3. Route model binding and authorization

This route has no `{chirp}` parameter, so SubstituteBindings runs but binds nothing. There is no policy or Gate
call, and `StoreChirpRequest::authorize()` returns `true`.

## 4. FormRequest resolution and validation

`Route::run()` → `runController()` (`Routing/Route.php:274-278`) → `ControllerDispatcher::dispatch`
(`Routing/ControllerDispatcher.php:38-46`) → `resolveParameters`. For a class-typed parameter,
`Routing/ResolvesRouteDependencies.php:92` calls `$this->container->make($className)`.

Container hooks in `Foundation/Providers/FormRequestServiceProvider.php:27-38`:

```php
$this->app->afterResolving(ValidatesWhenResolved::class, function ($resolved) {
    $resolved->validateResolved();
});
$this->app->resolving(FormRequest::class, function ($request, $app) {
    $request = FormRequest::createFrom($app['request'], $request);
    $request->setContainer($app)->setRedirector($app->make(Redirector::class));
});
```

`resolving` copies the current (already trimmed) request into the FormRequest. `afterResolving` then runs
validation **before the controller method body executes**. `Validation/ValidatesWhenResolvedTrait.php:17-35` runs
`prepareForValidation()`, then `passesAuthorization()`, then `getValidatorInstance()`, then
`if ($instance->fails()) $this->failedValidation($instance);`, then `passedValidation()`.
The data comes from `validationData()` → `$this->all()` (`Foundation/Http/FormRequest.php:197-199`).

**On failure** (`Foundation/Http/FormRequest.php:314-320`):

```php
throw (new $exception($validator))->errorBag($this->errorBag)->redirectTo($this->getRedirectUrl());
```

`getRedirectUrl()` (`:328-337`) falls back to `$url->previous()`. The route pipeline catches the exception
(`Routing/Pipeline.php:40-51`, `$handler->render($passable, $e)`), so StartSession still saves the session.
`Foundation/Exceptions/Handler.php:894-898` builds the response:

```php
return redirect($exception->redirectTo ?? url()->previous())
    ->withInput(Arr::except($request->input(), $this->dontFlash))
    ->withErrors($exception->errors(), $request->input('_error_bag', $exception->errorBag));
```

`$request->validated()` (`FormRequest.php:392-394`) returns `data_get($this->validator->validated(), ...)`, which here
is `['message' => '...']`.

## 5. `$user->chirps()->create()` and the SQL

`User::chirps()` returns `hasMany(Chirp::class)`. `Database/Eloquent/Relations/HasOneOrMany.php:407-415`:

```php
return tap($this->related->newInstance($attributes), function ($instance) {
    $this->setForeignAttributesForCreate($instance);
    $instance->save();
```

- `newInstance` (`Database/Eloquent/Model.php:788-804`) runs `$model->fill((array) $attributes)`. Mass assignment
  only ever sees `['message' => ...]`.
- `setForeignAttributesForCreate` (`HasOneOrMany.php:517-519`) then calls
  `$model->setAttribute($this->getForeignKeyName(), $this->getParentKey());`. This is a direct `setAttribute`, not
  `fill`, so `user_id` never needs to be fillable. `getParentKey()` is `$this->parent->getAttribute($this->localKey)` (`:604-606`).

**Mass-assignment guard.** `#[Fillable(['message'])]` is merged in
`Database/Eloquent/Concerns/GuardsAttributes.php:49` (`mergeFillable(static::resolveClassAttribute(Fillable::class, 'columns') ?? [])`).
`fillableFromArray` (`:285-288`) intersects the input with the fillable list. In `Model::fill` (`Model.php:722-736`):

```php
if (count($attributes) !== count($fillable) && static::preventsSilentlyDiscardingAttributes()) {
    $keys = array_diff(array_keys($attributes), array_keys($fillable));
    $exception = new MassAssignmentException(sprintf('Add fillable property [%s] ...
```

`Model::shouldBeStrict(! app()->isProduction())` (AppServiceProvider) calls
`preventSilentlyDiscardingAttributes($shouldBeStrict)` (`Model.php:583-587`). So outside production, an extra
`user_id` key passed to `fill()`/`create()` throws `MassAssignmentException` instead of being dropped silently. In
production, the key is silently discarded. `tests/Feature/Chirps/ChirpModelTest.php` asserts the throwing case.
The controller is safe either way, because `validated()` only contains `message`.

**Save → SQL.** `Model::performInsert` (`Model.php:1613-1636`) fires `creating`, calls `updateTimestamps()`, then
`insertAndSetId` (`:1722-1726`) → `Query\Builder::insertGetId` (`Database/Query/Builder.php:4316-4324`:
`$sql = $this->grammar->compileInsertGetId(...)`) → `Processor::processInsertGetId`
(`Database/Query/Processors/Processor.php:30-36`: `$query->getConnection()->insert($sql, $values);` then
`getPdo()->lastInsertId($sequence)`).

**Captured SQL.** This came from a throwaway Pest test that posted `'   Hello SQL   '` as a verified user using
`actingAs`, with `DB::enableQueryLog()` (sqlite `:memory:`). The test was deleted afterwards.

```
insert into "chirps" ("message", "user_id", "updated_at", "created_at") values (?, ?, ?, ?)
bindings: ['Hello SQL', 1, '2026-10-24 12:55:46', '2026-10-24 12:55:46']   (0.03 ms, write)
```

There was exactly one query. The binding is trimmed (section 1), and `user_id` comes after `message`, which shows
it was set after `fill()`. The session driver is `array` in tests, so there are no session queries. In production
there would also be a `select * from "users" where "id" = ? limit 1` from the session guard (section 2). That
query was not captured here, so it is unverified for this app's `.env` session driver.

## 6. Response: redirect, flash, session save

`redirect()` with no arguments returns the `Redirector` (`Foundation/helpers.php:707-711`).
`Redirector::route` (`Routing/Redirector.php:151-153`) returns a 302 to the `home` URL. `->with('success', ...)`
(`Http/RedirectResponse.php:44-49`) calls `$this->session->flash($k, $v)`. `Session/Store.php:471-477` then runs
`put($key, $value)` and `push('_flash.new', $key)`.

`Router::prepareResponse` normalizes the response. ThrottleRequests adds `X-RateLimit-Limit/Remaining`
(`ThrottleRequests.php:175-179`). Then **StartSession** finishes in `handleStatefulRequest`
(`Session/Middleware/StartSession.php:109-129`):

```php
$response = $next($request);
$this->storeCurrentUrl($request, $session);
$this->addCookieToResponse($response, $session);
$this->saveSession($request);
```

`saveSession` (`:245-248`) calls `$this->manager->driver()->save()`. `Store::save` (`Session/Store.php:181-189`)
calls `ageFlashData()`, which moves `_flash.new` to `_flash.old` (`:233-239`), then writes the data with
`$this->handler->write(...)`. On the next GET `/`, `success` is still readable. The save after that one forgets it.

**There is no `StartSession::terminate()` in this version.** Grepping `terminate` in `StartSession.php` finds no
match, so the session is saved inline during `handle`, before the response goes back out through the pipeline.
After `$response->send()`, `Kernel::terminate` (`Foundation/Http/Kernel.php:211-215`) dispatches `Terminating` and
calls `terminateMiddleware` (`:243-262`), which runs `terminate()` only on middleware that define it, then
`$app->terminate()`.

## 7. Events, listeners, jobs

`performInsert` fires the Eloquent `creating`/`created` model events (`Model.php:1619`, `:1659`), and save fires
`saving`/`saved`. The app registers no Chirp observers or listeners, and no jobs are dispatched. The framework also
dispatches `Routing` and `RouteMatched` (`Router.php:787`, `:809`).
