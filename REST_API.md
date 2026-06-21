# Sim Central Suite — REST API Reference

The suite's REST API exposes HTTP endpoints for external integrations: n8n workflows, scripts, dashboards, anything that needs to read mission posts, characters, or missions — or to manage user activation status and event webhooks — without scraping HTML.

> **Status:** read endpoints for posts/characters/missions, plus write endpoints for user activation status (disable/reactivate) and event-webhook management (create/update/delete). Write endpoints are gated behind their own `*:write` scopes — issue those only to trusted automation.

---

## Setup

1. *Sim Central Suite &rarr; REST API &rarr;* **Enable**.
2. Click **Setup database**. This creates `<prefix>sim_central_api_tokens` (typically `nova_sim_central_api_tokens`).
3. Click **Configure** &rarr; fill in a label, tick the scopes you want, optionally set an expiry &rarr; **Create Token**.
4. **Copy the token immediately.** It is shown exactly once. Only its hash is stored.

Tokens are managed entirely in the ACP. There is no self-service issuance &mdash; only sysadmins with access to `site/settings` can create or revoke them.

## Interactive explorer + OpenAPI spec

Once the feature is enabled, two helper surfaces appear:

- **API Explorer** *(admin only)* &mdash; *REST API &rarr; API Explorer*. Lists every endpoint with parameters, response shapes, and a **Try it** button per endpoint that fires a live request and renders the JSON response inline. Also has **Copy curl** buttons. Use this for hands-on debugging before / instead of writing an external script.
- **OpenAPI 3.0 spec** *(public when feature on, 404 when off)* &mdash; `GET /extensions/nova_ext_sim_central/Api/openapi`. Machine-readable description of every endpoint, every parameter, every response schema. Importable into Postman, Insomnia, Stoplight, n8n's OpenAPI nodes, etc. No authentication required &mdash; the spec is a public document by convention.

The explorer page and the OpenAPI spec read from the same in-code endpoint catalog, so they always stay in sync with what the API actually does.

---

## Authentication

Every request must send the token in the `X-API-Key` header:

```
X-API-Key: scapi_<40 hex chars>
```

Tokens look like `scapi_a1b2c3d4e5f6...` (a `scapi_` prefix plus 40 random hex characters &mdash; 160 bits of entropy).

The server hashes the supplied token with SHA-256 and looks it up. The raw value is never stored, never logged, and cannot be recovered if lost &mdash; revoke and re-issue if you lose one.

> **Why X-API-Key and not `Authorization: Bearer`?** Apache strips the `Authorization` header before PHP can read it on most shared hosts &mdash; it treats `Authorization` as server-owned. Supporting only `X-API-Key` (which Apache passes through untouched, no config required) means the API works the same way on every install. No `.htaccess` edits, no environment quirks.

---

## Scopes

Tokens carry an explicit scope list. Endpoints check for the scope they require and return `403` if missing.

| Scope | Grants |
|---|---|
| `posts:read` | List/view **public (activated)** posts |
| `posts:read.own` | List/view the **bound user's** posts, including drafts |
| `posts:write` | Create/update the bound user's posts (save or activate) |
| `posts:delete` | Delete the bound user's posts |
| `posts:read.all` | Read **any** post incl. others' drafts *(sysadmin only)* |
| `posts:write.all` | Create/update **any** post, add a character to it *(sysadmin only)* |
| `posts:delete.all` | Delete **any** post *(sysadmin only)* |
| `characters:read` | List characters, view a single character |
| `missions:read` | List missions, view a single mission |
| `users:write` | Disable / reactivate users and their linked characters |
| `webhooks:read` | List event webhooks and view their config |
| `webhooks:write` | Create, update, and delete event webhooks |

A token with no relevant scope but a valid signature will still get `200` on `/ping`, since that endpoint is scope-free by design (it's the n8n "does this token work?" check).

The `*:write` / `*:delete` scopes mutate sim state — grant them sparingly. Webhook config includes destination URLs (returned in full by the webhook endpoints), so a `webhooks:read` token is sensitive too.

### Token → user binding

The `posts:read.own` / `posts:write` / `posts:delete` scopes act **as a specific Nova user**. Bind the user when you create the token (ACP → *REST API → Configure* → "Act as user"). These scopes are rejected at creation time if no user is selected, and return `409` at request time if the binding is missing. `GET /me` reports who a token is bound to.

The `*.all` scopes are a **sysadmin bypass**: they widen what a token can see/target, but only when the token carries the `.all` scope **and** the bound user is a sysadmin. The scope is the security boundary; the sysadmin flag is the permission. `/me` still returns only the bound user's own characters.

---

## Rate limiting

Per-token, rolling 60-second window. Default 60 requests/minute. Configurable via the suite's `rest_api_rate_limit_per_minute` setting (set to `0` to disable).

Hitting the limit returns:

```http
HTTP/1.1 429 Too Many Requests
Content-Type: application/json

{"error": "Rate limit exceeded (60/min). Try again shortly."}
```

---

## Response shape

All endpoints return JSON.

**Single resource** &rarr; the resource object directly:
```json
{ "id": 42, "title": "...", ... }
```

**List** &rarr; a paginated envelope:
```json
{
  "data":     [ { ... }, { ... } ],
  "page":     1,
  "per_page": 25,
  "total":    137
}
```

**Errors** &rarr; `{ "error": "human-readable message" }` with the appropriate HTTP status.

| Status | When |
|---|---|
| `200` | OK |
| `201` | Created (webhook create) |
| `401` | Missing / malformed / unknown / revoked / expired token |
| `403` | Token is valid but lacks the required scope |
| `404` | Resource not found, *or* the REST API feature is disabled on this sim |
| `405` | Wrong HTTP method for the endpoint |
| `409` | A required suite feature is off (Event Webhooks, or Discord Auth for `discord_id` lookups) |
| `422` | Validation failed on a write body (response includes a `details` array) |
| `429` | Rate limit exceeded |
| `500` | Bug. File an issue. |

Write endpoints accept their body as JSON (`Content-Type: application/json`), form-encoded fields, or query-string params.

---

## Base URL

```
https://<your-sim>/extensions/nova_ext_sim_central/Api
```

All endpoints below are relative to this base.

---

## Endpoints

### `GET /ping`

Sanity check. Any valid token, no scope required. Use this from n8n's "Test step" button to verify your credential is wired up before building the rest of a flow.

**Response:**
```json
{
  "ok": true,
  "token_label": "n8n - feed sync",
  "now": "2026-05-27T14:00:00+00:00"
}
```

---

### `GET /me`

Identity of the user this token is bound to. No specific scope (any valid token), but the token **must** be user-bound (`409` otherwise). Use it to populate a "posting as…" picker.

**Response:**
```json
{
  "user": { "id": 42, "name": "Reece", "is_sysadmin": false },
  "characters": {
    "pc":  [ { "id": 17, "name": "Tamblem Dravor", "rank": "Lieutenant JG", "rank_order": 22, "crew_type": "active", "is_main": true } ],
    "npc": [ { "id": 88, "name": "Ensign Vex", "rank": "Ensign", "rank_order": 24, "crew_type": "npc", "is_main": false } ]
  },
  "scopes": ["posts:read.own", "posts:write"]
}
```

---

### `GET /posts`

List mission posts, most recent first. Scope: `posts:read`.

**Query params:**

| Param | Default | Notes |
|---|---|---|
| `mission` | *(none)* | Filter to a single mission id |
| `status` | `activated` | Post status. `any` returns drafts/saved/activated. Other values: `saved`, `draft` |
| `page` | `1` | 1-indexed |
| `per_page` | `25` | Capped at `100` |

**Response (envelope):** see [Response shape](#response-shape).

**Post object:**

| Field | Type | Notes |
|---|---|---|
| `id` | int | `posts.post_id` |
| `title` | string | |
| `content` | string | Raw post body. May contain BBCode / HTML depending on your Nova install. |
| `mission_id` | int \| null | |
| `authors` | string \| null | Comma-separated `charid` list (Nova's native format). |
| `status` | string | `activated` / `saved` / `draft` |
| `date` | ISO 8601 | UTC |
| `summary` | string \| null | **Only present when the *Mission Post Summary* feature is enabled.** |
| `ordered` | object | **Only present when *Ordered Mission Posts* is enabled.** Keys: `day` (int), `time` (string `"HHMM"`), `date` (string), `stardate` (string). Only populated keys are included. |
| `age_gated` | bool | **Only present when *Content Filter* is enabled.** The API still returns full `content`; this flag lets your consumer decide whether to redact downstream. |

### `GET /posts/{id}`

Single post by id. Scope: `posts:read` (public, activated only). A user-bound `posts:read.own` token may also fetch its own drafts; `posts:read.all` (sysadmin) may fetch any post. Returns `404` when the tier doesn't permit the post.

---

### `POST /posts`

Create a mission post. Scope: `posts:write` (user-bound). Body as JSON, form-encoded, or query.

| Field | Required | Notes |
|---|---|---|
| `title` | ✓ | Post title |
| `authors` | ✓ | Character ids (array or CSV). ≥1 must be one of the bound user's characters (unless `posts:write.all` + sysadmin) |
| `mission_id` | ✓ | Must reference an existing mission |
| `body` | | Post content |
| `status` | | `saved` (default, draft) or `activated` (publish) |
| `location` | | In-character location |
| `timeline` | | Free-text timeline (when *Ordered Mission Posts* is **off**) |
| `tags` | | Array or CSV |
| `ordered_day` / `ordered_time` / `ordered_date` / `ordered_stardate` | | *Ordered Mission Posts* fields (when **on**); times accept `HH:MM` or `HHMM` |
| `age_gated` | | *Content Filter*: gate this post behind the age notice |

The **saving character** (`post_saved`, and the webhook `actor`) is derived: the bound user's main character if it's on the post, else their highest-ranked character on it. Activating (`status=activated`) fires the `post.posted` webhook, stamps `last_post`, sends the sim's crew email, and honours per-user moderation — a post by a moderated author lands as `pending`.

```bash
curl -X POST "$BASE/posts" \
  -H "X-API-Key: $TOKEN" -H "Content-Type: application/json" \
  -d '{"title":"First Steps","authors":[17],"mission_id":16,"body":"...","status":"saved"}'
```

Returns `201` with the created post object. `422` on validation failure (missing title/authors/mission, unknown character id, bad status); `403` if no author belongs to you; `409` if the token isn't user-bound.

### `PATCH /posts/{id}` (alias `PUT`)

Update a post you author (or any post with `posts:write.all` + sysadmin). Scope: `posts:write`. **Partial** — only the fields you send change.

- Same field set as create (all optional).
- `body_mode`: `replace` (default) or **`append`** (appends to the existing body).
- Changing `status` to `activated` on a draft publishes it (same activation side-effects as create).

```bash
curl -X PATCH "$BASE/posts/642" \
  -H "X-API-Key: $TOKEN" -H "Content-Type: application/json" \
  -d '{"body":"\n\nMore to add.","body_mode":"append"}'
```

### `DELETE /posts/{id}`

Permanently delete a post you author (or any with `posts:delete.all` + sysadmin). Scope: `posts:delete`. Returns `{ "deleted": true, "id": 642 }`.

---

### `GET /characters`

List characters. Scope: `characters:read`.

**Query params:**

| Param | Default | Notes |
|---|---|---|
| `status` | `active` | `crew_type`. `any` returns every character (active, inactive, pending, etc.) |
| `page` | `1` | |
| `per_page` | `25` | Capped at `100` |

**Character object:**

| Field | Type | Notes |
|---|---|---|
| `id` | int | `characters.charid` |
| `first_name` | string \| null | |
| `last_name` | string \| null | |
| `suffix` | string \| null | |
| `status` | string | `crew_type`: `active`, `inactive`, `pending`, etc. |
| `rank` | int \| null | `rank_id` &mdash; look up name separately if needed |
| `user_id` | int \| null | The Nova user that owns this character |
| `display_name` | string \| null | **Only present when *Display Name* is enabled.** Raw value of the override column. |
| `preferred_name` | string | **Only present when *Display Name* is enabled.** Precomputed: `display_name` if set, otherwise `first last suffix` joined. Use this if you just want "what to call this character." |

### `GET /characters/{id}`

Single character by id. Scope: `characters:read`. Returns any status (unlike posts, where the single endpoint hides non-activated rows).

---

### `GET /missions`

List missions, most recent start first. Scope: `missions:read`.

**Query params:**

| Param | Default | Notes |
|---|---|---|
| `status` | *(any)* | `mission_status`: `current`, `upcoming`, `completed` |
| `page` | `1` | |
| `per_page` | `25` | Capped at `100` |

**Mission object:**

| Field | Type | Notes |
|---|---|---|
| `id` | int | `missions.mission_id` |
| `title` | string \| null | |
| `description` | string \| null | |
| `status` | string | `current` / `upcoming` / `completed` |
| `start` | ISO 8601 \| null | |
| `end` | ISO 8601 \| null | |
| `summary_enabled` | bool | **Only present when *Mission Post Summary* is enabled.** Whether writers see the summary field on this mission's posts. |
| `ordered` | object | **Only present when *Ordered Mission Posts* is enabled.** Keys: `config`, `numbering` (bool), `default_date`, `default_stardate`, `legacy_mode` (bool). |

### `GET /missions/{id}`

Single mission by id. Scope: `missions:read`.

---

## User activation status (write)

Two endpoints flip a user's activation status and cascade to their linked characters. Scope: `users:write`.

Identify the user either way:

- **`user_id`** — the Nova user id. Always available.
- **`discord_id`** — the user's linked Discord account id. Only works when the **Discord Auth** feature is enabled (that's the feature that stores the link). If it's off, the endpoint returns `409` telling you to use `user_id`.

If both are supplied, `user_id` wins (so a request never fails on Discord Auth being off when you also gave a user id).

### `POST /users/disable`

Sets the user to `status = inactive` and every **currently-active** linked character to `crew_type = inactive`.

**Body:** `user_id` *or* `discord_id`.

```bash
curl -X POST "$BASE/users/disable" \
  -H "X-API-Key: $TOKEN" -H "Content-Type: application/json" \
  -d '{"discord_id": "123456789012345678"}'
```

**Response (`UserStatusResult`):**
```json
{
  "user_id": 42,
  "discord_id": "123456789012345678",
  "status": "inactive",
  "characters": { "status": "inactive", "affected": 2, "ids": [10, 11] }
}
```

### `POST /users/reactivate`

Sets the user to `status = active`. By default every **previously-inactive** linked character is set back to `crew_type = active`.

**Body:** `user_id` *or* `discord_id`; optional `reactivate_characters` (default `true`). Pass `false` to reactivate only the user and leave characters inactive.

```bash
curl -X POST "$BASE/users/reactivate" \
  -H "X-API-Key: $TOKEN" -H "Content-Type: application/json" \
  -d '{"user_id": 42, "reactivate_characters": false}'
```

When `reactivate_characters` is `false`, the response's `characters.status` is `"unchanged"` and `affected` is `0`.

---

## Event webhooks (read + write)

Manage the suite's [event webhooks](WEBHOOKS.md) over the API. **All of these require the Event Webhooks feature to be enabled** — every verb returns `409` (`"The Event Webhooks feature is not enabled on this sim."`) when it's off, and `503` if it's on but the table hasn't been created via *Setup database*.

The destination `url` is returned in full — these endpoints are privileged.

### `GET /webhooks`

List every webhook (enabled first). Scope: `webhooks:read`.

**Response:** `{ "data": [ Webhook, ... ], "total": N }`.

### `GET /webhooks/{id}`

Single webhook by id. Scope: `webhooks:read`.

**Webhook object:**

| Field | Type | Notes |
|---|---|---|
| `id` | int | |
| `label` | string | |
| `url` | string | Destination URL |
| `format` | string | `discord` or `generic` |
| `events` | string[] | Subset of `post.saved`, `post.posted`, `log.posted`, `news.posted` |
| `enabled` | bool | |
| `news_types` | string | `public` / `private` / `both` (for `news.posted`) |
| `mention_role_id` | string \| null | Numeric Discord role id |
| `mention_role_events` | string[] | Events on which the role is pinged |
| `template_title` / `template_description` | string \| null | `post.posted` Discord embed templates |
| `template_log_title` / `template_log_description` | string \| null | `log.posted` templates |
| `template_news_title` / `template_news_description` | string \| null | `news.posted` templates |
| `created_at`, `last_fired_at`, `last_status`, `last_error` | — | Delivery bookkeeping |

### `POST /webhooks`

Create a webhook. Scope: `webhooks:write`. Returns `201` with the created `Webhook`.

**Body** (minimum `label`, `url`, `format`, `events`):

| Field | Required | Notes |
|---|---|---|
| `label` | ✓ | Max 120 chars |
| `url` | ✓ | Valid `http(s)` URL |
| `format` | ✓ | `discord` or `generic` |
| `events` | ✓ | Array; one or more of `post.saved`, `post.posted`, `log.posted`, `news.posted` |
| `enabled` | | Default `true` |
| `news_types` | | `public` (default) / `private` / `both` |
| `mention_role_id` | | Numeric Discord role id (discord format) |
| `mention_role_events` | | Subset of `events` to ping the role on |
| `template_*` | | Discord embed templates (see table above) |

```bash
curl -X POST "$BASE/webhooks" \
  -H "X-API-Key: $TOKEN" -H "Content-Type: application/json" \
  -d '{"label":"Feed","url":"https://discord.com/api/webhooks/...","format":"discord","events":["post.posted"]}'
```

Validation failures return `422` with a `details` array:
```json
{ "error": "Validation failed.", "details": ["url must be a valid http(s) URL."] }
```

### `PATCH /webhooks/{id}` (alias `PUT`)

Update a webhook. Scope: `webhooks:write`. **Partial** — only the fields you send change; omitted fields keep their stored values. Same field set and validation as create. Returns the updated `Webhook`.

```bash
curl -X PATCH "$BASE/webhooks/3" \
  -H "X-API-Key: $TOKEN" -H "Content-Type: application/json" \
  -d '{"enabled": false}'
```

### `DELETE /webhooks/{id}`

Permanently delete a webhook. Scope: `webhooks:write`.

**Response:** `{ "deleted": true, "id": 3 }`.

---

## Field presence as signal

Suite-feature fields (`summary`, `ordered`, `age_gated`, `display_name`, `preferred_name`, `summary_enabled`) are **omitted entirely** when the relevant feature is off &mdash; they aren't returned as `null`. So in n8n / Python / etc:

```python
if "summary" in post:
    # Sim has Mission Post Summary enabled.
    # post["summary"] may still be None for posts with no summary set.
else:
    # Sim doesn't have summaries — don't try to use them.
```

This way you can detect feature availability without an extra config endpoint.

---

## Examples

### curl

```sh
TOKEN=scapi_your_token_here
BASE=https://yoursim.example/extensions/nova_ext_sim_central/Api

# Sanity check
curl -H "X-API-Key: $TOKEN" "$BASE/ping"

# Recent posts
curl -H "X-API-Key: $TOKEN" "$BASE/posts?per_page=5"

# Posts for a single mission
curl -H "X-API-Key: $TOKEN" "$BASE/posts?mission=4&per_page=50"

# Single post
curl -H "X-API-Key: $TOKEN" "$BASE/posts/123"

# Active crew
curl -H "X-API-Key: $TOKEN" "$BASE/characters?status=active"
```

### n8n

1. **Credentials &rarr; New &rarr; Header Auth.**
   - Name: `Sim Central API`
   - Header Name: `X-API-Key`
   - Header Value: `scapi_...` (the raw token; no "Bearer " prefix)
2. **HTTP Request node**
   - Authentication: *Generic Credential Type &rarr; Header Auth &rarr; Sim Central API*
   - Method: `GET`
   - URL: `https://yoursim.example/extensions/nova_ext_sim_central/Api/posts?per_page=10`
3. Use a Set / Code node to walk `{{ $json.data }}` and pull out the fields you need.

For an "is this token still working" health check, run a daily Schedule trigger &rarr; HTTP Request to `/ping` &rarr; If node on `{{ $json.ok }}` &rarr; alert on failure.

---

## Versioning

The API surface is versioned through the suite itself (`config.json` &rarr; `version`). Breaking changes to the response shape will only happen in a major version bump (e.g. 1.x &rarr; 2.0). Additive changes (new optional fields, new endpoints) ship in minor versions and won't break existing consumers.

If you want to pin against a specific version, check the suite version on `GET /ping` (add your own version-fetch endpoint if needed) or read the GitHub release tag of your installed copy.

---

## Troubleshooting

### 401 "Missing API token"

You forgot the header, or it's named something other than `X-API-Key`. The API doesn't accept `Authorization: Bearer`, `apikey`, `token`, or any other variant &mdash; only the literal `X-API-Key` header. Case doesn't matter (`x-api-key` works too).

### 401 "Unknown token"

The header was parsed but the SHA-256 hash doesn't match any row. Either the token was revoked + deleted, or you're hitting a different sim than where you issued it, or you typoed the value.

### 503 "REST API is enabled but the tokens table is missing"

You toggled the feature on but didn't run **Setup database**. Suite admin &rarr; REST API row &rarr; **Setup database**.

### 429 every few requests

You're over the per-token rate limit. Default is 60/minute per token. Bump `rest_api_rate_limit_per_minute` (set to `0` to disable) or use multiple tokens for higher-throughput flows.

---

## Security notes

- Tokens are admin-only. Sysadmins with `site/settings` access are the only users who can issue or revoke them. There is intentionally no per-user self-service.
- Tokens are stored as SHA-256 hashes. Database compromise does not yield usable tokens.
- Revoking a token sets `revoked_at`; the row stays for audit. Delete only if you really want to forget it ever existed.
- The API is **disabled by default**. With the feature toggle off, every endpoint returns `404` &mdash; no surface is exposed to scanners or attackers.
- Always serve the sim over HTTPS in production. Bearer tokens in cleartext over HTTP are not safe.
