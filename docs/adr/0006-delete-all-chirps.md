# 0006: `chirps:delete-all` uses a query delete, is opt-in, and guards production

**Status:** accepted · 2026-10-24

**Context:** The reference resets its demo with an hourly `chirps:delete-all`. `Chirp::truncate()` is the obvious one-liner, but it's DDL with per-driver behaviour: Postgres rejects `TRUNCATE` on a table referenced by a foreign key unless it `CASCADE`s (which would silently follow FKs to other tables), MySQL refuses outright, and none of them report how many rows went. It also bypasses Eloquent's query builder, so a global scope added later (e.g. `SoftDeletes`) would be ignored. And a scheduled wipe of user content is dangerous on a real deployment.

**Decision:**
- `Chirp::query()->delete()`: plain `DELETE FROM chirps` through Eloquent. FK constraints are enforced row by row on every driver, global scopes apply, and the affected-row count feeds "Successfully deleted {n} chirps." in one statement. It doesn't fire per-model `deleting`/`deleted` events; there are no chirp observers today, and if one is added this must become a chunked `each->delete()`.
- `ConfirmableTrait`: in production the command refuses unless `--force` is passed. Non-interactive runs (cron) take the default "no".
- The hourly schedule exists only when `config('chirper.demo_reset')` (`CHIRPER_DEMO_RESET`, default `false`) is true, and then it passes `--force`: turning the flag on is the consent.

**Consequences:** Same result as the reference on SQLite and Postgres, with no way to wipe production by accident. A huge table would delete in one transaction; fine at demo scale. Tests: `tests/Feature/Console/`.
