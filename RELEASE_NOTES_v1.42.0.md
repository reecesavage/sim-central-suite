# Sim Central Suite v1.42.0 — the rest of the archive

v1.41.0 opened up what a sim *is* — its departments, ranks, manifests and positions. This release opens up the rest of what it **wrote**: the bio form behind every character, the arcs its missions belong to, its news, its awards, its ship specs and deck tour, and the drafts nobody could reach.

Together the two releases mean a sim can be read out whole. That matters most for a sim that's closing: everything below was, until now, data that would simply have gone with it.

Everything here is additive. Two items reach content the sim shows nobody, and both sit behind a new sysadmin-only scope — see *Two privileged additions*.

## The bio form, not just the answers

`GET /characters/{id}` has returned a character's bio since v1.38.0. What it couldn't tell you was the **form**: reconstructing that meant one request per character, and the union of those still can't tell "empty for this character" apart from "doesn't exist" — nor does it ever give you field order, help text, or a select's option list.

New **`GET /characters/bio-fields`** returns the form itself, in the order the bio page renders it, including fields nobody has ever filled in and (unlike a character's bio) the ones the sim has hidden. Select fields carry their options, and an option's `value` is exactly what turns up in a character's `bio[].value`.

And **`GET /characters?include=bio`** attaches every character's bio to the list. It's opt-in because the payload is much larger — but it is *not* slow: the whole page's bio values are read in **one** query, not one per character. A supporting index on `characters_data` ships with it, since Nova has none on the column this looks up.

## Arcs, news, awards, specs, tour

- **`GET /missions/groups`** — Nova's mission groups are story arcs. Without them a ten-year sim's sixty missions arrive as one flat list. Missions gained a `group_id` to attach them.
- **`GET /news`** — nothing served news history before now; the only code that touched the table fired a webhook. Unpublished items are reachable with `?status=any` so they can be imported *as* unpublished rather than dropped, and each item reports whether the sim showed it to members or to visitors.
- **`GET /awards`** and **`GET /awards/received`** — the catalogue and the record of who was given what, with reason and date. Pending and rejected nominations stay private; that's moderation state, not a record of an award.
- **`GET /specs`** and **`GET /tour`** — ship specifications and the deck tour, flattened out of Nova's internal field/value tables into plain label-and-value pairs. Nova pre-creates a row per field per item, so empty ones are left out entirely rather than returned as blanks.

Two things about the tour are worth knowing before you build against it, because both are how Nova actually stores them rather than choices made here: **decks belong to a ship**, so they come back with `GET /specs` rather than `GET /tour`; and **tour fields have no sections**, so they arrive as one flat ordered list.

## Two privileged additions

Both of these reach content the sim itself shows nobody, so both sit behind a new scope that only works on a **sysadmin-bound token** — the same rule `posts:read.all` has always used. The scope is the boundary, the sysadmin flag is the permission.

**`logs:read.all`** lets `?status` reach unpublished personal logs. v1.39.0 refused this deliberately, on the grounds that a draft is one writer's private work and there was no `own`/`all` tier to earn access with. This adds the missing tier rather than widening `logs:read`, which still sees activated logs only no matter what it asks for. A sim owner exporting their own sim clears the bar; a third-party integration doesn't.

**`missions:read.all`** adds a mission's GM-only writing notes. Those render in the members' writing area and the admin panel, behind a permission, and on no public page — so putting them on the public `missions:read` tier would have made it the first endpoint here to hand out something the sim doesn't show its own visitors.

Neither is part of the Sim Central grant, so no upgrade quietly widens an existing token into them. They have to be minted on purpose.

## Upgrade

Use the **Update Now** button on the dashboard, or `POST /Api/suite`.

**Run *Setup database*** afterwards if you plan to use `?include=bio` — it adds one index to `characters_data`. Nothing else needs it, and re-running is a no-op.

`news:read`, `awards:read` and `specs:read` are added to the Sim Central grant, so already-granted sims pick them up on upgrade without a re-grant. The bio form and mission groups reuse `characters:read` and `missions:read`.

## Credits

MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
