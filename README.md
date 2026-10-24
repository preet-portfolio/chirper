# Chirper: the Laravel Bootcamp, built in depth

[![tests](https://github.com/preet-portfolio/chirper/actions/workflows/tests.yml/badge.svg)](https://github.com/preet-portfolio/chirper/actions/workflows/tests.yml)

A rebuild of the [Laravel Bootcamp](https://github.com/laravel/bootcamp) "Chirper" app. It has the same features as the reference, built the way a senior reviewer would want:

- **Spec first.** [`docs/spec.md`](docs/spec.md) lists the invariants (I1–I8) and an acceptance checklist taken from the reference app.
- **Test-first.** Each section began as failing Pest tests. Hand mutation testing (deliberately breaking the code) checks that the tests actually catch bugs.
- **Traced through the framework.** [`docs/traces/`](docs/traces/) follows each request through `vendor/laravel/framework` with file:line references and the real SQL.
- **Decisions recorded.** [`docs/adr/`](docs/adr/) explains where and why this build differs from the reference.

**Stack:** Laravel 13 · PHP 8.5 · Livewire starter kit (Fortify auth, Flux UI) · Pest · Larastan level 8 · Pint. CI runs on SQLite and Postgres.

## Run it

```bash
composer setup          # install, .env, key, migrate, build assets
php artisan db:seed     # verified demo user: test@example.com / password
php artisan serve       # http://127.0.0.1:8000
npm run dev             # in a second terminal: Vite, so Tailwind picks up new classes
```

`composer test` runs Pint, Larastan and Pest. Everything must pass before a commit.
