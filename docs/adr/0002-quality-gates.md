# 0002: Quality gates

**Status:** accepted · 2026-10-24

**Context:** The reference ships no tests. We need evidence of correctness that doesn't depend on Claude or the author saying it works.

**Decision:**
- `composer test` = Pint check + Larastan **level 8** (raised from the kit's 7, memory limit 1G) + Pest. It must be green before every commit.
- `Model::shouldBeStrict()` outside production, so lazy loading, silently discarded fillable and missing attributes throw in dev and tests.
- Every invariant in `docs/spec.md` gets a test. Every policy ability gets a test with both owner and non-owner.
- CI gets these later: Postgres in the matrix, `composer audit`, coverage ≥ 90%, Pest `arch()` rules, and a mutation score target (`pest --mutate`).

**Consequences:** Builds are slower and stricter. Strict mode can surface starter-kit lazy loading, which we fix where it happens rather than switch strict mode off.
