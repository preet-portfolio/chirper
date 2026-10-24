# 0007: New-chirp emails: controller event, per-recipient queued notifications, sent ledger

**Status:** accepted · 2026-10-24

**Context:** The original bootcamp emails every other user when someone chirps. It does this from a model
event, with one synchronous listener calling `User::all()`. That breaks in three ways. Seeding 50 chirps
sends 50 × N emails. Memory grows with the user count. And a single SMTP error part-way through fails the
whole job, so the retry emails everyone again. The job can also outlive its chirp.

**Decision:**

1. **Dispatch `ChirpCreated` from `ChirpController::store`, not `$dispatchesEvents`.** The event means "a
   user posted a chirp", which is a request-level fact. The model event `created` fires for factories,
   seeders, tinker and imports, so seeding would email real inboxes unless every seeder remembered
   `WithoutModelEvents`. The cost is that a future second write path (an API, a console command) must
   dispatch the event itself. The tests pin both sides: the HTTP post dispatches once, a rejected post
   and `Chirp::factory()` dispatch nothing. Moving the event to the model kills two tests.
2. **The fan-out is a queued listener, and delivery is one queued notification per recipient.**
   `SendNewChirpNotifications` (`ShouldQueue`, `tries = 3`, `backoff() = [10, 60, 300]`) selects
   `id != author AND email_verified_at IS NOT NULL` with `chunkById(100)`, which is keyset paging with
   bounded memory. Each chunk goes to `Notification::send`. Because `NewChirp` is `ShouldQueue`, Laravel
   pushes **one `SendQueuedNotifications` per user** (`docs/traces/notifications.md` §3), each with its own
   `tries = 3` and backoff. One bad mailbox retries alone. Both `failed()` hooks log with the chirp id.
   Unverified users are excluded because we must not email an address nobody has proven they own.
3. **Idempotency: a sent ledger checked in `shouldSend`, written in `afterSending`.** The table is
   `sent_chirp_notifications(chirp_id, user_id, sent_at)` with `UNIQUE(chirp_id, user_id)`, and both
   columns cascade on delete. `NewChirp::shouldSend()` skips a recipient who already has a row.
   `afterSending()` (called only after the mailer accepted the message) does `insertOrIgnore`. A re-run
   listener (a retry after a partial failure, or a duplicate job) therefore re-queues cheap no-op jobs
   for the users it already reached. A `WithoutOverlapping("new-chirp:{chirp}:{user}")->dontRelease()`
   job middleware closes the remaining race, where two copies for the same recipient run at once and
   both pass `shouldSend` before either writes. The second copy is **dropped**, not released. That's safe
   because the copy holding the lock keeps its own retries. A worker can also die mid-send (timeout, OOM,
   deploy), which orphans the lock. That's why the timings are ordered: `$timeout` (30s) < lock
   `expireAfter` (60s) < the queue's `retry_after` (90s). The orphaned lock expires before the job is
   redelivered, so the redelivery sends instead of being dropped. A test simulates the dead worker and
   pins the ordering. If `retry_after` is ever lowered below 60s, this protection breaks.
4. **Deleted chirps send nothing.** The listener and the notification both set `$deleteWhenMissingModels = true`.
   `SerializesModels` restores with `firstOrFail()`, and the resulting `ModelNotFoundException` deletes the job
   quietly instead of filling `failed_jobs` (trace §2). This applies whether the delete happens before the
   fan-out or between the fan-out and delivery.
5. **Mail escaping includes Markdown.** Blade's `{{ }}` encodes HTML, but notification mail is then
   rendered as Markdown, so `[Claim your prize](https://evil.test)` in a chirp would become a real link in
   an email we send. `Markdown::withSecuredEncoding()` (AppServiceProvider) escapes `\ [ ]` in echoed
   values app-wide.
6. **Telescope is local-only.** `AppServiceProvider::register` registers `Laravel\Telescope\TelescopeServiceProvider`
   and `App\Providers\TelescopeServiceProvider` only when `environment('local')` and the class exists, so a
   `--no-dev` production install is safe. It is deliberately absent from `bootstrap/providers.php`.
   `config/telescope.php` defaults `enabled` to `APP_ENV === 'local'`. Its migration loads from the package
   inside the local-only provider, so tests, CI and production never create `telescope_entries`. A
   subprocess test boots the app with `APP_ENV=production` and asserts the app provider is absent,
   Telescope is disabled and no `/telescope` routes exist.

**Alternatives rejected:**
- *`ShouldBeUnique` keyed on chirp+user.* `SendQueuedNotifications` doesn't implement it (the unique lock
  only applies to `ShouldBeUnique` commands), so this would mean a hand-written job per recipient. Worse,
  the unique lock is **released when the job finishes**, so a listener retry after the first jobs ran
  would happily send again. It deduplicates *in-flight* jobs, not *delivered* emails.
- *Claiming the ledger row before sending (at fan-out time, in a transaction with the `jobs` insert).*
  That gives exactly-once enqueueing, but only while the queue shares the DB connection. On Redis/SQS a crash
  between commit and push loses the email for good (at-most-once), and an SMTP failure after the claim
  needs compensating deletes.
- *`User::all()` / `Notification::send(User::all())`.* That loads every user into memory, and one job per
  fan-out means one SMTP failure retries everyone.

**Consequences / trade-offs:** Delivery is **at-least-once, with one narrow window**. If the worker dies
after SMTP accepts the message but before `afterSending` writes the row, the retry sends a second copy.
Closing that window would need the mail provider's idempotency key; plain SMTP has none. We prefer a rare
duplicate to a lost email. Each delivery costs one `exists` read and one insert (trace §6). The ledger
grows by one row per recipient per chirp, and the cascade cleans it up with the chirp. A prune is future
work if volume ever matters. Overlap protection needs a cache store with atomic locks (`database`,
`redis`, `array` all qualify). `CACHE_STORE=file` across several hosts would not. The fan-out's own
`backoff` is per listener attempt. Per-recipient jobs queued by an earlier attempt are not cancelled,
which the ledger makes harmless.

**Open item for the `composer.json` owner:** add `"laravel/telescope"` to `extra.laravel.dont-discover`.
Until then, package discovery registers Telescope's *package* provider wherever dev dependencies are
installed, including CI and testing. It stays inert there (it returns early because `enabled` is false
and registers no routes), but discovery should not be what decides this.

**Hand mutation (30 mutants, all killed):** author or unverified not excluded; a single unchunked query;
listener or notification `tries`, `backoff`, `deleteWhenMissingModels` or `failed()` logging; `shouldSend`
always true or ignoring the recipient; ledger never written; overlap middleware removed, releasing, or
keyed without the recipient; the overlap lock outliving `retry_after`; the notification `$timeout` removed; secured encoding off; message or greeting as raw `HtmlString`; subject
wording; wrong action URL; event not dispatched; event moved to `$dispatchesEvents`; Telescope registered
in every env, outside local, or enabled by default.
