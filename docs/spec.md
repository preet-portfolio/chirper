# Chirper: spec

Reference: [laravel/bootcamp](https://github.com/laravel/bootcamp) (2025 rewrite, checked 2026-10-24). That reference is a Blade app with hand-rolled auth, inline `user_id !== auth()->id()` checks and no tests. This build keeps the reference's **behaviour** and changes the **implementation** where a senior reviewer would (see `docs/adr/`).

## Domain model

| Entity | Fields | Notes |
|---|---|---|
| User | id, name, email, password, email_verified_at, 2FA/passkey columns | From the Livewire starter kit (Fortify) |
| Chirp | id, user_id (FK → users, cascade on delete), message (string ≤255), created_at, updated_at | Index on `created_at` for the timeline |

User **hasMany** Chirp, and Chirp **belongsTo** User.

## Invariants (each one gets a test)

1. **I1** Message is required, is a string, is ≤ 255 characters after trimming, and isn't only whitespace.
2. **I2** Only an authenticated user can create a chirp, and `user_id` always comes from the session, never from the request (mass-assignment test).
3. **I3** Only the author can edit, update or delete their chirp. Anyone else gets **403**, and a guest is redirected to `/login`.
4. **I4** The timeline is public. It shows the 50 newest chirps, newest first, with the author's name, using a **constant number of queries** however many chirps or authors there are.
5. **I5** Deleting a user deletes their chirps (FK cascade).
6. **I6** Chirp creation is rate-limited per user (proposed: 10/min, then 429).
7. **I7** Output is escaped: a message containing `<script>` renders as text.
8. **I8** "Edited" shows when `updated_at > created_at`.

## Acceptance checklist (reference behaviour)

### Install & layout · `tests/Feature/Layout/NavigationLinksTest.php`
- [x] App boots, `/` renders the timeline for guests (reference: `GET /` → `ChirpController@index`; also `tests/Feature/Chirps/TimelineTest.php`)
- [x] Shared layout with nav: Log in/Register for guests and no Log out; for users, their name linking to `profile.edit`, no guest links, and a Log out form (POST + CSRF) that actually logs out

### Auth (reference: Register / Login / Logout controllers)
- [x] Register, log in and log out work (`tests/Feature/Auth/RegistrationTest.php`, `AuthenticationTest.php`, and the Log out form in `tests/Feature/Layout/NavigationLinksTest.php`), and so does email verification (`tests/Feature/Auth/EmailVerificationTest.php`; the starter kit adds this; reference has none). Verification is required to chirp: `tests/Feature/Chirps/CreateChirpTest.php` ("unverified user cannot post a chirp"), plus the edit/delete equivalents
- [x] Guest-only pages (`/login`, `/register`) redirect users who are already logged in to `/` (`tests/Feature/Layout/NavigationLinksTest.php`)

### Create chirps (reference: `POST /chirps`) · `tests/Feature/Chirps/CreateChirpTest.php`, `ChirpModelTest.php`
- [x] Logged-in, **verified** user posts a chirp → redirected to `/` with flash "Your chirp has been posted!" (ADR 0003)
- [x] Validation messages: "Please write something to chirp!" / "Chirps must be 255 characters or less." (I1, incl. whitespace-only, 255 vs 256, trimming)
- [x] Guest `POST /chirps` → redirect to login; unverified → `/verify-email`
- [x] `user_id` taken from session; not mass assignable (I2)
- [x] Rate limit 10/min per user → 429, other users unaffected (I6)
- [x] FK cascade on user delete (I5); `created_at` index for I4

### Show chirps (reference: `index`, `Chirp::with('user')->latest()->take(50)`) · `tests/Feature/Chirps/TimelineTest.php`
- [x] Timeline lists newest 50 with author name and relative time (I4, I8), same-second ties by id desc (ADR 0004)
- [x] Constant query count regardless of chirp/author count (I4)
- [x] Messages are escaped (I7)
- [x] Empty state shown when there are no chirps

### Edit chirps (reference: `GET /chirps/{chirp}/edit`, `PUT /chirps/{chirp}`) · `tests/Feature/Chirps/EditChirpTest.php`, `tests/Feature/Policies/ChirpPolicyTest.php`
- [x] Author sees an edit link on their chirps only; guests see none
- [x] Update validates (I1) → flash "Chirp updated!"; cannot reassign `user_id` (I2)
- [x] Non-author → 403 on form and update, **before** validation (I3, ADR 0005); guest → login; unverified → verify notice; missing chirp → 404

### Delete chirps (reference: `DELETE /chirps/{chirp}`) · `tests/Feature/Chirps/DeleteChirpTest.php`, `tests/Feature/Policies/ChirpPolicyTest.php`
- [x] Author sees delete on their chirps only, and gets a confirm prompt; guests see none
- [x] Delete → flash "Chirp deleted!"; only that chirp is removed
- [x] Non-author → 403 (I3); guest → login (no id probing); unverified → verify notice; missing → 404

### Console & schedule (reference: `chirps:delete-all`, hourly) · `tests/Feature/Console/DeleteAllChirpsTest.php`, `DemoResetScheduleTest.php`
- [x] `php artisan chirps:delete-all` deletes every chirp and prints "Successfully deleted {n} chirps." (also with zero chirps; users untouched). In production it refuses without `--force` (ADR 0006)
- [x] Scheduled hourly (demo reset) with `--force`, only when `config('chirper.demo_reset')` (`CHIRPER_DEMO_RESET`, default `false`) is true (ADR 0006)

### Depth extension (not in the 2025 reference; it was in the original bootcamp) · `tests/Feature/Notifications/`, ADR 0007, `docs/traces/notifications.md`
- [x] `ChirpCreated` dispatched by `store` (not by factories/seeders) → queued listener → queued `NewChirp` mail, one job per recipient, to every **other verified** user, in chunks; retried with backoff; failed path logged; idempotent per (chirp, recipient); deleted chirps send nothing; message escaped (HTML and Markdown)
- [x] Telescope locally to observe the request, queries, job and mail (never registered in testing or production)

### Deploy
- [ ] CI green (Pint, Larastan L8, Pest + coverage, `composer audit`, SQLite + Postgres)
- [ ] Deployed URL where a stranger can register and chirp
