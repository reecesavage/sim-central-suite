# Sim Central Suite v1.30.0 — Astrolabe snapshot endpoint

Adds a single read-only REST API endpoint that lets the **Astrolabe** platform mirror a live-ish snapshot of the sim on that sim's Astrolabe page.

## What's new

### `GET /snapshot` — the Astrolabe snapshot (scope `astrolabe:read`)

One JSON aggregate of the sim's **public** data, built for Astrolabe to poll and mirror:

- **`game`** — name + homepage URL.
- **`stats`** — players (active accounts), characters (active + NPCs), stories (missions).
- **`manifest`** — the crew roster: Nova's manifests → departments (and sub-departments) → characters (active players **and** NPCs), each with position, avatar, rank `{name, abbreviation, image}`, a link to their page, and the player's public display name.
- **`stories`** — missions, with status, post counts, and links.
- **`recent_posts`** — the 10 most recent posts, newest first, with authors, timestamp, and a plain-text excerpt.

It's part of the existing **REST API** feature, so it reuses everything: mint a token in *REST API → Configure* scoped to **only** `astrolabe:read` (that token can read the snapshot and nothing else), authenticate with the `X-API-Key` header, and it shows up in the API Explorer and the OpenAPI spec automatically.

**Guarantees:** every `url` / `avatar_url` / `rank.image` is an absolute `https://` URL or `null`; `description` / `excerpt` are HTML-stripped and length-capped; missing single values are `null` and missing lists `[]`; `recent_posts` is capped at 10. **No private data** — no email, real name, IP, or account internals. The snapshot is served from a short (~10 minute) cache since Astrolabe polls on a schedule.

Full integration contract for the Astrolabe developer is in **[`ASTROLABE.md`](ASTROLABE.md)** (endpoint URL, `X-API-Key` auth, response shape, field reference, error behavior).

## Upgrade

Use the **Update Now** button on the dashboard, or `POST /Api/suite`. No database changes, no shims. Then, on a sim that wants to appear in Astrolabe: enable the REST API feature, create a token scoped to `astrolabe:read`, and hand Reece the endpoint URL + token.

## Credits

MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
