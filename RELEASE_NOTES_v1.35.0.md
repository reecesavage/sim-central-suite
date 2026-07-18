# Sim Central Suite v1.35.0 — last saver on post API objects

Additive fields for Astrolabe's "whose turn is it" indicator on drafts.

## What's new

Every post returned by `GET /posts` and `GET /posts/{id}` now carries:

- **`saved_user_id`** — the user who owns the character recorded as the post's last saver (Nova's `post_saved`). This is the value a consumer compares against a writer's own user id: someone else's id means "your turn", your own means "waiting on others". `null` when unknown or the saving character is unowned.
- **`saved_character_id`** — the raw `post_saved` character id, `null` when unset.
- **`saved_user_name`** — that user's public display name (same value/visibility rule as `Character.user_name` and the snapshot's `player.name`), for hover text.

The user behind `saved_user_id` always matches the webhook `actor`'s user: the webhook's saved-by normalisation only ever swaps *which of that same user's characters* is displayed, never the user. Saves through the API (`PATCH /posts/{id}`) record the acting character, so the field updates to the API writer immediately.

All three appear in the API Explorer and OpenAPI spec. Lookups are per-request memoized primary-key reads — negligible cost even on full pages.

## Upgrade

Use the **Update Now** button on the dashboard, or `POST /Api/suite`. No database changes, no configuration changes.

## Credits

MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
