# Sim Central Suite v1.35.2 — repository housekeeping

Removes stray files that were committed by accident. **No code changes** — nothing in the suite's behaviour, API, or configuration differs from v1.35.1.

## What changed

- Deleted a stray zero-byte file named `"` from the extension root, committed accidentally in v1.34.0.
- Stopped tracking `.DS_Store` and `views/.DS_Store` — macOS Finder metadata with no bearing on the extension. They're removed from the repository but left alone on your own machine.
- Added a `.gitignore` covering `.DS_Store` and the usual editor/OS cruft, so they don't come back.

## Do I need this?

Only if you care about a tidy install directory. There is no functional difference from v1.35.1 — if you're already on that version, upgrading is optional.

## Upgrade

Use the **Update Now** button on the dashboard, or `POST /Api/suite`. No database changes, no configuration changes.

## Credits

MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
