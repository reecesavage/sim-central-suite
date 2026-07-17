# Sim Central Suite v1.33.0 — Sim Central grant gains `tokens:write` (Astrolabe write-back)

One deliberate, clearly-flagged change: the **Grant Sim Central access** token now carries **`tokens:write`**.

## What it enables

This is the write-back trust upgrade previewed in v1.32.0. With `tokens:write`, Astrolabe can **mint narrow per-member posting tokens** on your sim — each scoped to `posts:read.own` + `posts:write` and bound to one member (matched by their linked Discord account) — so your players can write and save Nova posts **from Astrolabe** with native attribution, edit locking, and per-user moderation, exactly as if they'd used the sim's own write page.

## What it does NOT change

- The granted token itself still **cannot author posts** — it carries no post-write scopes. It can only mint tokens.
- Minting only works while the granting admin's account is a **sysadmin** (every `tokens:*` call is sysadmin-gated at request time).
- The per-member tokens it mints are visible in your token list like any other — inspect, revoke, or delete them individually.
- **Revoke Sim Central access** still cuts everything off in one click.

The grant panel on *REST API → Configure* now states all of this next to the scope list.

## Rollout

Nothing to do beyond updating: on the first request after the update, post-update housekeeping widens the existing granted token's scopes to the new set and re-registers with the registry (event `updated`), which forwards to Astrolabe — so Astrolabe's Write tab lights up on its own. No re-grant, no token paste.

## Upgrade

Use the **Update Now** button on the dashboard, or `POST /Api/suite`. No database changes.

## Credits

MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
