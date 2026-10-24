# Trace: `DELETE /chirps/{chirp}` (`chirps.destroy`), delta only

> **This trace describes the `v1-with-auth` request path.** On `main` (ADR 0008) there is no `auth`/verified middleware; route binding is scoped to `Chirp::live()`, so expired chirps 404, and `ChirpPolicy::delete` checks `AuthorToken::owns()` (the encrypted cookie's hash) instead of the user.

Laravel 13.38.0, sqlite `:memory:` in tests. Paths are relative to `vendor/laravel/framework/src/Illuminate/`.
Route: `routes/web.php:15` -> `ChirpController@destroy` (`Gate::authorize('delete', $chirp); $chirp->delete();
redirect()->route('home')->with('success', 'Chirp deleted!')`).

**Not repeated here:** kernel, global middleware, and the `web` group are in [create-chirps.md §1-2](create-chirps.md#1-entry-point-and-global-middleware);
redirect/flash/session save are in [create-chirps.md §6](create-chirps.md#6-response-redirect-flash-session-save).
`_method=DELETE` spoofing, implicit binding, the auth-before-bindings order that sends guests to `/login` instead of 404,
and policy resolution (`ChirpPolicy::delete`) all work the same way as `PUT`. See [edit-chirps.md §2-4](edit-chirps.md#2-method-spoofing-post--_methodput--put).

```
Browser            Router/MW (see edit trace)     Controller         Model::delete()            Query\Builder / Connection
  | confirm() ok      |                              |                   |                            |
  | POST _method=DELETE                              |                   |                            |
  |------------------>| bind {chirp}: SELECT ... limit 1 (missing -> 404)|                            |
  |                   |----------------------------->| Gate::authorize (403 if not owner)             |
  |                   |                              |------------------>| exists? no -> return null  |
  |                   |                              |                   | fire 'deleting' (false -> return false)
  |                   |                              |                   | touchOwners() ($touches = [] -> no-op)
  |                   |                              |                   | performDeleteOnModel ----->| delete from "chirps" where "id" = ?
  |                   |                              |                   |                            | rowCount() -> int (0 or 1)
  |                   |                              |                   |<-- int thrown away; exists = false
  |                   |                              |                   | fire 'deleted' -> return true
  |<----------------------------------------------- 302 /  + flash success 'Chirp deleted!'           |
```

## 1. `Model::delete()`

`Database/Eloquent/Model.php:1793-1824`:
```php
if (! $this->exists) {
    return;                                   // :1803 -> null, no events, no query
}
if ($this->fireModelEvent('deleting') === false) {
    return false;                             // :1806-1808 a listener can cancel
}
$this->touchOwners();                         // :1813
$this->performDeleteOnModel();                // :1815
$this->fireModelEvent('deleted', false);      // :1820 (halt=false, result ignored)
return true;                                  // :1822
```
- `deleting` fires with `$halt = true`, which uses `dispatcher->until()`, so a listener that returns `false` stops the delete (`Database/Eloquent/Concerns/HasEvents.php:214`: `$method = $halt ? 'until' : 'dispatch';`). `deleted` fires with `dispatch`.
- `touchOwners()` loops over `getTouchedRelations()`, which returns `$this->touches` (`Concerns/HasRelationships.php:1218-1220`). `Chirp` declares no `$touches`, so this does nothing.
- `performDeleteOnModel()` (`Model.php:1883-1888`):
  ```php
  $this->setKeysForSaveQuery($this->newModelQuery())->delete();
  $this->exists = false;
  ```
- `setKeysForSaveQuery()` adds `where id = <key>` (`Model.php:1590-1595`). The key comes from `getKeyForSaveQuery()`, which reads `$this->original[$this->getKeyName()] ?? $this->getKey()` (`Model.php:1602-1605`), so the *original* id is used even if `id` was changed in memory.
- **Calling it on an already-deleted model:** `exists` is now `false`, so a second `delete()` returns `null` with 0 queries and no events. A throwaway test showed `first => true, exists => false, second => NULL, queries => 0`. There is no exception (compare `deleteOrFail()`, which returns `false` when `! exists`, `Model.php:1846-1848`).

## 2. The SQL for an author's DELETE

The chain goes Eloquent `Builder::delete()` -> `$this->toBase()->delete()` (`Database/Eloquent/Builder.php:1563-1569`) -> Query `Builder::delete()` -> `$this->connection->delete($this->grammar->compileDelete($this), ...)` (`Database/Query/Builder.php:4576-4591`). SQLite passes no-join/no-limit deletes to the base grammar (`Query/Grammars/SQLiteGrammar.php:455-461`). Query log from a throwaway Pest test (`actingAs($author)->delete(route('chirps.destroy', $chirp))`):
```
select * from "chirps" where "id" = ? limit 1   [1]   -- implicit route binding
delete from "chirps" where "id" = ?             [1]   -- performDeleteOnModel
```
Result: 302 to `/` with `session('success') === 'Chirp deleted!'`. Authorization runs no queries (see the edit trace §6). The test was then deleted, and `git status` shows no leftover test files.

## 3. Race: two concurrent DELETEs

- **Second request arrives after the first commits:** binding runs `SELECT ... limit 1`, gets `null`, and returns **404**. The test confirmed `second_status => 404`. The mechanism is in edit-chirps.md §3.
- **Row disappears between binding and `delete()`:** `Connection::delete()` passes straight to `affectingStatement()` (`Database/Connection.php:583-586`), which returns PDO's row count:
  ```php
  $statement->execute();
  $this->recordsHaveBeenModified(
      ($count = $statement->rowCount()) > 0
  );
  return $count;                                  // :632-638
  ```
  `performDeleteOnModel()` **ignores that return value** (`Model.php:1885` does not assign it), sets `exists = false`, and `delete()` still returns `true` and fires `deleted`. To reproduce this, the throwaway test registered `Chirp::deleting(fn ($m) => DB::table('chirps')->where('id', $m->id)->delete())`. That external delete affected 1 row. Eloquent's own `delete from "chirps" where "id" = ?` then affected **0** rows (a direct `DB::table(...)->delete()` afterwards also returned `0`). The response was still 302 + 'Chirp deleted!'.
- The same thing happens with a stale in-memory instance whose row was deleted elsewhere. `exists` is still `true`, so `delete()` runs a 0-row DELETE and returns `true`.
- **Impact:** the end state (row gone) is correct, and both users see "Chirp deleted!". Nothing fails. If you need to know that *this* request deleted the row, use `Chirp::whereKey($id)->delete()` (returns int) or lock the row. `deleteOrFail()` only adds a transaction and does not check the count (`Model.php:1850`).

## 4. `onsubmit="return confirm(@js(__('Delete this chirp?')))"`

`resources/views/components/chirp.blade.php:17`. `@js` compiles in `View/Compilers/Concerns/CompilesJs.php:15-21`:
```php
"<?php echo \%s::from(%s)->toHtml() ?>"
```
which renders as `confirm(<?php echo \Illuminate\Support\Js::from(__('Delete this chirp?'))->toHtml() ?>)`. Note that `toHtml()` is echoed raw, without `e()`.

`Js::from()` -> `new static(...)` (`Support/Js.php:58-61`) -> `convertDataToJavaScriptExpression()`. For a string it JSON-encodes, strips the outer `"` and wraps the result in single quotes (`Js.php:90-93`). `encode()` always ORs in `REQUIRED_FLAGS` (`Js.php:121`):
```php
JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
| JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR          // Js.php:26-32
```
**Why a hostile translation can't break out:** a translator string like `It's <b>"x"</b> & y` becomes
`'It's <b>"x"<\/b> & y'` (checked in the throwaway test).
- `'` -> `'` (HEX_APOS), so it can't close the single-quoted JS literal.
- `"` -> `"` (HEX_QUOT), so it can't close the HTML `onsubmit="..."` attribute.
- `&` -> `&` (HEX_AMP), so it can't form an HTML entity like `&quot;` that the attribute parser would decode into a real quote *before* JS sees it.
- `<`/`>` -> `<`/`>` (HEX_TAG), so it can't inject `</script>` in script contexts.
- Backslashes are escaped by `json_encode` itself. Invalid UTF-8 is replaced instead of throwing (`JSON_INVALID_UTF8_SUBSTITUTE`, `Js.php:121`).

The browser's JS engine decodes the `\uXXXX` escapes back into the original text, so the dialog shows the string exactly as translated. Non-ASCII translations stay readable in the HTML source (`JSON_UNESCAPED_UNICODE`). If the user cancels, `confirm()` returns `false`, the form isn't submitted, and no request is sent.
