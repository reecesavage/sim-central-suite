# Migrating a Nova sim into Astrolabe — API guide (as built)

**Companion to [ASTROLABE.md](ASTROLABE.md)**, which covers the snapshot endpoint. This one covers everything delta v13 asked for: what shipped, what changed shape from the request, and what didn't ship.

Everything below is live in **v1.41.0** (sim structure) and **v1.42.0** (content archive). Nothing in either release changes an existing response shape or field meaning — with one runtime exception, flagged under [Before you upgrade](#before-you-upgrade).

---

## Read this first — five corrections

These change what you should code against. All five are cases where the request assumed a shape Nova doesn't actually have.

### 1. Three requested paths can't exist

Nova's extension router dispatches on `method_exists($class, $URI->segment(4))`, and `awards-received`, `mission-groups` and `bio-fields` aren't legal PHP method names. Hyphens are fine at segment 5, so each is namespaced under an existing resource:

| You asked for | It is |
|---|---|
| `GET /awards-received` | **`GET /awards/received`** |
| `GET /mission-groups` | **`GET /missions/groups`** |
| `GET /bio-fields` | **`GET /characters/bio-fields`** |

`/missions/groups` reads backwards — a group contains missions, not the other way round. It's a namespace, not a containment claim; `/users/disable` is namespaced the same way. The alternative was a `_remap()` that would have hijacked dispatch for all 44 endpoints.

### 2. Decks belong to a ship, not to the tour

`tour_decks.deck_item` points at **`specs.specs_id`**, not `tour_id` — Nova's own deck editor picks a ship from the specs list. So `decks[]` is returned by **`GET /specs`**, not `GET /tour`. Each tour location instead names its ship via `spec_item_id`, so you can still associate the two.

### 3. The tour has no sections

There is no tour sections table and `tour_fields` has no `field_section` column — tour fields are one flat ordered list. A tour field therefore has **no `section` key at all** (absent, not `null`). Spec fields do have one.

### 4. `post_participants` holds *user* ids

Not character ids. It's the one id CSV on a post row that isn't character-keyed — every other one (`post_authors`) is characters, which makes this an easy silent bug. Each entry is an explicit object so it can't be misread:

```jsonc
"participants": [ { "user_id": 7, "name": "Jane Doe" } ]
```

`name` is `null` if the user no longer resolves, and the entry is kept anyway. Nova only maintains this column when the sim has *Post Participants* on, so `[]` is the normal answer.

### 5. `mission_notes` is not public data

It renders only in the members' writing area and the admin panel, behind a `use_mission_notes` permission, and on no public page. Putting it on `missions:read` would have made that the first endpoint here to hand out something the sim doesn't show its own visitors.

It ships behind a new **`missions:read.all`** — scope on the token *and* the bound user must be a sysadmin. A migration is run by the sim's owner, so you clear that bar; mint the token accordingly. Without it the `notes` key is **absent**, not null.

---

## Scopes

| Scope | Covers | New? |
|---|---|---|
| `characters:read` | characters, `?include=bio`, `/characters/bio-fields`, `/ranks` | existing |
| `positions:read` | positions, `/departments`, `/manifests` | existing |
| `missions:read` | missions, `/missions/groups` | existing |
| `posts:read` | posts | existing |
| `logs:read` | published personal logs | existing |
| `news:read` | `/news` | **v1.42.0** |
| `awards:read` | `/awards`, `/awards/received` | **v1.42.0** |
| `specs:read` | `/specs`, `/tour` | **v1.42.0** |
| `missions:read.all` | + mission `notes` | **v1.42.0**, sysadmin-bound |
| `logs:read.all` | + log drafts | **v1.42.0**, sysadmin-bound |

`news:read`, `awards:read` and `specs:read` were added to the Sim Central grant, so an already-granted sim picks them up on upgrade **without a re-grant**.

The two `.all` scopes were deliberately left out of that grant — they reach content the sim shows nobody, so they have to be minted on purpose rather than widened into an existing token by an upgrade. Both **degrade silently**: a token carrying the scope but not bound to a sysadmin gets the ordinary public answer, not a 403. Don't use a 200 as proof you have the privileged view — check the `notes` key is present, or that `?status=any` actually returned a draft.

---

## What shipped, by delta item

| Item | Status | Where |
|---|---|---|
| **A** `player.discord_id` | ✅ v1.41.0 | on every `GET /characters` row |
| **B** departments | ✅ v1.41.0 | `GET /departments` |
| **C** all positions | ✅ v1.41.0 | `GET /positions?open=any` |
| **D** manifests | ✅ v1.41.0 | `GET /manifests` |
| **E** ranks + artwork | ✅ v1.41.0 | `GET /ranks` |
| **F1** bio form | ✅ v1.42.0 | `GET /characters/bio-fields` |
| **F2** bios on the list | ✅ v1.42.0 | `GET /characters?include=bio` |
| **G** mission groups | ✅ v1.42.0 | `GET /missions/groups`, `group_id`, `notes` (see correction 5) |
| **H** news | ✅ v1.42.0 | `GET /news` |
| **I** awards | ✅ v1.42.0 | `GET /awards`, `GET /awards/received` |
| **J** specs / tour | ✅ v1.42.0 | `GET /specs`, `GET /tour` (see corrections 2–3) |
| **K** log drafts | ✅ v1.42.0 | `GET /logs?status=any` + `logs:read.all` |
| **L** `per_page` 500 | ✅ v1.41.0 | every list endpoint |
| **L** per-token rate limit | ❌ not built | see [Not shipped](#not-shipped) |
| **M** images, names, dates, tags, participants | ✅ v1.41.0 | see below |
| **M** `?updated_since` on `/missions` | ❌ deferred | see [Not shipped](#not-shipped) |

### Item A — the one that mattered

```jsonc
{
  "id": 41,
  "first_name": "Kira",
  "user_id": 7,
  "user_name": "Jane Doe",
  "player": { "name": "Jane Doe", "discord_id": "182736451827364518" }
}
```

`player.name` and `user_name` are guaranteed identical — both read one source, so they can't drift. `player` is `null` for an unowned character; `discord_id` is `null` when Discord Sign-In is off or the player never linked.

Present on **every** character, inactive and pending included — which is the whole point, since the snapshot manifest lists active and NPC only.

### Item E — rank artwork

`image_url` is absolute and generated sim-side. It is `null` in two cases, and you should treat them the same: the rank has no image, **or** the sim's configured rank set can't be resolved at all. The second usually means a rank set belonging to a different genre — `get_rankcat()` filters on genre, so the extension resolves empty and no URL can be built. Null rather than a URL that would 404.

If *every* rank comes back with `image_url: null`, that's the second case, not an empty ladder.

### Item M — what landed where

| Endpoint | Fields |
|---|---|
| `GET /characters` | `images[]`, `middle_name`, `date_activate`, `date_deactivate` |
| `GET /missions` | `images[]` |
| `GET /posts`, `/posts/{id}` | `tags`, `participants[]` |

`tags` was a genuine bug: accepted on write and folded into `content_hash` since post authoring shipped, but never returned — so a tag written through this API couldn't be read back by anything.

---

## Every image is an absolute URL

`Character.images`, `Mission.images`, `Rank.image_url`, `Award.image_url`, `SpecItem.images`, `TourItem.images`, and the snapshot's existing `avatar_url` / `rank.image` all follow one rule, now enforced by one shared implementation:

- an absolute `https://` URL, or `null`
- never a bare filename, never `""`, never a site-relative path
- `http://` is rewritten to `https://`
- **list** fields are always an array — `[]` when empty, never `null`
- **single** image fields are `null` when there is none

---

## Endpoint reference

Full request/response detail is in [REST_API.md](REST_API.md) and the live OpenAPI document at `GET /openapi`. Summary:

### Structure

- **`GET /departments`** — `id, name, description, order, display, type, parent_id, manifest_id`. Includes child departments. `?display=y|n` (omit for both), `?manifest_id=`.
- **`GET /manifests`** — `id, name, description, order, display, default`. `manifest_view` and `manifest_metadata` are omitted; the first stores raw jQuery snippets, the second is a pipe-delimited list of bio field names.
- **`GET /ranks`** — `id, name, short_name, order, display, image_url`. Full ladder including unworn rungs. The rank catalogue isn't exposed — import the artwork as one game-owned set.
- **`GET /positions?open=any`** — every position including filled ones. Rows gained `display`. `open` is a **count**, not a flag: capacity is (current holders + `open`). `?top=1` is ignored when `open=any`. Note `?display` is `y` **or** `n`, never both — two calls if you want everything.

### People

- **`GET /characters/bio-fields`** — the form itself: `id, name, label, type, order, display, help, rows, section{id,name,order,tab{id,name,order,display}}`, plus `options[]` on select fields only. **Hidden fields are included here** (with `display: false`) because this describes the form, not a character. `options[].value` is exactly what appears in a character's `bio[].value`.
- **`GET /characters?include=bio`** — bios on the roster. Opt-in for payload size, **not** for cost: the whole page's values are read in one query regardless of page size.

### Story

- **`GET /missions/groups`** — `id, name, description, order, parent_id`. Attach via `group_id` on missions, which is `null` for ungrouped (Nova stores that as `0` or `NULL` depending on how it got there; both come back `null`).
- **`GET /news`** — defaults to `activated`; `?status=any` for unpublished. `private` is reported, never filtered on. `?category_id=` available.
- **`GET /awards`** / **`GET /awards/received`** — ⚠️ **key grants on their `id`.** The same award can legitimately be given to the same character twice, so the grant id is the only thing that makes a re-run idempotent. Pending and rejected nominations are not exposed by any endpoint.
- **`GET /specs`** / **`GET /tour`** — EAV flattened sim-side into `fields[{name, label, value, order, section?}]`. Empty values are **omitted entirely**, not returned as `""`. `decks[]` on specs (correction 2); no `section` on tour fields (correction 3).
- **`GET /logs?status=any`** — drafts, with `logs:read.all` on a sysadmin-bound token.

---

## Suggested walk order

Each step only needs what came before it.

1. `GET /openapi` — probe. Every path in this doc appears there; a 404 means an older suite.
2. `GET /manifests` → `GET /departments` → `GET /positions?open=any` → `GET /ranks` — the skeleton.
3. `GET /characters/bio-fields` — build the form before you have values to put in it.
4. `GET /characters?status=any&include=bio&per_page=500` — the roster, owners and bios in one walk.
5. `GET /missions/groups` → `GET /missions` — arcs, then missions.
6. `GET /posts?per_page=500` per mission, or in bulk.
7. `GET /logs`, `GET /news`, `GET /awards`, `GET /awards/received`, `GET /specs`, `GET /tour`.

Ownership resolves entirely from step 4: match `player.discord_id` to an Astrolabe account, and treat `null` as unowned rather than guessing from `user_name`.

---

## Before you upgrade

**`per_page` went from 100 to 500.** This is the one non-additive change across both releases. No response *shape* changed, but a client already sending `per_page=500` used to get 100 rows back and page for the rest — now it gets 500 in one response. If anything on your side sizes a buffer, a timeout or a memory budget from the page it receives, check it. `GET /posts` with bodies at 500 rows is the largest response this API can produce.

**`?include=bio` wants an index.** v1.42.0 adds one to `characters_data`, which Nova ships with only a primary key. The sim admin needs to run **Setup database** once after upgrading. Nothing else needs it, and re-running is a no-op — but without it, `?include=bio` table-scans.

---

## Not shipped

**`?updated_since` on `/missions`** — deferred. Unlike everything else in the delta this isn't read-only: it needs two tracking columns on `missions`, an index, a hash function, a backfill, and a managed code block written into Nova core's `Missions_model.php`. A one-time migration reads every mission anyway, so it buys a retiring sim nothing. Reopen it if a live-sync consumer ever needs it; `posts_updated_at` still covers the per-mission post delta.

**Per-token rate-limit override** — not built. `rest_api_rate_limit_per_minute` remains a per-suite setting. With `per_page=500` the walk is short enough that the default 60/min shouldn't bind; ask if it does.

**Nova's built-in wiki** — out of scope, as you noted.

---

## If something looks wrong

- **Every `image_url` is null** → the sim's rank set can't be resolved, usually a genre mismatch. Not an empty ladder.
- **`notes` key missing on missions** → the token isn't sysadmin-bound, or lacks `missions:read.all`. It degrades silently.
- **`?status=any` on `/logs` returns only published** → same, for `logs:read.all`.
- **A department you expected is missing** → check `?display`; departments default to returning both, but a filter may be set.
- **`403`** → the token lacks the scope. `404` on a whole endpoint → the REST API feature is off on that sim, or the suite predates the release.
