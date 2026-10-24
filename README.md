# Chirper: the Laravel Bootcamp, built in depth

[![tests](https://github.com/preet-portfolio/chirper/actions/workflows/tests.yml/badge.svg)](https://github.com/preet-portfolio/chirper/actions/workflows/tests.yml)

A rebuild of the [Laravel Bootcamp](https://github.com/laravel/bootcamp) "Chirper" app, running as an **anonymous public test board**. Anyone can post a short chirp without an account, and the browser that posted it can edit or delete it. It's built the way a senior reviewer would want:

- **Spec first.** [`docs/spec.md`](docs/spec.md) lists the invariants (I1–I9) and an acceptance checklist.
- **Test-first.** Each section began as failing Pest tests. Coverage and mutation-testing gates check that the tests actually catch bugs.
- **Traced through the framework.** [`docs/traces/`](docs/traces/) follows each request through `vendor/laravel/framework` with file:line references and the real SQL.
- **Decisions recorded.** [`docs/adr/`](docs/adr/) explains where and why this build differs from the reference. [ADR 0008](docs/adr/0008-anonymous-public-board.md) covers the switch to an anonymous board.

**Stack:** Laravel 13 · PHP 8.5 · Blade + Flux UI · Pest (with browser tests) · Larastan level 8 · Pint. CI runs on SQLite and Postgres.

> [!WARNING]
> **This is a public test board.** Anything you post can be read by anyone. **Don't post personal data**: no names, emails, phone numbers, addresses, or anything else that identifies you or someone else. Chirps are anonymous and are **deleted after 24 hours**.

## How it works

- **Anonymous posting.** There are no accounts, logins or settings pages. Every chirp shows as "Anonymous".
- **Browser-cookie ownership.** Your first post gives your browser a random 40-character token in an encrypted, http-only cookie (`chirper_author`, 7 days). The chirp stores only the token's SHA-256 hash. Edit and Delete appear only in the browser that holds the matching token, and `ChirpPolicy` checks the same thing on the server. If you clear your cookies or switch browsers, you can no longer edit your chirps.
- **Personal-data guard.** Messages that look like an email address or contain a 9+ digit number (phone or card numbers) are rejected, on both create and update. It's a best-effort check that backs up the disclaimer, so it can't catch everything.
- **Rate limits.** Each IP address can post 5 chirps a minute and 50 a day. After that it gets HTTP 429.
- **Honeypot.** A hidden `website` field catches bots: if it's filled in, the post looks successful but nothing is saved.
- **24-hour expiry.** Chirps older than 24 hours are hidden from the timeline and return 404 if you try to edit them. They're deleted the next time anyone posts, so no cron job is needed.

## Run it locally

```bash
composer setup          # install, .env, key, migrate (SQLite), build assets
php artisan serve       # http://127.0.0.1:8000
npm run dev             # in a second terminal: Vite, so Tailwind picks up new classes
```

Tests:

```bash
composer test           # Pint, Larastan, architecture rules, Pest. Must pass before every commit
composer test:coverage  # line coverage, at least 90% (needs Xdebug)
composer test:mutate    # mutation testing, score at least 96%
composer test:browser   # Pest browser tests in Chromium
```

## Deploy

```bash
composer deploy         # npm run build, then vercel deploy --prod --yes
```

The app runs on [Vercel](https://vercel.com) (the `vercel-php` runtime, see `vercel.json`) with a [Neon](https://neon.tech) Postgres database. Deploys only happen from the CLI, because Git auto-deploys are turned off.

The assets are built locally before uploading because Vercel's Git build image has no PHP, and the Flux CSS is imported from `vendor/livewire/flux`, which only exists after `composer install`. So `vercel.json` skips the install and build steps and serves the prebuilt `public/build`.

Run production migrations against Neon's direct (unpooled) connection:

```bash
vercel env run -e production -- sh -c 'DB_CONNECTION=pgsql DB_URL="$DATABASE_URL_UNPOOLED" php artisan migrate --force'
```

## Cost

Running this costs nothing:

- **Vercel Hobby** and **Neon Free**, neither of which needs a card.
- **GitHub Actions**, which is free for public repositories.
- No paid services: no mail provider, no queue worker and no scheduler. The queue runs `sync`, and mail goes to the log.

The app also keeps its own usage small. The per-IP limits (5/min, 50/day) and the honeypot cap writes. Messages are at most 255 characters, the timeline loads at most 50 chirps, and expired chirps are pruned on each post, so the database stays tiny.

## Want accounts? Build the authenticated version

If you want login, registration or a dashboard, start from the account version of Chirper. It's kept at the git tag **`v1-with-auth`**.

1. **Check it out.** Either look at it as is, or start your own branch from it:

   ```bash
   git checkout v1-with-auth             # detached, read-only look
   git switch -c my-app v1-with-auth     # or: your own branch to build on
   ```

2. **What it includes:**
   - Login, registration, password reset, two-factor authentication and passkeys through Fortify, from the Livewire starter kit, plus profile, appearance and security settings pages.
   - Email verification, required before chirping by default.
   - Per-user ownership: `ChirpPolicy` allows edit and delete only for the chirp's author (`$chirp->user()->is($user)`), and the timeline shows each author's name.
   - Queued new-chirp email notifications to every other verified user, with a sent ledger so retries don't send duplicates (see that version's ADR 0007).

3. **Set it up:**

   ```bash
   composer setup          # install, .env, key, migrate, build assets
   php artisan db:seed     # verified demo user: test@example.com / password
   php artisan serve       # http://127.0.0.1:8000
   npm run dev             # second terminal: Vite
   php artisan queue:work  # third terminal: sends the queued new-chirp emails (.env.example uses the database queue)
   ```

   Locally, `MAIL_MAILER=log` writes verification and notification emails to `storage/logs/laravel.log`.

4. **Mail in production.** Verification emails need a real mail provider (set `MAIL_MAILER` and its credentials). Without one, set `CHIRPER_REQUIRE_VERIFIED=false` so new users can chirp without verifying. The tag's `vercel.json` already does this, because that deployment has no mail provider.

5. **Add a dashboard.** The tag removed the starter kit's dashboard (`tests/Feature/NavigationTest.php` asserts `/dashboard` is a 404, so update that test first). To add one behind `auth`:

   ```php
   // routes/web.php (EnsureEmailIsVerifiedWhenRequired is already imported there)
   Route::view('dashboard', 'dashboard')
       ->middleware(['auth', EnsureEmailIsVerifiedWhenRequired::class])
       ->name('dashboard');
   ```

   ```blade
   {{-- resources/views/dashboard.blade.php --}}
   <x-layouts::app :title="__('Dashboard')">
       <flux:heading size="xl">{{ __('Your chirps') }}</flux:heading>
       @foreach (auth()->user()->chirps()->with('user')->latest()->get() as $chirp)
           <x-chirp :chirp="$chirp" />
       @endforeach
   </x-layouts::app>
   ```

   The `with('user')` matters: `x-chirp` prints the author's name, and strict mode throws on lazy loading outside production. Use plain `'auth'` instead if you don't want the verification check. To send users there after login, set `'home' => '/dashboard'` in `config/fortify.php` (it's `/` at the tag), and add a `flux:sidebar.item` for `route('dashboard')` in `resources/views/layouts/app/sidebar.blade.php`.

The docs for that version (ADRs 0001–0007, `docs/traces/`) describe it as it was at the tag.
