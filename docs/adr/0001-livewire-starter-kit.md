# 0001: Livewire starter kit on Laravel 13 instead of hand-rolled Blade auth

> **Superseded for `main` by [0008](0008-anonymous-public-board.md); still describes tag `v1-with-auth`.** `main` has no Fortify auth and none of the starter kit's auth or settings pages; Flux UI stays.

**Status:** accepted · 2026-10-24 · CRUD part amended by 0003

**Context:** The 2025 bootcamp reference writes its own Register/Login/Logout controllers so beginners can see them. We want production-grade auth (verification, rate-limited login, password reset, 2FA, passkeys) and want all logic to stay in PHP so framework tracing remains readable.

**Decision:** Use `laravel new --livewire --pest --database=sqlite` (Laravel 13.38, Livewire 4, Flux, Fortify). Chirp CRUD uses Livewire components. Authorization goes through a `ChirpPolicy`, not inline `user_id` checks.

**Consequences:** We lose the reference's "write auth yourself" exercise; we make up for it by running `/trace POST /login` through Fortify. Auth behaviour is the starter kit's, not the reference's (e.g. email verification is required before `/dashboard`). The React/Vue kits are ruled out, because they add a JS layer we'd also have to test and trace.
