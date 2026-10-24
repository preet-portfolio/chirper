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

### Install & layout
- [ ] App boots, `/` renders the timeline for guests (reference: `GET /` → `ChirpController@index`)
- [ ] Shared layout with nav: login/register for guests, logout for users

### Auth (reference: Register / Login / Logout controllers)
- [ ] Register, log in and log out work, and so does email verification (the starter kit adds this; reference has none)
- [ ] Guest-only pages redirect users who are already logged in

### Create chirps (reference: `POST /chirps`)
- [ ] Logged-in user posts a chirp → redirected to `/` with flash "Your chirp has been posted!"
- [ ] Validation messages: "Please write something to chirp!" / "Chirps must be 255 characters or less."
- [ ] Guest `POST /chirps` → redirect to login

### Show chirps (reference: `index`, `Chirp::with('user')->latest()->take(50)`)
- [ ] Timeline lists newest 50 with author name and relative time (I4, I8)
- [ ] Empty state shown when there are no chirps

### Edit chirps (reference: `GET /chirps/{chirp}/edit`, `PUT /chirps/{chirp}`)
- [ ] Author sees an edit link on their chirps only
- [ ] Update validates (I1) → flash "Chirp updated!"
- [ ] Non-author → 403 (I3)

### Delete chirps (reference: `DELETE /chirps/{chirp}`)
- [ ] Author sees delete on their chirps only, and gets a confirm prompt
- [ ] Delete → flash "Chirp deleted!"
- [ ] Non-author → 403 (I3)

### Console & schedule (reference: `chirps:delete-all`, hourly)
- [ ] `php artisan chirps:delete-all` deletes every chirp and prints the count
- [ ] Scheduled hourly (demo reset). This is a reference behaviour; keep it behind config so production can turn it off

### Depth extension (not in the 2025 reference; it was in the original bootcamp)
- [ ] `ChirpCreated` event → queued `NewChirp` notification (mail) to all other users; retried with backoff; failed-job test; idempotent
- [ ] Telescope locally to observe the request, queries, job and mail

### Deploy
- [ ] CI green (Pint, Larastan L8, Pest + coverage, `composer audit`, SQLite + Postgres)
- [ ] Deployed URL where a stranger can register and chirp
