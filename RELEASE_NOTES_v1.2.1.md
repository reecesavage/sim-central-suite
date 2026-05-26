# Sim Central Suite v1.2.1 — Manual update recheck

Small follow-up to v1.2.0 adding an on-demand way to refresh the update check.

## What's new

- **Check now button** next to the version line on the dashboard. Bypasses the 24-hour cache and immediately hits the GitHub Releases API.
- **"Last checked X ago" indicator** next to the version line so you know how stale the cached value is before you decide whether to recheck.

Useful when you've just published a new release and want the *Update available* banner to appear without waiting up to a day for the regular cache to expire.

## Why not just remove the 24h cache?

GitHub rate-limits unauthenticated API requests to **60 per hour per IP**. The 24-hour cache keeps the dashboard well under that on shared-IP hosts (one check per sim per day). The manual button is opt-in &mdash; you pay for the request only when you actively click it.

## Behaviour

- The button always works, regardless of the current status (up-to-date, update-available, or never-checked).
- A failed check (network error, GitHub down, rate-limited) flashes the same "refreshed" message but the banner stays unchanged &mdash; matches the existing fail-silent behaviour of the automatic check.

## Upgrade

Use the **Update Now** button on the dashboard. The new button appears next to the version line after reload.

## Credits

Same as v1.2.0. MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
