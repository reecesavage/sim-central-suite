# Sim Central Suite v1.15.4 &mdash; Fix POST /posts create (insert_id)

Fixes the `500` on `POST /posts` (create). Creating a post over the API actually wrote the post to the database, but the response then crashed and returned a blank error.

## Root cause

After inserting the post, the endpoint read `$this->db->insert_id()` to fetch the new row and return it. On a sim with **Ordered Mission Posts** enabled, that feature's `db.insert.prepare.posts` listener runs a nested mission lookup *during* the insert, which leaves the database driver's last-insert-id unreliable. So `insert_id()` came back wrong, `get_post()` on it returned `false`, and projecting that `false` row fatally errored:

```
Attempt to read property "post_id" on bool ... Api.php
property_exists(): Argument #1 must be of type object|string, bool given
```

Reads, updates (`PATCH`), and deletes were never affected &mdash; they don't depend on `insert_id()` (update/delete use the id from the URL).

## What's fixed

`POST /posts` no longer trusts `insert_id()` blindly. If it doesn't resolve to a real row, the endpoint recovers the just-written post by matching it (title + saving character + exact `post_date`) and returns it. Combined with the v1.15.3 error-reporting wrapper, a create now returns the created post (`201`) as intended.

## Implementation notes

- `controllers/Api.php` &mdash; `_postCreate()` resolves the new post id via `insert_id()` with a fallback lookup, instead of fataling when `insert_id()` is clobbered by a `db.prepare` listener's nested query.

## Known related issue (separate)

The Event Webhooks model shim uses the same `insert_id()` immediately after a post insert to fire `post.saved` on **brand-new** posts, so on Ordered-Mission-Posts sims that specific webhook can target the wrong id. Updates/activations are unaffected. A shim fix will follow in a later release; it doesn't affect the REST API.

## Upgrade

Use the **Update Now** button on the dashboard. No database or config changes.

## Credits

Same as v1.15.3. MIT licensed. Thanks to the admin who captured the Nova error log that pinpointed this.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
