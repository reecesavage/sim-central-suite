# Sim Central Suite v1.31.0 — Astrolabe snapshot: open positions + full entity decoding

Additive update to the Astrolabe snapshot (`GET /snapshot`). Both changes are backward-compatible; `version` stays `1`.

## What's new

### `open_positions` in the snapshot

The snapshot gains a top-level **`open_positions`** array — the positions the sim is actively recruiting for (Nova's open-positions concept: `pos_open > 0` on displayed positions, the same set shown on the join page). Each entry:

```json
{ "name": "Chief Engineer", "department": "Engineering", "openings": 1,
  "description": "Keeps the warp core humming.", "url": "https://…/main/join" }
```

Filled positions are omitted; `openings` is always ≥ 1; department labels match the manifest's where they share a department; `description` is plain text (≤ 300 chars); `url` points at the join page (absolute https). Empty array when the sim has no open positions.

### All human-readable strings are entity-decoded

Previously only `description` / `excerpt` were entity-decoded. Now **every** human-readable string in the snapshot is — manifest and department names, character names, positions, ranks, story and post titles, player names, and the new open-positions fields. So a department literally named `Security &amp; Tactical` in the database now arrives as `Security & Tactical` instead of double-escaping on display.

## Compatibility

`version` remains `1` — these are optional additions. Older Astrolabe integrations ignore the new key; there's nothing to change on your side beyond consuming `open_positions` when you want it.

## Upgrade

Use the **Update Now** button on the dashboard, or `POST /Api/suite`. No database changes. The [`ASTROLABE.md`](ASTROLABE.md) handoff doc is updated with the new field and the widened decoding guarantee.

## Credits

MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
