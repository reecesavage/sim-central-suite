# Sim Central Suite v1.32.0 — Astrolabe delta v3: grant scopes, player Discord IDs, discord_id token binding, post.updated

Four additive changes for the Astrolabe integration. Snapshot `version` stays `1`; nothing breaks existing consumers.

## What's new

### The Sim Central grant now serves Astrolabe too

The one-button **Grant Sim Central access** token now also carries **`astrolabe:read`** and **`positions:read`** — so the single granted token serves Astrolabe's snapshot poller and open-positions sync, with no separate token to paste. (`tokens:write` is deliberately NOT included yet; it will arrive, clearly flagged, only when Astrolabe's write-in-Nova feature ships.)

**Already granted?** Nothing to do: on the sim's first request after this update, the post-update housekeeping widens the existing granted token's scopes to the new set and re-registers with the registry (event `updated`), so Sim Central's record stays accurate. The one-time dashboard summary notes when this happened.

### Snapshot: `player.discord_id`

Each manifest character's `player` object gains **`discord_id`** — the player's linked public Discord ID when the sim runs Discord Sign-In and the player linked their account, else `null` (feature off, unlinked, or no owning user). Same visibility rule the event webhooks already use for @mentions. Astrolabe matches it to its own Discord-authed users so "played by" can link to the player's Astrolabe profile.

### `POST /tokens`: bind by `discord_id`

Token creation accepts **`discord_id`** as an alternative to `user_id` — resolve-and-bind to the user linked to that Discord account. Mutually exclusive with `user_id` (`422` if both are sent); `409` when the Discord Auth feature is off or no user has linked that ID (mirroring `POST /users/disable`). Omitting both still creates an unbound token, exactly as before. Sysadmin-gated `tokens:write` flow only.

### New webhook event: `post.updated`

Fires when an already-**activated** post is edited and re-saved **without** a status change — the gap between `post.posted` (the activation transition) and `post.saved` (drafts). Built for machine sync (e.g. Astrolabe refreshing its mirrored copy):

- **Generic** payload is identical in shape to `post.posted`, with `event: "post.updated"`.
- **Discord** format renders the same embed as `post.posted` but has its **own role-ping opt-in** — and since event subscriptions are per-webhook checkboxes, announcement channels simply leave it unchecked.
- No shim changes needed — the existing model shim already reports every save; subscribe via the new checkbox on the webhook form (ACP or API).

## Companion change (sim-central-registry, deployed separately)

The registry worker now forwards registrations (grant / update / revoke / delete) to Astrolabe's `POST /api/sim-central/registrations` receiver with a dedicated `X-SC-Secret` — best-effort, so Astrolabe being down never fails a grant. The grant forward includes the token so a sim shows up in Astrolabe ready to attach, no copy-paste. It also accepts the new `updated` registration event. (Note for the Astrolabe team: this lives in the **registry** worker at `registry.simcentral.host`, not the Discord OAuth broker.)

## Upgrade

Use the **Update Now** button on the dashboard, or `POST /Api/suite`. No database changes; the granted-token scope sync runs automatically via post-update housekeeping.

## Credits

MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
