# Sim Central Suite v1.31.1 — Astrolabe snapshot: `top` flag on open positions, version-aware cache

Follow-up to v1.31.0's `open_positions`. Additive; `version` stays `1`.

## What's new

### `open_positions[].top`

Each open position now carries a **`top`** boolean — `true` when the position is on the sim's "top open positions" (featured) list, `false` otherwise. Astrolabe uses it to mark featured billets.

The snapshot already exports **all** open positions (every displayed position with `pos_open > 0`, not just the featured ones), and always includes the `open_positions` key (`[]` when nothing is open). `top` just distinguishes the featured ones.

### Version-aware snapshot cache

The snapshot cache is now invalidated automatically when the suite version changes. Previously, after updating the suite, the endpoint could serve the pre-update snapshot for up to the cache TTL (~10 minutes). Now a version change forces a rebuild on the next request, so new fields (like `open_positions`) show up immediately after an update — no waiting, no manual cache clear.

## Note for anyone who "updated but doesn't see the change"

If a sim's snapshot is missing a field the release added, the sim is almost certainly still running the **old extension build** — a GitHub release alone doesn't update a sim. Update the extension on the sim itself (dashboard **Update Now**, or push it), then re-fetch. As of this release, the version-aware cache means you won't also have to wait out the TTL.

## Upgrade

Use the **Update Now** button on the dashboard, or `POST /Api/suite`. No database changes.

## Credits

MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
