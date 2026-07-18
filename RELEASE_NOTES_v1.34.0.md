# Sim Central Suite v1.34.0 — `user_name` on character API objects

One additive field, requested by Astrolabe's write-in-Nova author picker.

## What's new

Every character returned by `GET /characters` and `GET /characters/{id}` now carries **`user_name`** — the linked Nova user's **public display name**. Exactly the same value and visibility rule as the Astrolabe snapshot's `player.name` (entity-decoded; never email or account internals). `null` when the character is unowned/unlinked (`user_id` null).

Astrolabe uses it to label its author-picker groups — "Character (Player)" chips, and showing who a linked NPC belongs to. Any other consumer gets it for free; it's in the API Explorer and OpenAPI spec.

## Upgrade

Use the **Update Now** button on the dashboard, or `POST /Api/suite`. No database changes, no configuration changes.

## Credits

MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
