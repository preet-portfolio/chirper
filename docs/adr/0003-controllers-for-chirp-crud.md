# 0003: Plain controllers + Blade for chirp CRUD; email verification required to chirp

> **Partly superseded for `main` by [0008](0008-anonymous-public-board.md); still describes tag `v1-with-auth`.** Controllers + Blade still hold; the `auth`/`verified` requirement and the per-user limit are gone (per-IP 5/min and 50/day instead).

**Status:** accepted · 2026-10-24 · amends 0001 ("Chirp CRUD uses Livewire components")

**Context:** Every Livewire action goes through a single `POST /livewire/update` endpoint. That hides the routing, implicit binding and middleware we want to trace and test. The reference app's routes (`POST /chirps`, `PUT/DELETE /chirps/{chirp}`) also double as our acceptance spec.

**Decision:**
- Chirp CRUD uses `ChirpController` with a FormRequest and Blade views styled with Flux. A public layout (`layouts/public`) serves the guest-visible timeline, and Livewire stays for the starter kit's auth and settings pages.
- Chirping requires `verified` as well as `auth`, which the reference doesn't. This is anti-spam for a public timeline, and the kit already ships verification.
- Rate limit: the named limiter `chirps` allows 10 per minute per user, enforced with `throttle:chirps`.

**Consequences:** Each chirp action is a real HTTP route we can trace and test with `$this->post(...)`. Posting needs a full page reload, so there's no live UX. If we want that later, a Livewire form can call the same model code. Unverified users get redirected to `/verify-email`.
