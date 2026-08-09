# Sim Central Suite v1.41.0 — the sim underneath the story

The REST API has been able to hand out a sim's **archive** — its characters, missions and posts — for a while now. What it couldn't hand out was everything a sim *is* underneath that archive: its departments, its rank ladder and artwork, its manifests.

This release closes that gap. It's the first of two: the structural half ships now, the content half (bio form definitions, mission groups, news, awards, specs, deck tour) follows in v1.42.0.

Everything here is **additive** — no existing endpoint changed shape, and no existing field changed type or meaning. The one exception is the page-size cap, called out at the bottom.

## Matching a character to the person who plays them

`GET /characters` rows now carry a **`player`** object:

```jsonc
"player": {
  "name": "Jane Doe",
  "discord_id": "182736451827364518"
}
```

This is the same object the Astrolabe snapshot manifest has carried since v1.32.0, under the same visibility rule — the linked Discord ID when *Discord Sign-In* is on and the player linked their account, `null` otherwise, and `null` entirely for a character nobody owns. No new information leaves your sim; it's an existing field on the endpoint that has the character ids.

It matters because it's the **only identifier on a character that means anything off-site**. `user_id` is internal to your Nova install, and `user_name` collides the moment two players share a display name. The snapshot manifest carried the Discord id already, but it lists active and NPC characters only and has no character ids at all — so anything consuming it had to match on normalised display name, which quietly mis-assigns duplicates and loses ownership completely for a player whose characters are all inactive.

`player` is on every character, inactive and pending included.

## The rank ladder, including the rungs nobody wears

New **`GET /ranks`** returns your whole ladder in seniority order, with **absolute** artwork URLs.

Until now rank data reached the API only as three fields copied onto each character, plus an image buried in the snapshot — which between them describe only the ranks somebody is wearing *today*. Anything mirroring your sim had nothing to promote a character **into**.

Rank art is generated sim-side and handed over as a finished `https://` URL, because the real path depends on your genre, your chosen rank set and a catalogue column no outside consumer can see. Where that can't be resolved — most often a rank set belonging to a different genre — you get `null` rather than a URL that would 404.

## Departments, manifests, and the positions people actually hold

- **`GET /departments`** — every department, with descriptions, ordering, display flags and manifest assignment. Child departments included. Previously a department with nobody in it was invisible to the API entirely, and its description and ordering were unavailable at any size.
- **`GET /manifests`** — a sim can have several (crew, support craft, NPCs), and this is what lets a department be attached to the right one.
- **`GET /positions?open=any`** — every position regardless of whether it has a vacancy. The default is unchanged and still returns open positions only, exactly as the join page shows them; `open=any` is the new opt-in. Position rows also gained a `display` flag.

That last one had a real hole in it: the endpoint could only ever return positions with an unfilled slot, which is right for a join page and wrong for anything trying to describe a crew — the **filled** positions are the ones people actually hold.

## Smaller additions

| Endpoint | New |
|---|---|
| `GET /characters` | `images[]` (absolute URLs, first is the avatar), `middle_name`, `date_activate`, `date_deactivate` |
| `GET /missions` | `images[]` |
| `GET /posts`, `/posts/{id}` | `tags`, `participants[]` |

`tags` is a bug fix as much as an addition: tags have been accepted on write — and folded into `content_hash` — since post authoring shipped, but were never returned, so a tag written through this API couldn't be read back by anything.

⚠️ `participants[]` holds **user** ids, not character ids. It's the one id list on a post row that isn't character-keyed, so each entry is an explicit `{user_id, name}` object rather than a bare number. Nova only maintains it when your sim has *Post Participants* switched on, so `[]` is the normal answer.

## Every image is now an absolute URL

`Character.images`, `Mission.images`, `Rank.image_url` and the snapshot's existing `avatar_url` / `rank.image` all follow one rule: an absolute `https://` URL, or `null`. Never a bare filename, never `""`, never a site-relative path. List fields are always an array — `[]` when empty, never `null`.

This is now one shared implementation rather than two, so the snapshot and the API can't drift on what a URL looks like.

## Pages can be five times bigger

`per_page` is capped at **500**, up from 100.

**This is the one change here that isn't purely additive.** No response shape changed, but a client already sending `per_page=500` used to receive 100 rows and page for the rest — now it receives 500 in a single response. If yours sizes a buffer, a timeout or a memory budget from the page it gets back, check it before upgrading. `GET /posts` with bodies is the largest response this API can produce.

Everything else is unaffected: the default page size is still 25, and a client that doesn't ask for more won't notice.

## Upgrade

Use the **Update Now** button on the dashboard, or `POST /Api/suite`. No database changes, no configuration changes.

The new endpoints reuse existing scopes, so **tokens already granted don't need re-minting**: `positions:read` now also covers departments and manifests, and `characters:read` also covers the rank ladder.

## Credits

MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
