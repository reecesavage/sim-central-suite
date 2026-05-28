# Sim Central Suite v1.9.1 &mdash; REST API

Read-only HTTP API for external integrations. Designed primarily for [n8n](https://n8n.io/) workflows but works with any HTTP client. Admin-issued bearer tokens, per-scope grants, per-token rate limiting. The API is **off by default** and stays invisible until enabled.

## What's new

### New feature: REST API

Eighth feature in the suite. Toggle on from *Sim Central Suite &rarr;* **REST API**, run **Setup database**, then open **Configure** to issue tokens.

Endpoints (all under `/extensions/nova_ext_sim_central/Api/`):

| Method | Path | Scope |
|---|---|---|
| `GET` | `/ping` | any valid token |
| `GET` | `/posts` *(filters: `?mission=`, `?status=`, `?page=`, `?per_page=`)* | `posts:read` |
| `GET` | `/posts/{id}` | `posts:read` |
| `GET` | `/characters` *(filters: `?status=`, `?page=`, `?per_page=`)* | `characters:read` |
| `GET` | `/characters/{id}` | `characters:read` |
| `GET` | `/missions` *(filters: `?status=`, `?page=`, `?per_page=`)* | `missions:read` |
| `GET` | `/missions/{id}` | `missions:read` |

List endpoints return a paginated envelope (`{data, page, per_page, total}`), `per_page` capped at 100. See [`REST_API.md`](REST_API.md) for the full reference.

### Token management UI

New page under **REST API &rarr; Configure** that handles the full token lifecycle:

- **Create** &mdash; pick a label, tick the scopes, optionally set an expiry. The raw token is shown **exactly once** with a copy-on-click input. Refresh the page and it's gone forever &mdash; only the SHA-256 hash + a short display prefix (`scapi_xxxx&hellip;`) remain in the database.
- **Revoke** &mdash; sets `revoked_at` (preserves audit trail). Subsequent requests return `401 Token has been revoked.` immediately.
- **Delete** &mdash; hard removal for cleanup. Confirm dialog because it's irreversible.

The page also displays per-token metadata: created/last-used/expires timestamps, scope list, and status badges (active / revoked / expired).

### Suite-feature-aware responses

When other suite features are enabled, the API surfaces what they add. Field *presence* signals feature availability &mdash; absent keys mean the feature is off on this sim, so consumers can detect capabilities without an extra config endpoint.

| Feature | Field added to&hellip; |
|---|---|
| Mission Post Summary | `summary` on posts; `summary_enabled` on missions |
| Ordered Mission Posts | `ordered` object on posts; `ordered` config on missions |
| Content Filter | `age_gated` boolean on posts (full `content` still returned) |
| Display Name | `display_name` + precomputed `preferred_name` on characters |

### Bug fix: Discord section on user account pages now reflects the viewed user

On the user account page (`/admin/user/account/{id}`), the **Linked Discord account** section used to always render the **logged-in user's** Discord identity and Link/Unlink/Change buttons &mdash; even when a sysadmin was viewing somebody else's account. So a sysadmin opening user 2's page would see *their own* Discord ID at the bottom, and clicking Unlink would unlink *their own* account, not user 2's.

The event listener now mirrors Nova's `User::account()` rule for picking the viewed user (`uri->segment(3)` when the viewer is level 2, otherwise the logged-in id):

- **Own account** &rarr; full interactive UI as before (Link / Unlink / Change Discord account, with required-link mode handling).
- **Someone else's account (sysadmin only)** &rarr; read-only. Either *"Discord: username (ID 123...)"* if linked, or *"No Discord account linked."* if not. No buttons &mdash; they'd act on the session's user, not the URL's user, which is the source of the original bug.

Direct intervention (force-unlink another user, point their link to a different Discord account) is intentionally not exposed in this UI; it's a manual SQL operation if you really need it. The expected flow is: the user fixes their own link from their own account page.

### Rate limiting

Per-token rolling 60-second window. Default 60 requests/minute, configurable via the `rest_api_rate_limit_per_minute` setting (set to `0` to disable). Exceeding the limit returns `429`.

The counter is a single-row DB update, no Redis or external dependency. The window resets lazily when the next request arrives more than 60s after the stored window start &mdash; no cron job needed.

## Why a separate Api controller?

The existing `Ajax.php` controller assumes a logged-in Nova session and calls `Auth::is_logged_in()` in its constructor. That's exactly wrong for the API, where:

- consumers (n8n, scripts) have no Nova session and never will,
- authentication is a bearer token, not a cookie,
- responses are JSON, not HTML fragments rendered through `Template::render()`.

Sharing a controller would mean a tangle of "if this is an API request do this else do that" conditionals. Cleaner to split: `Ajax.php` for admin-page widgets, new `Api.php` for token-authenticated external traffic. The two never share a request.

## Why direct-to-Nova, not via the broker?

The [Sim Central Broker](https://github.com/reecesavage/sim-central-broker) is a Discord-auth concern. Routing the API through it would mean either inventing fake Discord identities for API consumers or building a parallel auth path in the broker &mdash; both worse than just exposing the API on the sim under a separate auth scheme. Sites that want the broker as a caching/rate-limiting proxy in front of the API can still add that themselves; this release keeps the surface direct so users not running the broker (or not using Discord at all) get the same experience.

## Why admin-only token issuance?

Tokens grant programmatic access to (eventually) post content, character details, mission data. That's not a casual permission. Restricting issuance to sysadmins matches the rest of the suite's admin-gated configuration (every other feature also requires `site/settings`). It also keeps the v1 design simple &mdash; no per-user UI, no audit story for cross-user token visibility, no question of what happens to a user's tokens when they're disabled.

If you later want per-user tokens (e.g. to give individual writers scoped access to their own posts via an API), that's a future feature with a different scope model. v1 keeps the door closed.

## Why write endpoints are deferred

Write endpoints (`POST /posts`, `PATCH /characters`, etc.) need a designated "API author" setting &mdash; since tokens don't carry a user identity, the sim has to decide which Nova user/character gets attributed when content lands via the API. That's a real product decision (do you want a dedicated `api-bot` character? do you want the token's `created_by` user? do you want per-token author config?) and we'd rather ship reads now than block on it. Read-only v1 also gives a real-world feel for what the API surface needs before painting any corners.

## Implementation notes

- New library `libraries/ApiAuth.php` &mdash; `generateToken()` (raw + sha256 + display prefix), `validateBearer($header, $scope)` (returns `{status, code, message, token?}` covering 401/403/429/ok), and the rolling rate-limit counter. Loaded conditionally from `init.php` when the feature is on, matching the pattern used by every other feature.
- New controller `controllers/Api.php` &mdash; no `Auth::is_logged_in()` in the constructor. Hard 404 if the feature toggle is off (no surface leak on sims that haven't opted in). Single `_emit()` exit point ensures uniform JSON content-type + cache-control headers + exit behaviour.
- New table `sim_central_api_tokens` (becomes `<dbprefix>sim_central_api_tokens` on disk, typically `nova_sim_central_api_tokens` &mdash; the name deliberately avoids a leading `nova_` so CI3's auto-prefix on Query Builder doesn't skip it) &mdash; `id`, `label`, `token_hash` (UNIQUE), `token_prefix`, `scopes` (JSON), `created_by`, `created_at`, `last_used_at`, `expires_at`, `revoked_at`, `rate_count`, `rate_window_at`. Installed via the standard *Setup database* flow.
- New view `views/admin/pages/rest_api.php` &mdash; list, create form, one-time reveal, per-row revoke/delete buttons with confirm dialogs.
- `controllers/Manage.php` &mdash; new `rest_api()` route + private helpers (`_createApiToken`, `_revokeApiToken`, `_deleteApiToken`, `_apiAvailableScopes`). New entry in `_featureRegistry()` using the `requires_tables` pattern (same as URL Parser).
- `config.json` &mdash; new `rest_api` feature toggle (default off) and `rest_api_rate_limit_per_minute` setting (default 60).

## Upgrade

Use the **Update Now** button on the dashboard. After reload:

1. *Sim Central Suite &rarr; REST API &rarr;* **Enable** &rarr; **Setup database**.
2. Click **Configure** &rarr; fill in a label, tick the scopes you want, optionally set an expiry &rarr; **Create Token**.
3. Copy the displayed token. **This is the only time it will be shown.**
4. Test it: `curl -H "Authorization: Bearer scapi_..." https://yoursim.example/extensions/nova_ext_sim_central/Api/ping` &rarr; expect `{"ok":true,...}`.

If you don't want this feature, leave it off &mdash; every endpoint will return `404` and no API surface is exposed.

If anything breaks, the recovery path is:

- Disable the feature from the dashboard. All endpoints return to `404`.
- Or wipe all tokens at once: `UPDATE nova_sim_central_api_tokens SET revoked_at = NOW() WHERE revoked_at IS NULL;`

## Credits

Same as v1.8.0. MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
