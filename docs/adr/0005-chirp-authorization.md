# 0005: Chirp authorization through `ChirpPolicy`, checked before validation

**Status:** accepted · 2026-10-24

**Context:** The reference repeats `if ($chirp->user_id !== auth()->id()) abort(403)` in edit, update and destroy. That's three copies of a rule that can drift. It's also invisible to `@can` in views, and its strict `!==` depends on both sides having the same PHP type. A second problem: if a FormRequest validates before it authorizes, a non-author's invalid payload gets validation errors (and a hint about the rules) instead of a 403.

**Decision:**
- `ChirpPolicy::update` (and `delete`, next section) returns `$chirp->user()->is($user)`. It compares keys without running a query, and Laravel finds the policy by naming convention.
- `UpdateChirpRequest::authorize()` calls `Gate::allows('update', $chirp)`, which runs before `rules()`. `edit()` calls `Gate::authorize`. Views use `@can`.
- The tests cover the rule twice: policy unit tests (owner/other) and HTTP tests (403, a non-author with an invalid payload still gets 403, and no edit link for others).

**Consequences:** The rule lives in one place, and the UI hides exactly what the server forbids. Hand mutation showed every authorization mutant is caught by at least one test.
