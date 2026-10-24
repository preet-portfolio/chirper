# Trace: `PUT /chirps/{chirp}` (`chirps.update`)

Laravel 13.38.0, PHP 8.5.12, sqlite `:memory:` in tests. Paths are relative to
`vendor/laravel/framework/src/Illuminate/` unless they start with `vendor/` or `app/`.

```
Browser    Kernel        Router       web group        auth/verified   SubstituteBindings  Controller DI     Gate/Policy        Eloquent/PDO
  | POST /chirps/1 (_method=PUT) |          |                 |                |                  |                  |                  |
  |------->| enableHttpMethodParameterOverride()               |                |                  |                  |                  |
  |        | global MW (see create trace)  |                 |                |                  |                  |                  |
  |        |------------>| match(): getMethod() -> "PUT"       |                |                  |                  |                  |
  |        |             |------------>| Cookies, Session, Errors, CSRF       |                  |                  |                  |
  |        |             |             |---------------->| check()? guest -> 302 /login (no DB lookup of chirp) |       |                  |
  |        |             |             |                 |--------------->| resolveRouteBinding("1")             |                  |
  |        |             |             |                 |                |------------------------------------------------------->| SELECT chirps
  |        |             |             |                 |                |  null -> ModelNotFoundException -> 404                  |
  |        |             |             |                 |                |----------------->| make(UpdateChirpRequest)            |
  |        |             |             |                 |                |                  | validateResolved: authorize() FIRST |
  |        |             |             |                 |                |                  |----------------->| getPolicyFor -> ChirpPolicy (guessed)
  |        |             |             |                 |                |                  |                  | user()->is($user): key compare, 0 queries
  |        |             |             |                 |                |                  |<-- false: AuthorizationException -> 403
  |        |             |             |                 |                |                  | true: validate rules -> 302 back w/ errors on fail
  |        |             |             |                 |                |                  | update($request->validated()) ------>| UPDATE chirps
  |<-------------------------------- 302 /  + flash 'success' (session saved by StartSession)                                   |
```

## 1. Shared hops (not repeated)

`public/index.php` → `Application::handleRequest` → `Kernel::handle`, the global middleware (TrimStrings,
ConvertEmptyStringsToNull, ...), the `web` group (EncryptCookies, AddQueuedCookiesToResponse, StartSession,
ShareErrorsFromSession, **PreventRequestForgery**, SubstituteBindings), and the `auth`/`verified` middleware are
covered in [create-chirps.md section 1](create-chirps.md#1-entry-point-and-global-middleware) and
[section 2](create-chirps.md#2-routing-the-web-group-and-route-middleware). The redirect, flash, and session save are covered in
[section 6](create-chirps.md#6-response-redirect-flash-session-save). The `php artisan route:list --path=chirps -v` output for this route:

```
 PUT chirps/{chirp} .. chirps.update › ChirpController@update
 ⇂ web
 ⇂ Illuminate\Auth\Middleware\Authenticate
 ⇂ Illuminate\Auth\Middleware\EnsureEmailIsVerified
```

App hooks: `routes/web.php` (the `auth` + `verified` group), `ChirpController@edit` / `@update`,
`app/Http/Requests/UpdateChirpRequest.php`, `app/Policies/ChirpPolicy.php`, `resources/views/chirps/edit.blade.php`
(`@method('PUT')` → `<input type="hidden" name="_method" value="PUT">`), and `resources/views/components/chirp.blade.php` (`@can`).

## 2. Method spoofing: POST + `_method=PUT` → PUT

Override support is turned on twice. It happens in `Request::capture()` (`Http/Request.php:78-82`):

```php
static::enableHttpMethodParameterOverride();
return static::createFromBase(SymfonyRequest::createFromGlobals());
```

and again at the very top of `Kernel::handle`, **before** any middleware or routing (`Foundation/Http/Kernel.php:141-144`):

```php
try {
    $request->enableHttpMethodParameterOverride();
    $response = $this->sendRequestThroughRouter($request);
```

The override is resolved lazily the first time something calls `getMethod()`
(`vendor/symfony/http-foundation/Request.php:1263-1299`):

```php
$this->method = strtoupper($this->server->get('REQUEST_METHOD', 'GET'));
if ('POST' !== $this->method || !(self::$allowedHttpMethodOverride ?? true)) { return $this->method; }
$method = $this->headers->get('X-HTTP-METHOD-OVERRIDE');
if (!$method && self::$httpMethodParameterOverride) {
    $method = $this->request->get('_method', $this->query->get('_method', 'POST'));
```

Only a real POST can be overridden. Overrides to GET/HEAD/CONNECT/TRACE are ignored (`:1287-1289`), and a non-letter
method throws `SuspiciousOperationException` (`:1295-1297`). The result is cached in `$this->method`, and `Http/Request.php:100-103`
(`method()`) just proxies to it. Routing reads it in `Routing/RouteCollection.php:200`:
`$routes = $this->get($request->getMethod());`, so the PUT route is matched. The spoof is applied after
global middleware and before route matching. Empirically, `post('/chirps/1', ['_method' => 'PUT', ...])` as the author returned
302 and changed the message.

## 3. Middleware order, implicit binding, and the 404

**Priority.** `Router::gatherRouteMiddleware` → `sortMiddleware` (`Routing/Router.php:844-846`, `:902-905`) sorts by
`$middlewarePriority` (`Foundation/Http/Kernel.php:102-115`):

```php
\Illuminate\Session\Middleware\StartSession::class,
\Illuminate\View\Middleware\ShareErrorsFromSession::class,
\Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
...
\Illuminate\Routing\Middleware\SubstituteBindings::class,
```

`Authenticate implements AuthenticatesRequests` (`Auth/Middleware/Authenticate.php:11`), so `auth` is sorted ahead of
SubstituteBindings, even though the `web` group lists SubstituteBindings before the route's own `auth`. `bootstrap/app.php`
does not call `->priority(...)` (`Foundation/Configuration/Middleware.php:411`), so the default list applies.

**Binding.** `Routing/Middleware/SubstituteBindings.php:41-49`:

```php
try {
    $this->router->substituteBindings($route);
    $this->router->substituteImplicitBindings($route);
} catch (ModelNotFoundException $exception) {
    if ($route->getMissing()) { return $route->getMissing()($request, $exception); }
    throw $exception;
```

`Router::substituteImplicitBindings` (`Routing/Router.php:990-996`) calls `ImplicitRouteBinding::resolveForRoute`.
That method loops over the controller signature's `UrlRoutable` parameters (`Routing/ImplicitRouteBinding.php:29`),
makes a blank `Chirp` (`:40`), and calls `resolveRouteBinding` (`:46`). If that returns null, it throws (`:60-61`):

```php
} elseif (! $model = $instance->{$routeBindingMethod}($parameterValue, $route->bindingFieldFor($parameterName))) {
    throw (new ModelNotFoundException)->setModel(get_class($instance), [$parameterValue]);
```

`Database/Eloquent/Model.php:2541-2543` / `:2627-2629`: `resolveRouteBindingQuery($this, $value, $field)->first()` →
`$query->where($field ?? $this->getRouteKeyName(), $value)`. `getRouteKeyName()` is the `#[RouteKey]` attribute or `id`
(`:2531`). The Handler maps the exception to a 404 (`Foundation/Exceptions/Handler.php:773`):
`$e instanceof ModelNotFoundException => new NotFoundHttpException($e->getMessage(), $e),`.

**Can a guest probe for chirp 999?** No. Empirically, in a throwaway test:

| request | result |
|---|---|
| guest `PUT /chirps/999` | 302 → `/login` |
| guest `PUT /chirps/1` (exists) | 302 → `/login` |
| guest `GET /chirps/999/edit` | 302 → `/login` |
| verified user `PUT /chirps/999` | 404 |

Both responses are identical, and the guest never triggers the chirp `SELECT`, because Authenticate throws before
SubstituteBindings runs. Any logged-in, verified user *can* tell a missing id (404) from someone else's chirp (403). With
auto-increment ids that leaks little, but it is a real difference.

## 4. Policy resolution, `Gate::authorize` vs `Gate::allows`, `@can`

The Gate's user comes from `new Gate($app, fn () => call_user_func($app['auth']->userResolver()))` (`Auth/AuthServiceProvider.php:60`).
`Gate::raw` (`Auth/Access/Gate.php:428-452`) runs the before callbacks, then `callAuthCallback` → `resolveAuthCallback`
(`:621-625`):

```php
if (isset($arguments[0]) &&
    ! is_null($policy = $this->getPolicyFor($arguments[0])) &&
    $callback = $this->resolvePolicyCallback($user, $ability, $arguments, $policy)) {
```

`getPolicyFor` (`:653-693`) tries, in order: `$this->policies[$class]` (empty in this app, `Gate::policy()` is never
called), the `#[UsePolicy]` attribute (`:666-670`), then `guessPolicyName` (`:672-676`):
`if (class_exists($guessedPolicy)) { return $this->resolvePolicy($guessedPolicy); }`.

`guessPolicyName` (`:724-745`) builds `{prefix}\Policies\{Basename}Policy` for each namespace prefix. For `App\Models\Chirp`
the dirname is `App\Models`, which yields `App\Policies\ChirpPolicy` and `App\Models\Policies\ChirpPolicy`. The extra
`\Models\` → `\Policies\` branch does not fire, because `str_contains('App\Models', '\Models\')` is false (there is no trailing
backslash). The list is reversed, and the first existing class wins. A throwaway test confirmed it returns
`["App\\Policies\\ChirpPolicy"]` with `$policies === []`. `resolvePolicy` is `$this->container->make($class)` (`:767-770`).
`resolvePolicyCallback` (`:781-806`) checks `is_callable([$policy, 'update'])`, calls `before()` if present, then
`callPolicyMethod` → `$policy->update($user, ...$arguments)` (`:851-853`).

- `allows` → `check` → `inspect(...)->allowed()` (`:326-328`, `:350-354`). It returns a bool and never throws. This is what
  `UpdateChirpRequest::authorize()` uses.
- `authorize` → `$this->inspect($ability, $arguments)->authorize()` (`:390-393`), which `ChirpController@edit` uses.
  `Auth/Access/Response.php:150-153`: `if ($this->denied()) { throw (new AuthorizationException(...))->setResponse($this)->withStatus($this->status); }`.
  `inspect` turns a `false` into `Response::deny()` (`:410-412`).
- The Handler turns it into a 403 (`Foundation/Exceptions/Handler.php:777`):
  `$e instanceof AuthorizationException && ! $e->hasStatus() => new AccessDeniedHttpException($e->getMessage(), $e),`.

**`@can`** compiles to a bool check (`View/Compilers/Concerns/CompilesAuthorizations.php:15`):
`"<?php if (app(\Illuminate\Contracts\Auth\Access\Gate::class)->check{$expression}): ?>"`, and `@endcan` compiles to `<?php endif; ?>` (`:78-80`).
For guests on the timeline, `canBeCalledWithUser(null, ...)` (`Gate.php:463-471`) only calls policy methods that
allow a null user. `update(User $user, ...)` is non-nullable, so the link is hidden without calling the policy (the guest check itself comes from
`methodAllowsGuests`, not traced line by line, so this is unverified).

## 5. FormRequest ordering: authorize before validate

Container hooks and `createFrom` are covered in [create-chirps.md section 4](create-chirps.md#4-formrequest-resolution-and-validation).
`Validation/ValidatesWhenResolvedTrait.php:19-25`:

```php
$this->prepareForValidation();
if (! $this->passesAuthorization()) {
    $this->failedAuthorization();
}
$instance = $this->getValidatorInstance();
```

`passesAuthorization` (`Foundation/Http/FormRequest.php:347-353`) runs `$this->container->call([$this, 'authorize'])`, and
`failedAuthorization` (`:365-367`) runs `throw new AuthorizationException;`. Authorization (line 21) runs before the validator is even built
(line 25). `$this->route('chirp')` (`Http/Request.php:687-695`, `$route->parameter($param, $default)`) already returns the
bound `Chirp` model, because SubstituteBindings ran earlier in the route pipeline.
Evidence: `tests/Feature/Chirps/EditChirpTest.php` ("non-author gets 403 before validation runs", `message => ''`). In a
throwaway test, a non-author sending 300 characters got 403 with `session('errors') === null`. In the existing tests, 18 of 18 pass (EditChirpTest + Policies).

## 6. `$chirp->user()->is($user)`: comparing keys without a query

`BelongsTo` uses `ComparesRelatedModels` (`Database/Eloquent/Relations/BelongsTo.php:22`).
`Database/Eloquent/Relations/Concerns/ComparesRelatedModels.php:18-29`:

```php
$match = ! is_null($model) &&
       $this->compareKeys($this->getParentKey(), $this->getRelatedKeyFrom($model)) &&
       $this->related->getTable() === $model->getTable() &&
       $this->related->getConnectionName() === $model->getConnectionName();
if ($match && $this instanceof SupportsPartialRelations && $this->isOneOfMany()) { ...->exists(); }
```

BelongsTo extends `Relation` directly (`BelongsTo.php:20`), so the `exists()` branch never applies here. `getParentKey()` is
`$this->child->user_id` (`:326-328`, `:368-372`), and `getRelatedKeyFrom` is `$model->{$this->ownerKey}`, which is `$user->id` (`:357-359`).
Building `$chirp->user()` only creates the relation and does not execute it. A throwaway test with the query log on counted **0 queries**.

`compareKeys` (`:65-75`):

```php
if (empty($parentKey) || empty($relatedKey)) { return false; }
if (is_int($parentKey) || is_int($relatedKey)) { return (int) $parentKey === (int) $relatedKey; }
return $parentKey === $relatedKey;
```

It is strict, but if *either* side is an int it casts both to int first. It also rejects empty/0/null keys, and it checks the table and connection.

**Key types.** `Model::$keyType = 'int'` and `$incrementing = true` (`Model.php:87`, `:94`). `getCasts()` prepends the key cast
(`Database/Eloquent/Concerns/HasAttributes.php:1728-1729`):
`return array_merge([$this->getKeyName() => $this->getKeyType()], $this->casts);`, and `'int'` → `(int) $value` (`:871-873`).
So `$user->id` / `getKey()` is always an int, whatever the driver returns. `user_id` has **no** cast on `Chirp`.

**Measured (PHP 8.5.12, pdo_sqlite).** Laravel sets `PDO::ATTR_STRINGIFY_FETCHES => false` (`Database/Connectors/Connector.php:23`).
A raw `PDO::query('select id, user_id from chirps')` returned `id => int, user_id => int`, and Eloquent's raw attributes were also int.
`getCasts()` was `{"id":"int"}`.

**Contrast with `$chirp->user_id !== auth()->id()`.** In the test, `user_id` was forced to the string `'1'` (simulating a driver/config
that stringifies). `is()` returned `true`, because `$user->id` is a cast int, so the `(int)` branch applies. The strict `!==` evaluated
`'1' !== 1` → `true`, which **denies the real author**. The strict form fails closed (a false 403), not open. It is
still a bug on drivers or settings that return strings, and `is()` avoids it.

## 7. The SQL for an author's successful PUT

Throwaway Pest test: `actingAs($chirp->user)`, `DB::enableQueryLog()` right before `put('/chirps/1', ['message' => '  After  '])`,
with the log written to a scratch file. Afterwards the test was deleted, and `git status --short` shows no test file. The result was 302 → `/`:

```
select * from "chirps" where "id" = ? limit 1                         | ["1"]
update "chirps" set "message" = ?, "updated_at" = ? where "id" = ?    | ["After","2026-10-24 13:26:58",1]
```

- Query 1 comes from route binding (section 3). The binding is the **string** `"1"` from the URL segment.
- **There are no queries from the policy check.** The user is already loaded, and `is()` only compares attributes (section 6).
- Query 2: `Model::update` → `fill()->save()` (`Model.php:1180-1186`) → `performUpdate` (`:1527-1549`) fires `updating`, calls `updateTimestamps()`,
  then `setKeysForSaveQuery($query)->update($dirty)`, where the WHERE uses `getKeyForSaveQuery()` = `original['id']` (`:1590-1604`), which is the int `1`.
  Only the dirty `message` and `updated_at` columns are written. `'  After  '` was trimmed by TrimStrings.
- Not captured: in production, `SessionGuard` adds `select * from "users" where "id" = ? limit 1`
  ([create-chirps.md section 2](create-chirps.md#2-routing-the-web-group-and-route-middleware)), plus session-driver queries if the
  driver is `database`. This is unverified for this app's `.env`.

## 8. Response and events

`redirect()->route('home')->with('success', 'Chirp updated!')` follows the same flash and session-save path as
[create-chirps.md section 6](create-chirps.md#6-response-redirect-flash-session-save). Events: the Eloquent `saving`/`updating`/`updated`/`saved`
model events (`performUpdate`, `Model.php:1532`), `Illuminate\Auth\Access\Events\GateEvaluated` from `dispatchGateEvaluatedEvent`
(`Gate.php:449-451`), and the router's `RouteMatched`. The app registers no listeners, observers, or jobs for these.
