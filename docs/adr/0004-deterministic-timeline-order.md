# 0004: Deterministic timeline order (`created_at desc, id desc`)

**Status:** accepted · 2026-10-24

**Context:** The reference uses `latest()->take(50)`. Chirps posted in the same second tie, and SQL leaves the order of tied rows undefined. Hand mutation testing showed that dropping the tie-break changes nothing on SQLite: `EXPLAIN QUERY PLAN` shows a backwards scan of `chirps_created_at_index`, and because each index entry carries the rowid, tied rows already come back id-descending. Postgres and MySQL don't guarantee this.

**Decision:** Order by `created_at desc, id desc` (`->latest()->latest('id')`). The tie-break test stays. On SQLite it's an *equivalent mutant*; the Postgres CI job (ADR 0002) is where it can actually fail.

**Consequences:** Pagination and "load more" later get a stable cursor `(created_at, id)`. If the timeline query shows up as slow on Postgres, switch the index to a composite `(created_at, id)`.
