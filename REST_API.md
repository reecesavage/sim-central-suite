# Sim Central Suite — REST API Reference

The suite's REST API exposes read-only HTTP endpoints for external integrations: n8n workflows, scripts, dashboards, anything that needs to pull mission posts, characters, or missions out of the sim without scraping HTML.

> **Status:** v1 — read-only. Write endpoints (post creation, character edits) are not yet exposed.

---

## Setup

1. *Sim Central Suite &rarr; REST API &rarr;* **Enable**.
2. Click **Setup database**. This creates `<prefix>nova_ext_sim_central_api_tokens`.
3. Click **Configure** &rarr; fill in a label, tick the scopes you want, optionally set an expiry &rarr; **Create Token**.
4. **Copy the token immediately.** It is shown exactly once. Only its hash is stored.

Tokens are managed entirely in the ACP. There is no self-service issuance &mdash; only sysadmins with access to `site/settings` can create or revoke them.

---

## Authentication

Every request must send:

```
Authorization: Bearer scapi_<40 hex chars>
```

Tokens look like `scapi_a1b2c3d4e5f6...` (a `scapi_` prefix plus 40 random hex characters &mdash; 160 bits of entropy).

The server hashes the supplied token with SHA-256 and looks it up. The raw value is never stored, never logged, and cannot be recovered if lost &mdash; revoke and re-issue if you lose one.

---

## Scopes

Tokens carry an explicit scope list. Endpoints check for the scope they require and return `403` if missing.

| Scope | Grants |
|---|---|
| `posts:read` | List posts, view a single post |
| `characters:read` | List characters, view a single character |
| `missions:read` | List missions, view a single mission |

A token with no relevant scope but a valid signature will still get `200` on `/ping`, since that endpoint is scope-free by design (it's the n8n "does this token work?" check).

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
| `401` | Missing / malformed / unknown / revoked / expired token |
| `403` | Token is valid but lacks the required scope |
| `404` | Resource not found, *or* the REST API feature is disabled on this sim |
| `429` | Rate limit exceeded |
| `500` | Bug. File an issue. |

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

Single post by id. Scope: `posts:read`. Returns `404` for non-activated posts (unlike `/posts?status=any`, the single endpoint is strictly public-only).

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
curl -H "Authorization: Bearer $TOKEN" "$BASE/ping"

# Recent posts
curl -H "Authorization: Bearer $TOKEN" "$BASE/posts?per_page=5"

# Posts for a single mission
curl -H "Authorization: Bearer $TOKEN" "$BASE/posts?mission=4&per_page=50"

# Single post
curl -H "Authorization: Bearer $TOKEN" "$BASE/posts/123"

# Active crew
curl -H "Authorization: Bearer $TOKEN" "$BASE/characters?status=active"
```

### n8n

1. **Credentials &rarr; New &rarr; Header Auth.**
   - Name: `Sim Central API`
   - Header Name: `Authorization`
   - Header Value: `Bearer scapi_...`
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

## Security notes

- Tokens are admin-only. Sysadmins with `site/settings` access are the only users who can issue or revoke them. There is intentionally no per-user self-service.
- Tokens are stored as SHA-256 hashes. Database compromise does not yield usable tokens.
- Revoking a token sets `revoked_at`; the row stays for audit. Delete only if you really want to forget it ever existed.
- The API is **disabled by default**. With the feature toggle off, every endpoint returns `404` &mdash; no surface is exposed to scanners or attackers.
- Always serve the sim over HTTPS in production. Bearer tokens in cleartext over HTTP are not safe.
