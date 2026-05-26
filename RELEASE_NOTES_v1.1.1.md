# Sim Central Suite v1.1.1 — Date + time format options

Two new dropdowns on the **Ordered Mission Posts** config page control how the timeline string is rendered everywhere it shows up.

## What's new

- **Date format dropdown.** Choose from `YYYY-MM-DD`, `YYYY/MM/DD`, `MM/DD/YYYY`, or `DD/MM/YYYY`. Affects the date portion of the timeline string on the public mission view, post view, RSS feed, post-notification emails, and the mission posts list.
- **Time format dropdown.** Choose between 24-hour (e.g. `23:00`) and 12-hour with AM/PM (e.g. `11:00 PM`). Affects every place a stored time is rendered.
- **No data migration.** Database storage is unchanged — dates stay ISO `YYYY-MM-DD`, times stay `HHmm`. Only the rendered output changes. Switch back at any time without losing anything.

## Defaults

- **Date**: `YYYY-MM-DD` — identical to what was rendered before this release, so existing sims see no visual change unless they explicitly choose a different format.
- **Time**: `24h` (e.g. `23:00`) — slightly different from the previous raw `2300` display. The 24-hour-with-colon form is the only choice in this release that matches the user-facing format conventions; if your sim relied on the no-colon `2300` form for any external integrations, this is the one thing to notice on upgrade.

## Implementation notes

- New `libraries/TimelineFormat.php` with a single `buildLine()` helper that every display point calls. Adding a new format choice in the future is a matter of editing the two `Choices()` methods and the two `format*()` switches; no other file needs to change.
- Stardate and Mission Day values pass through unformatted — they aren't dates. Only the time portion of those modes is reformatted.
- POST validation on the config form rejects unknown format keys, so a hand-edited form can't park a junk value in the settings row.
- Format setting lives in the same `sim_central_state` row as every other user-modified setting (added to v1.0.0), so it carries forward across updater runs automatically.

## Upgrade

Use the **Update Now** button on the dashboard (shipped in v1.1.0). The new dropdowns appear on the *Ordered Mission Posts → Configure* page after reload — both pre-set to the defaults above. No further action required.

Manual upgrade still works the same way as before: drop in over the existing install.

## Credits

Same as v1.0.x / v1.1.0. MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
