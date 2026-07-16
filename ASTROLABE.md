# Astrolabe Snapshot API — integration guide (as built)

**Audience:** the Astrolabe developer.
**What this is:** one small, read-only JSON endpoint per Nova sim (running the
Sim Central Suite) that returns a live-ish snapshot of the sim — crew manifest,
stories, recent posts, counts — for Astrolabe to mirror on that sim's page.

This document describes the endpoint **exactly as implemented in Sim Central
Suite v1.30.0**. It follows the original brief with **one change you need to
make on the Astrolabe side** (auth header — see below).

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
              "player": { "name": "Reece" }
            }
          ]
        }
      ]
    }
  ],
  "stories": [
    {
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
| `…characters[].player` | object \| null | `{ name }` — the player's **public display name only**. null for NPCs / unowned. |
| `stories` | array | Missions. May be `[]`. |
| `stories[].title` | string | |
| `stories[].description` | string \| null | Plain text, ≤ 300 chars. |
| `stories[].status` | string \| null | Nova values: `upcoming` / `current` / `completed`. |
| `stories[].start_date` | null | Nova has no in-character mission dates → always null. |
| `stories[].end_date` | null | Same. |
| `stories[].posts_count` | int | Activated posts in the mission. |
| `stories[].url` | string \| null | Absolute https link to the mission. |
| `recent_posts` | array | **≤ 10**, newest first. May be `[]`. |
| `recent_posts[].title` | string | |
| `recent_posts[].authors` | string[] | Character display names. `[]` if none. |
| `recent_posts[].published_at` | string \| null | ISO 8601 UTC. |
| `recent_posts[].excerpt` | string \| null | Plain text, ≤ 300 chars. |
| `recent_posts[].url` | string \| null | Absolute https link to the post. |
| `open_positions` | array | Positions the sim is recruiting for (Nova open positions). `[]` when none. *(v1.31.0+)* |
| `open_positions[].name` | string | Position title, e.g. `"Chief Engineer"`. |
| `open_positions[].department` | string \| null | Department name (matches the manifest's department labels where shared). |
| `open_positions[].openings` | int | Open slots (Nova `pos_open`); always ≥ 1 (filled positions are omitted). |
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

## 6. Future two-way sync (reserved, not built)

No write-back exists in this phase. If/when it does, it will be `POST` endpoints
under the same API using the same `X-API-Key` token with their own write scopes.
Nothing to build now.

---

## Handover checklist (Reece → Astrolabe)

- [ ] Sim URL (base): `https://<sim>/extensions/nova_ext_sim_central/Api/snapshot`
- [ ] Token (`scapi_…`, scoped to `astrolabe:read`)
- [ ] Astrolabe poller updated to send `X-API-Key` (not `Authorization: Bearer`)
