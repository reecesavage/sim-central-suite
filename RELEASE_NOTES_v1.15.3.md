# Sim Central Suite v1.15.3 &mdash; Post-write error reporting + reload hardening

Diagnostic and hardening release for the new post-authoring endpoints. `POST /posts` was returning a blank **500** on at least one sim: the post *was* created, but the response failed afterwards with no detail, making it impossible to see why from the client side.

## What's changed

- **Write endpoints now return errors as JSON.** The post create/update/delete dispatch is wrapped so any unexpected error comes back as a clean `500` with `detail` (the exception message) and `at` (file:line), instead of a blank fatal page. A privileged write client gets something it can act on.
- **The create path no longer fatals on reload.** After insert, if the new post's id can't be determined or the row can't be reloaded, the endpoint returns a descriptive `500` (including the id it saw) rather than crashing while projecting a missing row.

This is intentionally a small, safe change to surface the underlying cause of the create failure; the root-cause fix follows once the reported `detail` pinpoints it.

## Implementation notes

- `controllers/Api.php` &mdash; `try/catch (\Throwable)` around `_postsWrite()`'s verb dispatch; guards in `_postCreate()` for a non-positive `insert_id()` and a failed `get_post()` reload.

## Upgrade

Use the **Update Now** button on the dashboard. No database or config changes. (Reads, updates, and deletes were already working; this only affects how the write endpoints report failures.)

## Credits

Same as v1.15.2. MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
