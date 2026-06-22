# Sim Central Suite v1.19.0 — REST API: post locking

Adds post-lock support to the REST API, matching the locking behaviour introduced for
the mobile site in v1.18.0. PATCH now respects Nova's `post_lock_user` /
`post_lock_date` columns and four new endpoints give API clients explicit control over
the lock lifecycle.

## New endpoints

All lock endpoints require the `posts:write` scope and a bound user.

### `GET /posts/{id}/lock`
Returns the current lock state. Safe to call at any time — use this to check before
trying to acquire.

**Not locked:**
```json
{ "locked": false, "post_id": 8 }
```

**Locked by someone else:**
```json
{
  "locked": true,
  "post_id": 8,
  "lock": {
    "user_id": 3,
    "owner": "Lt. John Smith",
    "age_minutes": 2,
    "expires_in_minutes": 8
  }
}
```
`yours: true` is added when the caller holds the lock.

### `POST /posts/{id}/lock`
Acquire the lock. Returns `acquired: true` and `expires_in_minutes: 10` on success.

If another user holds a fresh lock, returns **409** with the owner, age, and time to
expiry so the caller can surface a useful message or retry after the lock expires.

Re-acquiring your own lock (or a stale, unclaimed one) simply refreshes the expiry
and returns 200 — the call is idempotent for the lock owner.

### `PUT /posts/{id}/lock`
Heartbeat: resets the 10-minute expiry timer. Call every ~5 minutes during a long
edit session to keep the lock alive. Returns **409** if you do not hold the lock.

### `DELETE /posts/{id}/lock`
Release the lock. Tokens with `posts:write.all` (sysadmin) may force-release any
lock; regular tokens may only release their own. Releasing an already-free lock is a
no-op (returns 200).

## PATCH changes

`PATCH /posts/{id}` now enforces the lock:
- If another user holds a fresh lock → **423 Locked**, with owner and expiry in the
  response body so the client can inform the user.
- If you hold the lock → proceed normally; the lock is auto-released after a
  successful save (no separate `DELETE /lock` call needed).
- If the post is unlocked → proceed normally (locking is not required, just respected
  when present).

## Recommended client flow

```
POST /posts/{id}/lock          ← acquire (409 = someone else is editing)
...user edits locally...
PUT  /posts/{id}/lock          ← heartbeat every ~5 min for long sessions
PATCH /posts/{id}              ← save; lock auto-released on success
```

Cancel flow (discard edits):
```
DELETE /posts/{id}/lock        ← release without saving
```

## Implementation notes

- `controllers/Api.php` — `posts()` branches on `segment(6) === 'lock'` to dispatch
  to new `_postsLock($method)`; `_postUpdate()` gains a 423 guard and auto-releases
  the caller's lock after a successful save.
- `libraries/PostWrite.php` — new `lockProjection($post)` static helper builds the
  serialisable lock-state structure; `lockOwnerName()` promoted to public.

## Upgrade

Use the **Update Now** button on the dashboard. No database or config changes.

## Credits

Same as v1.18.2. MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
