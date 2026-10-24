# Chirper: spec

Reference: [laravel/bootcamp](https://github.com/laravel/bootcamp) (2025 rewrite, checked 2026-10-24). That reference is a Blade app with hand-rolled auth, inline `user_id !== auth()->id()` checks and no tests. This build keeps the reference's chirp **behaviour** and changes the **implementation** where a senior reviewer would (see `docs/adr/`).

Since ADR 0008, `main` is an **anonymous public test board**: there are no accounts, ownership belongs to the posting browser, and chirps expire after 24 hours. The account-based version (Fortify, email verification, per-user policies, new-chirp emails) is kept at tag `v1-with-auth`. See the archived section below.

## Domain model

| Entity | Fields | Notes |
|---|---|---|
| Chirp | id, message (string ≤255), author_token_hash (char 64, indexed), created_at, updated_at | `message` is the only fillable field. `author_token_hash` is the SHA-256 of the posting browser's token, and it's `#[Hidden]`. Index on `created_at` for the timeline |
| Author token | 40 random chars in the `chirper_author` cookie (encrypted, http-only, 7 days) | Never stored. Only its hash is (`App\Support\AuthorToken`) |

There are no relations: a chirp belongs to a browser, not a user. The starter kit's `users` table and `User` model are still there (the seeder creates a test user), but there is no way to sign in, so the board never uses them.

## Invariants (each one gets a test)

1. **I1** Message is required, is a string, is ≤ 255 characters after trimming, and isn't only whitespace.
2. **I2** Ownership comes from the encrypted cookie, never from the request. `author_token_hash` isn't mass assignable, and the stored hash itself doesn't work as a token.
3. **I3** Only the posting browser can edit, update or delete a chirp. Anyone else gets **403**, before validation runs.
4. **I4** The timeline is public. It shows the 50 newest live chirps, newest first, as "Anonymous", using a **constant number of queries**.
5. **I5** No personal data: messages that look like an email address or contain a 9+ digit number are rejected on create and update. Every page shows the public-board disclaimer.
6. **I6** Chirp creation is rate-limited per IP: 5 per minute and 50 per day, then 429. Other IPs aren't affected. A filled honeypot saves nothing but looks successful.
7. **I7** Output is escaped: a message containing `<script>` renders as text.
8. **I8** "Edited" shows when `updated_at > created_at`.
9. **I9** Chirps expire after `chirper.lifetime_hours` (24). Expired chirps are hidden from the timeline, return 404 on edit, update and delete, and are pruned on the next post.

## Acceptance checklist

### Layout · `tests/Feature/Board/LayoutTest.php`
- [x] No account links or pages: `/login`, `/register`, `/logout`, `/dashboard`, `/settings/profile`, `/forgot-password` all 404
- [x] Every page (timeline and edit form) carries the public-board disclaimer (I5)

### Post chirps (`POST /chirps`) · `tests/Feature/Board/PostChirpTest.php`
- [x] Anyone posts without an account → redirected to `/` with flash "Your chirp has been posted!"
- [x] Posting sets a 40-char encrypted, http-only author cookie that lasts a week, and stores only its SHA-256. The same browser keeps its token across posts, and a malformed cookie is replaced (I2)
- [x] Validation messages: "Please write something to chirp!" / "Chirps must be 255 characters or less." (I1: missing, empty, whitespace-only, non-string, 255 vs 256)
- [x] Emails and phone/card-like numbers are rejected, ordinary numbers and `@` aren't (I5)
- [x] `author_token_hash` can't be supplied by the request and is never serialized (I2)
- [x] 5/min and 50/day per IP → 429, other IPs unaffected (I6); honeypot saves nothing (I6)
- [x] Posting prunes chirps older than the lifetime, and the lifetime is configurable (I9)

### Show chirps (`GET /`) · `tests/Feature/Board/TimelineTest.php`
- [x] Newest 50 live chirps as "Anonymous" with relative time (I4, I8); same-second ties by id desc (ADR 0004)
- [x] Constant query count, with and without an owner cookie (I4)
- [x] Expired chirps hidden before they're pruned (I9)
- [x] Messages are escaped (I7); empty state shown when there are no chirps

### Edit and delete chirps (`GET /chirps/{chirp}/edit`, `PUT` / `DELETE /chirps/{chirp}`) · `tests/Feature/Board/OwnershipTest.php`
- [x] The posting browser sees Edit and Delete (with a confirm prompt) on its own chirps only; a browser without the cookie sees neither
- [x] The author can edit (flash "Chirp updated!") and delete (flash "Chirp deleted!")
- [x] No cookie, someone else's token, an empty token, an unencrypted cookie, or the stored hash → 403 on form, update and delete (I2, I3)
- [x] Non-owners get 403 before validation; updates validate I1 and I5
- [x] Missing chirps → 404; expired chirps → 404 on edit (I9)

### Console & schedule (reference: `chirps:delete-all`, hourly) · `tests/Feature/Console/DeleteAllChirpsTest.php`, `DemoResetScheduleTest.php`
- [x] `php artisan chirps:delete-all` deletes every chirp and prints "Successfully deleted {n} chirps.". In production it refuses without `--force` (ADR 0006)
- [x] Scheduled hourly (demo reset) with `--force`, only when `config('chirper.demo_reset')` (`CHIRPER_DEMO_RESET`, default `false`) is true (ADR 0006)

### Deploy
- [x] Deployed with `composer deploy` (Vercel + Neon Postgres); proxy headers and the Neon connection URL are checked by `tests/Feature/DeploymentTest.php`
- [ ] CI green (Pint, Larastan L8, Pest + coverage, mutation gate, `composer audit`, SQLite + Postgres)

## Archived (v1-with-auth)

These items were ticked at tag `v1-with-auth` and don't apply to `main`. Read them with `git show v1-with-auth:docs/spec.md`.

- Register, log in, log out, password reset, email verification (switchable with `CHIRPER_REQUIRE_VERIFIED`), 2FA and passkeys through Fortify (ADR 0001, 0003)
- Old I2/I3/I5/I6: `user_id` from the session, owner-only edit and delete through `$chirp->user()->is($user)` (ADR 0005), cascading deletes from users, 10/min per-user rate limit
- Timeline with the author's name, eager-loaded with `Chirp::with('user')`
- Queued new-chirp emails to every other verified user, with a sent ledger for idempotency (ADR 0007, `docs/traces/notifications.md`)
