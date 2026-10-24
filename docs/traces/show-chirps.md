# Trace: `GET /` (`home`, `ChirpController@index`)

Laravel 13.38.0 (`php artisan --version`). Paths are relative to `vendor/laravel/framework/src/Illuminate/` unless
they start with `vendor/`, `app/`, `resources/` or `database/`.

```
Browser  Kernel+web MW   Controller     Eloquent Builder    Grammar/PDO        View/Blade          SessionGuard
  | GET / |                |                  |                  |                  |                  |
  |------>| (see create-chirps.md §1-2)       |                  |                  |                  |
  |       |--------------->| with('user')->latest()->latest('id')->take(50)->get()    |                  |
  |       |                |----------------->| getModels -> query->get              |                  |
  |       |                |                  |----------------->| select chirps ... order by ... limit 50
  |       |                |                  | hydrate (preventsLazyLoading if >1)  |                  |
  |       |                |                  | eagerLoadRelations -> BelongsTo      |                  |
  |       |                |                  |----------------->| select users where id in (1, 2)     |
  |       |                |                  | match(): setRelation('user')         |                  |
  |       |                | view('home') --------------------------------------->| isExpired? compile|
  |       |                |                  |                  |                  | @auth -> check() ->|
  |       |                |                  |                  |<-------------------------------------| users where id=? (only via session)
  |       |                |                  |                  |                  | <x-chirp> x N, e()|
  |<------| 200 HTML; StartSession saves session                 |                  |                  |
```

## 1. Kernel, global and web middleware

These hops are the same as in [create-chirps.md](create-chirps.md) §1 (index.php → `handleRequest` → Kernel
pipeline) and §2 (router plus the `web` group, whose CSRF middleware is `PreventRequestForgery` in 13.x).
`php artisan route:list --path=/ -v` shows only `⇂ web` for `GET|HEAD / .. home`, so there is no `auth`, `verified` or
`throttle`. `PreventRequestForgery` passes immediately because `isReading()` is true for GET. There is no `{param}`
for SubstituteBindings to bind, no Gate or Policy, no FormRequest, and no events, listeners or jobs beyond the
framework's `Routing`/`RouteMatched` and the `retrieved` model events fired during hydration.

## 2. `Chirp::with('user')` eager loading

`with()` stores the constraint closures: `$this->eagerLoad = array_merge($this->eagerLoad, $eagerLoad);`
(`Database/Eloquent/Builder.php:1795`). `get()` (`Builder.php:926-939`) runs the main query first and eager loads only if there are rows:

```php
if (count($models = $builder->getModels($columns)) > 0) {
    $models = $builder->eagerLoadRelations($models);
}
```

`eagerLoadRelations` (`:961-973`) calls `eagerLoadRelation` for each top-level name. `eagerLoadRelation`
(`:983-1000`) then runs:

```php
$relation = $this->getRelation($name);          // :1009, via Relation::noConstraintsForRelation
$relation->addEagerConstraints($models);
$constraints($relation);
return $relation->match($relation->initRelation($models, $name), $relation->getEager(), $name);
```

**`BelongsTo::addEagerConstraints`** (`Database/Eloquent/Relations/BelongsTo.php:105-114`):

```php
$key = $this->getQualifiedOwnerKeyName();                        // "users"."id"
$whereIn = $this->whereInMethod($this->related, $this->ownerKey);
$this->whereInEager($whereIn, $key, $this->getEagerModelKeys($models));
```

- `getEagerModelKeys` (`:123-137`) collects the non-null `user_id`s, then runs `sort($keys)` and
  `array_values(array_unique($keys))`. That is why the SQL shows `in (1, 2)` sorted and de-duplicated.
- `whereInMethod` (`Relations/Relation.php:506-512`) returns `'whereIntegerInRaw'` when the key is the int primary
  key. `Query/Builder.php:1571-1575` casts each value `(int)` and stores `'type' => 'InRaw'`.
  `Query/Grammars/Grammar.php:431-436` inlines the values (`' in ('.implode(', ', $where['values']).')'`).
  So the eager query has **no bindings**.
- If every key is null, `whereInEager` sets `eagerKeysWereEmpty` (`Relation.php:494-496`), and `getEager()`
  (`:244-248`) returns an empty collection without running a query.

**`match()`** (`BelongsTo.php:152-177`) builds `$dictionary[$ownerKey] = $user` and then, for each chirp,
`$model->setRelation($relation, $dictionary[$attribute])`. Before that, `initRelation` (`:142-149`) sets every
chirp's relation to `getDefaultFor($model)`, which is null when there is no `withDefault()`.

**Strict mode / lazy loading.** `Model::shouldBeStrict(! app()->isProduction())` (AppServiceProvider) calls
`static::preventLazyLoading($shouldBeStrict)` (`Database/Eloquent/Model.php:583-587`). In the view,
`$chirp->user` goes through `__get` → `getAttribute` (`Model.php:2804-2806`). Because `user` is not a column,
it reaches `getRelationValue` (`Database/Eloquent/Concerns/HasAttributes.php:504-506`, `:564-589`):

```php
if ($this->relationLoaded($key)) { return $this->relations[$key]; }   // eager-loaded: returns here
...
if ($this->preventsLazyLoading) { $this->handleLazyLoadingViolation($key); }
return $this->getRelationshipFromMethod($key);                          // the lazy query (:640-659)
```

`handleLazyLoadingViolation` (`HasAttributes.php:615-630`) builds `new LazyLoadingViolationException($this, $key)`
("Attempted to lazy load [user] on model [App\Models\Chirp] but lazy loading is disabled.",
`Database/LazyLoadingViolationException.php:33`). If a callback was registered with
`handleLazyLoadingViolationUsing`, it calls that instead. It **returns silently** if `! $this->exists ||
$this->wasRecentlyCreated`, and otherwise throws.

**Gotcha: the per-model flag is only set when more than one row is hydrated** (`Builder.php:516-518`):

```php
if (count($items) > 1) {
    $model->preventsLazyLoading = Model::preventsLazyLoading();
}
```

The throwaway test confirmed this. `Chirp::latest()->get()` with 3 rows followed by `->first()->user` threw
`LazyLoadingViolationException`. `Chirp::first()->user` (1 row) lazy loaded without an error. So if `with('user')`
were removed, a test that seeds only one chirp (for example `TimelineTest` "guest sees chirps with their author")
would still pass. The N+1 only fails tests that render two or more chirps.

## 3. `latest()` / `latest('id')` / `take(50)` in the SQLite grammar

- `Eloquent\Builder::latest($column = null)` (`Database/Eloquent/Builder.php:468-480`) uses
  `$this->model->getCreatedAtColumn() ?? 'created_at'` and qualifies it only when there are joins. It then calls
  `$this->query->latest($column)`.
- `Query\Builder::latest` (`Query/Builder.php:3087-3090`) is `return $this->orderBy($column, SortDirection::Descending);`.
  `orderBy` (`:3052-3065`) maps the enum to `'desc'` and appends `['column' => ..., 'direction' => 'desc']` to
  `$this->orders`. The second `latest('id')` appends a second entry.
- `take()` → `limit()` (`:3229-3248`): `$this->$property = ! is_null($value) ? (int) $value : null;`.
- Compilation: `$selectComponents` lists `'orders'` and then `'limit'` (`Query/Grammars/Grammar.php:47-48`).
  `SQLiteGrammar` (`Query/Grammars/SQLiteGrammar.php:11`) does **not** override either one, so the base methods
  apply: `compileOrders` → `'order by '.implode(', ', ...)` (`Grammar.php:1041-1047`), each entry being
  `$this->wrap($order['column']).' '.$order['direction']` (`:1069`). `compileLimit` → `'limit '.(int) $limit` (`:1109-1112`).
  Identifiers are wrapped in double quotes (`Database/Grammar.php:161`).
- Execution: `Query\Builder::get` → `runSelect` (`Query/Builder.php:3638-3642`) → `Connection::select`
  (`Database/Connection.php:431-449`: `prepare($query)`, `bindValues`, `execute()`, `fetchAll`), wrapped in
  `run()`, which logs the query.

## 4. The actual SQL

This came from a throwaway Pest test with sqlite `:memory:` and `SESSION_DRIVER=array` (phpunit.xml). It seeded
users Ada and Bob and 3 chirps (a1 one minute old, then b1 and a2 at the same frozen time), and wrapped the request
in `DB::enableQueryLog()`. The log was written to a scratch file. The test was deleted afterwards, and `git status`
showed no trace of it.

```
guest (2 queries)
  select * from "chirps" order by "created_at" desc, "id" desc limit 50     bindings=[]
  select * from "users" where "users"."id" in (1, 2)                        bindings=[]
actingAs($ada) (2 queries, identical)
session holding login_web_<sha1> = 1  (3 queries)
  ...the two above, then:
  select * from "users" where "id" = ? limit 1                              bindings=[1]
```

The page HTML order was `a2, b1, a1`. The extra query comes from `@auth`, which compiles to
`<?php if(auth()->guard()->check()): ?>` (`View/Compilers/Concerns/CompilesConditionals.php:22-26`) →
`GuardHelpers::check()` (`Auth/GuardHelpers.php:54`) → `SessionGuard::user()` (`Auth/SessionGuard.php:171`) →
`EloquentUserProvider::retrieveById` (`Auth/EloquentUserProvider.php:52-58`, `where(id)->first()`). The guard
caches the user, so the second `@auth` in the layout and `auth()->user()->name` don't query again.
`actingAs` → `be()` → `setUser($user)` (`Foundation/Testing/Concerns/InteractsWithAuthentication.php:43-49`)
skips that lookup, which is why it doesn't appear. A remember-me cookie or a database session driver would add
more queries. That is unverified here because `.env` was not read.

## 5. Blade: `{{ }}`, `<x-chirp>`, and compiled-view caching

**Echo.** `compileEchos` runs `compileRawEchos`, `compileEscapedEchos` and `compileRegularEchos`, in that order
(`View/Compilers/Concerns/CompilesEchos.php:39-60`). `compileRegularEchos` (`:89-100`) wraps the expression with
`sprintf($this->echoFormat, ...)`, where `DEFAULT_ECHO_FORMAT = 'e(%s)'` (`View/Compilers/BladeCompiler.php:44`).
In the test, `{{ $chirp->message }}` compiled to `<?php echo e($chirp->message); ?>`. `e()`
(`Support/helpers.php:124-137`) returns `Htmlable::toHtml()` unescaped. Otherwise it runs
`htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', $doubleEncode)` with
`$doubleEncode = true`, so a message containing `&amp;` is displayed literally as `&amp;`.

**`<x-chirp :chirp="$chirp" />`.** `ComponentTagCompiler::componentString` (`View/Compilers/ComponentTagCompiler.php:233-265`)
calls `componentClass('chirp')` (`:276-318`). There's no alias and no `App\View\Components\Chirp` class, so it calls
`guessAnonymousComponentUsingNamespaces` (`:363-391`). That method `->prepend('components', $component)`s a default
entry, so `guessViewName('chirp', 'components')` (`:457-468`) yields the view `components.chirp`. Because
`! class_exists('components.chirp')`, the class becomes `AnonymousComponent` (`:246-256`). Captured output:
`Illuminate\View\AnonymousComponent::resolve(['view' => 'components.chirp','data' => ['chirp' => $chirp]] ...)`.
Livewire also registers `resources/views/components` as an anonymous path
(`vendor/livewire/livewire/src/LivewireServiceProvider.php:129-135`, from the `component_locations` default in
`vendor/livewire/livewire/config/livewire.php:16-19`), but the namespace guess runs first.
**`<x-layouts::public>`** resolves through Livewire's `component_namespaces` default `'layouts' =>
resource_path('views/layouts')` (`livewire.php:32-35`), via `anonymousComponentPath($location, 'layouts')`
(`LivewireServiceProvider.php:143`). `BladeCompiler::anonymousComponentPath` (`BladeCompiler.php:843-856`)
registers a view namespace named `xxh128('layouts')`. The compiled output's view was `f4ac99e0...::public`.

**When does a recompile happen?** `View::getContents` → `$this->engine->get($this->path, ...)` (`View/View.php:208`)
→ `CompilerEngine::get` (`View/Engines/CompilerEngine.php:60-94`):

```php
if (! isset($this->compiledOrNotExpired[$path]) && $this->compiler->isExpired($path)) {
    $this->compiler->compile($path);
}
```

`Compiler::isExpired` (`View/Compilers/Compiler.php:105-133`) returns:
1. `true` if `! $this->shouldCache` (`view.cache`, default `true`, `View/ViewServiceProvider.php:101`);
2. `true` if the compiled file does not exist;
3. `false` if `! $this->shouldCheckTimestamps` (`view.check_cache_timestamps`, default `true`, `:103`);
4. otherwise `filemtime(source) >= filemtime(compiled)` (`Filesystem/Filesystem.php:491-493` is a bare `filemtime`).

The app has no `config/view.php`, and both keys come back `NULL` from config, so the defaults apply. The compiled
file is `storage/framework/views/` + `xxh128('v2'.$path)` + `.php` (`Compiler.php:92-94`). For
`components/chirp.blade.php` that is `.../d475e1422b17768e501810e304d33123.php`. The key is the path, not the content.

Other details that matter for mutation testing:
- **Memo.** After the first check, `compiledOrNotExpired[$path] = true` (`CompilerEngine.php:91`) skips
  `isExpired` for the rest of that engine's life. It is reset by `forgetCompiledOrNotExpired()` (`:147-150`) from
  an `$app->terminating` callback (`ViewServiceProvider.php:173-175`) and by `view:clear`
  (`Foundation/Console/ViewClearCommand.php:63`). Each Pest test builds a fresh app, so the memo doesn't carry
  across tests. In long-lived workers it lasts until terminate.
- **Same-content compile.** `BladeCompiler::compile` (`BladeCompiler.php:200-217`) only rewrites when the
  `xxh128` hash differs. Otherwise it runs `touch($compiledPath, $lastModified + 1)`, which pushes the compiled
  mtime one second past the source mtime.
- **Why stale views happen.** The check compares mtimes, not content. If the original is restored with
  something that keeps an *older* mtime (`cp -p`, `mv backup.blade.php chirp.blade.php`, an editor "revert" that
  preserves mtime), then `source mtime < compiled(mutant) mtime`, `isExpired` returns false, and the mutant's
  compiled PHP keeps being served. The `+1` touch makes this possible even for an edit in the same second
  (unverified, reasoned from source and not reproduced). The fix is to run `php artisan view:clear` (or `touch`
  the source) after every restore.

## 6. Why ties on `created_at` come back id-descending on SQLite

`EXPLAIN QUERY PLAN` against `database/database.sqlite` (SQLite 3.54.0):

```
order by created_at desc, id desc limit 50  ->  SCAN chirps USING INDEX chirps_created_at_index
order by created_at desc limit 50           ->  SCAN chirps USING INDEX chirps_created_at_index
users where id in (1,2)                     ->  SEARCH users USING INTEGER PRIMARY KEY (rowid=?)
```

There is no `USE TEMP B-TREE FOR ORDER BY` in either plan. `id integer primary key` is an alias for the rowid,
and every SQLite index entry stores the indexed columns followed by the rowid. So `chirps_created_at_index` is
effectively ordered by `(created_at, rowid)`, and scanning it backwards yields `created_at desc, id desc` without
a sort. That is why the plan is the same with or without `latest('id')`. This comes from the SQLite query-planner
docs (sqlite.org/queryplanner.html), which were not fetched here.

Without the index the result changes. In an in-memory copy of the schema with three equal `created_at`s, the
index scan returned `third,second,first`. Forcing a sort with `order by +created_at desc` (plan: `SCAN chirps` +
`USE TEMP B-TREE FOR ORDER BY`) returned `first,second,third`. **The id-desc tie order is a side effect of the
chosen plan, not a guarantee.** `latest('id')` turns it into a guarantee (see [ADR 0004](../adr/0004-deterministic-timeline-order.md)).

**Postgres:** this is not guaranteed. The PostgreSQL `SELECT` reference says rows equal on every ORDER BY
expression "are returned in an implementation-dependent order". That quote is from memory and the docs were not
fetched, so it is unverified. Postgres also has no rowid in its indexes. Duplicate btree entries are ordered by
heap TID (physical location, which changes on UPDATE), and the planner may choose a seq scan plus top-N heapsort,
which is not stable. This is unverified (no Postgres source in `vendor/`). Keep `latest('id')`.
