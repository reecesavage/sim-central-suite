# Sim Central Suite v1.15.0 &mdash; REST API post authoring

The REST API can now **write mission posts**, not just read them &mdash; built so the mobile apps people have been making against the read API can also create, edit, and publish posts where Nova's web UI is awkward on a phone.

The blocker was identity: tokens have no Nova session, so "who is the author?" was undefined. v1.15.0 solves it by letting you **bind a token to a Nova user**; the token then acts as that user.

## What's new

### Token → user binding

On *REST API &rarr; Configure*, the create-token form now has an **Act as user** dropdown. The bound user is shown on each token row. Binding is **required** for the post authoring scopes (rejected at creation otherwise) and the token then writes as that user.

### `GET /me`

Returns the bound user, their characters (split into PC and NPC, rank-ordered), and the token's scopes &mdash; everything an app needs to drive a "posting as…" picker. Any valid user-bound token; `409` if the token has no user.

### Post authoring endpoints

| Verb | Path | Scope |
|---|---|---|
| `POST` | `/posts` | `posts:write` |
| `PATCH` / `PUT` | `/posts/{id}` | `posts:write` |
| `DELETE` | `/posts/{id}` | `posts:delete` |

- **Create** takes `title`, `authors` (character ids), `mission_id`, `body`, `status` (`saved` draft or `activated` publish), plus `location`, `timeline`, `tags`, the *Ordered Mission Posts* fields, and `age_gated`.
- **Update** is partial &mdash; only the fields you send change. `body_mode=append` appends to the existing body instead of replacing it. Flipping `status` to `activated` publishes a draft.
- **Delete** removes a post.

### New read tiers

- `posts:read` &mdash; public/activated posts (unchanged).
- `posts:read.own` &mdash; the bound user's posts **including drafts**.
- `posts:read.all` &mdash; any post including others' drafts *(sysadmin)*.

### Sysadmin bypasses

`posts:read.all` / `posts:write.all` / `posts:delete.all` let a token reach any post &mdash; but only when the token carries the `.all` scope **and** the bound user is a sysadmin. The scope is the security boundary; the sysadmin flag is the permission. `/me` still returns only the bound user's own characters.

## Design notes

- **Writes go through Nova's own model methods** (`Posts_model::create_mission_entry` / `update_post`), so the suite's webhook shims fire (`post.saved` / `post.posted`) and Nova's `db.*.prepare.posts` listeners populate the *Ordered Mission Posts* and *Content Filter* columns automatically &mdash; no per-feature reimplementation. The API just seeds the request inputs those listeners read.
- **Authorization.** Create/update require at least one author to be one of the bound user's characters (unless `posts:write.all` + sysadmin); update/delete require the user to already be on the post. `/me` and ownership are checked against `post_authors_users`.
- **Saving character / actor.** `post_saved` (and the webhook `actor`) is the user's main character if it's on the post, otherwise their highest-ranked character on it (`ranks.rank_order`, tiebreak main char then lowest charid). Only the user's own characters are eligible, so unlinked NPCs are never chosen.
- **Activation is faithful to the website.** Publishing via the API stamps `last_post` on the authoring characters + their users, honours per-user moderation (a moderated author's post lands as `pending`), and sends the sim's crew email by reusing Nova's own `Mail`/`Parser` + the `write_missionpost` template + `get_crew_emails('email_mission_posts')`. The email is best-effort &mdash; a mail failure never blocks the write.

## Implementation notes

- `controllers/Api.php` &mdash; `GET /me`; `POST`/`PATCH`/`PUT`/`DELETE /posts`; read-tier resolution on `GET /posts`; identity/scope/ownership helpers.
- `libraries/PostWrite.php` (new) &mdash; actor resolution, author→user CSV, moderation, request-input seeding, and activation side-effects (last_post + crew email). Loaded from `init.php` when the REST API feature is on.
- `controllers/Manage.php` &mdash; `user_id` column (CREATE DDL + `requires_db`); new scopes; the token-create user dropdown + validation.
- `views/admin/pages/rest_api.php` &mdash; "Act as user" dropdown and a User column on the token list.
- `libraries/ApiEndpoints.php` &mdash; `/me` and the post-write endpoints added to the catalog + OpenAPI (`Me`, `PostDeleteResult` schemas).

## Upgrade

Use the **Update Now** button on the dashboard. Then, on the *REST API* feature:

1. **Setup database** &mdash; adds the new `user_id` column to `sim_central_api_tokens`. (Without it, user binding has nowhere to save.)
2. *Configure* &mdash; create a token, pick **Act as user**, and tick the post scopes you want.

Existing tokens and read integrations keep working unchanged; the new scopes are opt-in. If you want post webhooks to fire on API-authored posts, the Event Webhooks shim must be installed as usual (unchanged from before).

## Credits

Same as v1.14.4. MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
