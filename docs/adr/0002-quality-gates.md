# 0002: Quality gates

**Status:** accepted · 2026-10-24

**Context:** The reference ships no tests. We need evidence of correctness that doesn't depend on Claude or the author saying it works.

**Decision:**
- `composer test` = Pint check + Larastan **level 8** (raised from the kit's 7, memory limit 1G) + Pest. It must be green before every commit.
- `Model::shouldBeStrict()` outside production, so lazy loading, silently discarded fillable and missing attributes throw in dev and tests.
- Every invariant in `docs/spec.md` gets a test. Every policy ability gets a test with both owner and non-owner.
- CI (`.github/workflows/tests.yml`) runs `composer ci:check` on SQLite and Postgres, plus `composer audit --locked` and `npm audit`.
- **Architecture rules** (`tests/Arch.php`, run by `composer test:arch`, part of `composer test`): no `env()` outside `config/`; no `dd`/`dump`/`ray`/`var_dump`/`die`/`exit`; controllers extend `Controller` and don't use the `DB` facade; models extend Eloquent `Model`; FormRequests live in `App\Http\Requests` and extend `FormRequest` (and nothing else does); policies end in `Policy`. Pest's `php()`, `security()` and `laravel()` presets are on. Rules target namespaces, not classes, so new notifications, listeners or commands only have to sit in their conventional namespace. Each rule was checked by adding a deliberate violation and watching it fail.
  - `preset()->strict()` is **off**. It requires `declare(strict_types=1)` and `final` on every class. The starter kit's code (Fortify actions, providers, `User`) doesn't do that, and `final` models and controllers get in the way of Laravel's own extension points (mocking, proxies).
  - `tests/Arch.php` sits outside the `Unit`/`Feature` suites in `phpunit.xml`, so `php artisan test` doesn't run it. `composer test` runs it explicitly.
- **Coverage ≥ 90%** (`composer test:coverage`, Xdebug, on the CI SQLite leg). Currently **95.7%** lines. The only partly covered file is the starter kit's `FortifyServiceProvider`, at 80.8%.
- **Mutation score ≥ 94%** (`composer test:mutate`, its own CI job). It covers the code we wrote: `ChirpController`, `App\Http\Requests`, `App\Policies`, `Chirp`, `AppServiceProvider`. Currently **94.12%**: 34 mutants, 32 killed, 2 left, both accepted:
  - `AppServiceProvider` line 39, `RemoveStringCast` on `->by((string) $request->user()?->id)`. **Equivalent.** `ThrottleRequests` concatenates the key into `md5($name.$key)`, so `1` and `'1'` (or `null` and `''`) give the same bucket. Applied by hand, the full suite and Larastan both still pass.
  - `Chirp` line 19, `RemoveArrayItem` on `#[Fillable(['message'])]`. **Uncoverable, but killed by hand.** Attribute arguments aren't executable lines, so Xdebug maps no test to them and Pest reports the mutant as "uncovered". Applied by hand, it fails 7 chirp create/update tests (strict mode throws `MassAssignmentException`).
  - Pest's default mutators generate **no** mutants for `ChirpPolicy` (`->is()`) or `UpdateChirpRequest` (`Gate::allows`). Checked by hand: `is`→`isNot` fails 18 tests and `allows`→`denies` fails 8.
  - The gate runs serially, without `--parallel`. In parallel, surviving mutants hit the per-mutant timeout (suite time + 5 s) under load, and Pest counts a timeout as **killed**. That inflated the first measurement from 76% to 88%.

**Consequences:** Builds are slower and stricter. Strict mode can surface starter-kit lazy loading, which we fix where it happens rather than switch strict mode off. The mutation job takes about a minute. When new app code is added to the mutated set, raise or re-baseline `--min` deliberately rather than letting it drift down.

