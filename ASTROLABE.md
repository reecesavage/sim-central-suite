# Astrolabe Snapshot API — integration guide (as built)

**Audience:** the Astrolabe developer.
**What this is:** one small, read-only JSON endpoint per Nova sim (running the
Sim Central Suite) that returns a live-ish snapshot of the sim — crew manifest,
stories, recent posts, counts — for Astrolabe to mirror on that sim's page,
plus (§6) the on-demand post endpoints behind a public **Story Archive**.

This document describes the endpoints **exactly as implemented in Sim Central
Suite v1.36.0**. It follows the original brief with **two changes you need to
make on the Astrolabe side**: the auth header (§2) and the post byline field
(§6).

---

## 1. The endpoint

```
GET  {sim_base_url}/extensions/nova_ext_sim_central/Api/snapshot
```

- One snapshot describes exactly **one sim** (the one at that base URL). Astrolabe
  stores a base URL + token per sim; there is no multi-sim routing.
- Read-only and idempotent. Safe to cache and regenerate on your side.
- The Suite serves it from a short-lived cache (regenerated at most every ~10
  minutes), so polling every ~15 minutes is ideal.

> **Note on the URL.** The brief proposed a pretty `/api/astrolabe/v1/snapshot`
> path. To reuse the Suite's existing REST API (tokens, rate limiting, docs) we
> ship it under the API's real path shown above. It is stable — treat it as the
> endpoint. (`version` in the body still signals contract version.)

---

## 2. Auth — **X-API-Key, not Authorization: Bearer** ⚠️

**This is the one change from the brief.** Send the token in the `X-API-Key`
header, **not** `Authorization: Bearer`:

```
X-API-Key: <token>
Accept: application/json
```

Why: Nova commonly runs behind Apache/Cloudflare, which strips the
`Authorization` header before PHP sees it — so Bearer auth would fail
intermittently. The whole Suite API uses `X-API-Key` for this reason, and the
snapshot follows suit. **Please update the Astrolabe poller to send
`X-API-Key`.**

- Missing/invalid token → **`401`**. Valid → **`200`**.
- The token looks like `scapi_` followed by 40 hex characters.
- It is rotatable: Reece regenerates it in the sim's admin panel and re-pastes
  it into Astrolabe. Treat it as a secret.

### How Reece creates the token (sim side, for reference)

In the sim's admin panel: **Manage Extensions → Sim Central Suite → REST API →
Configure → Create token**, label it (e.g. "Astrolabe"), tick **only** the
`astrolabe:read` scope, and copy the token shown once. A token scoped to only
`astrolabe:read` can read **just this snapshot** and nothing else in the API.

**Or (v1.32.0+, preferred): use the Sim Central grant.** The one-button
**"Grant Sim Central access"** token now carries `astrolabe:read` and
`positions:read` too, and the **registry** worker (registry.simcentral.host —
not the Discord OAuth broker) forwards each grant to Astrolabe's
`POST /api/sim-central/registrations` receiver with the shared `X-SC-Secret` —
so a sim that grants access simply appears in Astrolabe's admin with a working
token, no copy-paste. Existing grants pick the new scopes up automatically on
the sim's next suite update.

The forward payload the receiver builds against (raw token included on
`granted` **only** — decided over a metadata-only forward + pull-back, which
would have needed a new registry route and a standing credential):

```json
{
  "action": "granted",                 // granted | updated | revoked | deleted (event mirrors it)
  "event":  "granted",
  "sim": { "name": "USS Example", "url": "https://example.org/",
           "api_url": "https://example.org/index.php/extensions/nova_ext_sim_central/Api",
           "version": "1.32.0" },
  "token": "scapi_…",                  // ONLY on granted; null otherwise
  "token_prefix": "scapi_abcd12",
  "scopes": ["posts:read", "positions:read", "astrolabe:read", "…"],
  "at": "2026-07-17T12:00:00.000Z"
}
```

Answer any 2xx quickly (204 ideal). Best-effort, no retries — a missed forward
is reconciled by re-granting on the sim. `updated` = same token, re-read
`scopes`; `revoked`/`deleted` = token is dead. Sims are keyed by `sim.url`.
Full contract: the sim-central-registry README.

---

## 3. Response (HTTP 200)

Content-Type `application/json`. Shape:

```json
{
  "version": 1,
  "generated_at": "2026-07-15T12:00:00Z",
  "game": {
    "name": "USS Example",
    "url": "https://ussexample.simcentral.org/",
    "description": null
  },
  "stats": { "players": 12, "characters": 18, "stories": 5 },
  "manifest": [
    {
      "name": "Crew Manifest",
      "slug": "crew-manifest",
      "departments": [
        {
          "department": "Command",
          "characters": [
            {
              "name": "Jane Doe",
              "position": "Commanding Officer",
              "avatar_url": "https://ussexample.simcentral.org/nova/assets/images/characters/jane.png",
              "url": "https://ussexample.simcentral.org/personnel/character/12",
              "rank": {
                "name": "Captain",
                "abbreviation": "CAPT",
                "image": "https://ussexample.simcentral.org/nova/assets/common/genre/ranks/standard/capt.png"
              },
              "player": { "name": "Reece", "discord_id": "797896720586768385" }
            }
          ]
        }
      ]
    }
  ],
  "stories": [
    {
      "id": 3,
      "title": "First Contact",
      "description": "The ship meets a new species.",
      "status": "current",
      "start_date": null,
      "end_date": null,
      "posts_count": 14,
      "url": "https://ussexample.simcentral.org/sim/missions/id/3"
    }
  ],
  "recent_posts": [
    {
      "title": "Bridge, Red Alert",
      "authors": ["Jane Doe", "John Smith"],
      "published_at": "2026-07-10T18:22:00Z",
      "excerpt": "Klaxons blared across the bridge as...",
      "url": "https://ussexample.simcentral.org/sim/viewpost/123"
    }
  ],
  "open_positions": [
    {
      "name": "Chief Engineer",
      "department": "Engineering",
      "openings": 1,
      "top": true,
      "description": "Keeps the warp core humming.",
      "url": "https://ussexample.simcentral.org/main/join"
    }
  ]
}
```

### Field reference

| Field | Type | Notes |
|---|---|---|
| `version` | int | Always `1` for this contract. |
| `generated_at` | string | ISO 8601 UTC (`Z`). When the snapshot was built. |
| `game.name` | string | Sim display name. |
| `game.url` | string | Absolute https homepage. |
| `game.description` | null | **Always null** — Astrolabe owns the blurb (enter it on your side). |
| `stats.players` | int | Active player accounts on the sim. |
| `stats.characters` | int | Active characters + NPCs. |
| `stats.stories` | int | Mission count. |
| `manifest` | array | Roster groups (Nova "manifests"). May be `[]`. |
| `manifest[].name` | string | Roster label, e.g. `"Crew Manifest"`. |
| `manifest[].slug` | string | URL-safe id, unique within the array. |
| `manifest[].departments[]` | array | Departments (top-level and sub-departments are each their own entry). Empty departments are omitted. |
| `…departments[].department` | string | Department name. |
| `…departments[].characters[]` | array | Active + NPC characters in that department, de-duplicated. |
| `…characters[].name` | string | Character name, no rank prefix (honours the sim's Display Name override when set). |
| `…characters[].position` | string \| null | Position title. |
| `…characters[].avatar_url` | string \| null | Absolute https, or null. |
| `…characters[].url` | string \| null | Absolute https link to the character's page. |
| `…characters[].rank` | object \| null | `{ name, abbreviation, image }`; each may be null. `image` absolute https or null. |
| `…characters[].player` | object \| null | `{ name, discord_id }` — the player's **public display name**, plus *(v1.32.0+)* their linked **public Discord ID** when the sim runs Discord Sign-In and the player linked their account (`null` otherwise — feature off, unlinked, or pre-1.32 suite). Object is null for NPCs / unowned. Same visibility rule the event webhooks use for @mentions; the ID is public Discord data. |
| `stories` | array | Missions. May be `[]`. |
| `stories[].id` | int | The Nova mission id &mdash; pass it back as `mission_id` on `GET /posts` to read that mission's post archive. *(v1.36.0+)* |
| `stories[].title` | string | **Fixed in v1.36.0** — the builder read a column that doesn't exist on Nova's missions table, so every story title arrived as `""`. If you cached blank titles, they refill on the next poll after the sim updates. |
| `stories[].description` | string \| null | Plain text, ≤ 300 chars. |
| `stories[].status` | string \| null | Nova values: `upcoming` / `current` / `completed`. |
| `stories[].start_date` | null | Nova has no in-character mission dates → always null. |
| `stories[].end_date` | null | Same. |
| `stories[].posts_count` | int | Activated posts in the mission &mdash; exactly the set `GET /posts?mission_id=<id>&status=activated` returns, so the count on an index matches what the reader then sees. |
| `stories[].url` | string \| null | Absolute https link to the mission. |
| `recent_posts` | array | **≤ 10**, newest first. May be `[]`. |
| `recent_posts[].title` | string | |
| `recent_posts[].authors` | string[] | Character display names. `[]` if none. |
| `recent_posts[].published_at` | string \| null | ISO 8601 UTC. |
| `recent_posts[].excerpt` | string \| null | Plain text, ≤ 300 chars. |
| `recent_posts[].url` | string \| null | Absolute https link to the post. |
| `open_positions` | array | **All** open positions the sim is recruiting for (Nova open positions, `pos_open > 0`) — not just the featured/top list. Always present; `[]` when none. *(v1.31.0+)* |
| `open_positions[].name` | string | Position title, e.g. `"Chief Engineer"`. |
| `open_positions[].department` | string \| null | Department name (matches the manifest's department labels where shared). |
| `open_positions[].openings` | int | Open slots (Nova `pos_open`); always ≥ 1 (filled positions are omitted). |
| `open_positions[].top` | bool | `true` when the position is on the sim's "top open positions" (featured) list, else `false`. *(v1.31.1+)* |
| `open_positions[].description` | string \| null | Plain text, ≤ 300 chars. |
| `open_positions[].url` | string \| null | Absolute https link to the sim's join/apply page. |

---

## 4. Guarantees the Suite makes

1. **All URLs are absolute `https://`** (or null) — every `url`, `avatar_url`,
   and `rank.image`.
2. **Plain text** in `description` and `excerpt` — HTML stripped, entities
   decoded, whitespace collapsed, length-capped. **All human-readable strings**
   (names, department labels, positions, ranks, titles, player names) are also
   **entity-decoded** *(v1.31.0+)*, so `Security &amp; Tactical` arrives as
   `Security & Tactical`.
3. **`null` for a missing single value, `[]` for a missing list.** Required keys
   (`version`, `generated_at`, `game`, `stats`, `manifest`) are always present.
4. **`recent_posts` ≤ 10, newest first.**
5. **No private data** — only what's already public on the sim: roster,
   character names, player display names, missions, posts. Never email, real
   name, IP, or account internals.

---

## 5. Errors

- **`401`** — missing/invalid `X-API-Key`.
- **`404`** — the REST API feature is off on that sim (nothing is exposed).
- Any non-`200` (401, 404, 5xx, timeout, malformed JSON) → treat as a failed
  poll: log it and **keep the last good snapshot** so the page never blanks.

---

## 6. Story archive &mdash; reading a sim's posts on demand *(v1.36.0+)*

The archive deliberately isn't in the snapshot: a sim with 600+ posts can't ship
one in a payload polled every 15 minutes. Instead the **snapshot is the index**
and the posts are read on demand through the same token, which already carries
`posts:read` and `missions:read`.

**Index** &mdash; `snapshot.stories[]`, which now carries `id` (the Nova mission
id) alongside the title, description, status, and `posts_count` you already
mirror. There is **no `slug`**: Nova has no mission slug column, so mission URLs
have to be keyed by id.

**A mission's posts:**

```
GET /posts?mission_id=4&status=activated&sort=order&page=3&per_page=25&content=0
```

| Param | Notes |
|---|---|
| `mission_id` | int &mdash; restrict to one mission. (`mission` remains as a legacy alias.) |
| `status` | `activated` = publicly visible posts only. |
| `sort` | `order` &rarr; in-mission reading order, ascending &mdash; the *Ordered Mission Posts* scheme when the sim runs it, else post date ascending, with post date as the final tie-break. Omit for the default newest-first. |
| `content` | `0` omits each post's body from the response &mdash; metadata-only rows, ~14 KB instead of 235 KB&ndash;1.6 MB. Send it for an index page and fetch bodies from `GET /posts/{id}` on demand. Default (omitted) includes bodies, unchanged. |
| `page` | 1-based. |
| `per_page` | Capped at 100. |

```jsonc
{
  "data": [
    {
      "id": 43,
      "title": "Bridge, Red Alert",
      "status": "activated",
      "mission_id": 4,
      "word_count": 1204,
      "author_names": ["Jane Doe", "John Smith"],
      "published_at": "2026-07-10T18:22:00Z",
      "url": "https://ussexample.simcentral.org/sim/viewpost/43",
      "excerpt": "Klaxons blared across the bridge as…"
    }
  ],
  "page": 3, "per_page": 25, "total": 587,
  "meta": { "current_page": 3, "last_page": 24, "per_page": 25, "total": 587 }
}
```

With `content=0` that's every field above and no `content` key. `word_count` and
`excerpt` survive &mdash; both are derived from the body, so the sim still reads and
counts it; what's saved is the serialization, the memory, and the transfer.
`meta` is unaffected either way: `total` and `last_page` describe the full result
set, not the page.

`word_count` is present on **every** post regardless of status, drafts included.
It's computed per request as `str_word_count(strip_tags($content))` &mdash; the
suite's one canonical definition, the same figure the sim shows on Manage
Missions and `/sim/missions` &mdash; not a stored column that could be null.

**One post's body** &mdash; `GET /posts/{id}` returns the same object plus
`content`, so a post page needs exactly one call and can be cached on your side.
`content=0` is list-only; the single read always includes the body.

### ⚠️ Read the byline from `author_names`, not `authors`

The brief asked for display names in the list's `authors`. **`authors` already
exists** on both the list and the single post as Nova's native comma-joined
**charid string** (`"17,21"`) &mdash; the same value the edit form explodes. Changing
its type would have broken every existing consumer, including your own edit
form, so the names ship alongside it instead:

- **`author_names`** &mdash; `["Jane Doe", "John Smith"]`, on **both** the list and
  the single post. `[]` when there are none; ids that no longer resolve are
  dropped rather than null-padded. Honours the sim's *Display Name* override, so
  a byline reads the same as that character's name on the manifest.
- **`authors`** &mdash; unchanged, still the id string. Keep using it for the picker.

Your documented fallback order for the post page (`author_names`, then
object-shaped `authors`, then nothing) already lands on the right field. **The
one change needed on the Astrolabe side is to read `author_names` on the list
rows too** &mdash; the list will never carry names in `authors`.

### Notes

- `url` is the post's own page on the sim, absolute `https://`, same rule as
  `recent_posts[].url`. Use it for the "Read on {sim} ↗" link and for
  `rel="canonical"`.
- `excerpt` is plain text, HTML stripped and capped at 300 chars &mdash; the same
  treatment as `recent_posts[].excerpt`. `null` when the body is empty.
- `published_at` is the activation timestamp, ISO 8601 UTC. `null` on anything
  not activated, so it's never a draft's last-save time.
- `meta.total` / `meta.last_page` are on **every** list endpoint, not just posts.
- A token without a posts read scope gets `403` from `/posts` entirely &mdash; no
  `data` key, drafts or otherwise.
- `content=0` is **opt-out**, so a caller that wants bodies (existing n8n flows,
  anything reading `data[].content`) is unaffected by its existence.

---

## 7. Future two-way sync (reserved, not built)

No write-back exists in this phase. If/when it does, it will be `POST` endpoints
under the same API using the same `X-API-Key` token with their own write scopes.
Nothing to build now.

---

## Handover checklist (Reece → Astrolabe)

- [ ] Sim URL (base): `https://<sim>/extensions/nova_ext_sim_central/Api/snapshot`
- [ ] Token (`scapi_…`, scoped to `astrolabe:read`)
- [ ] Astrolabe poller updated to send `X-API-Key` (not `Authorization: Bearer`)
