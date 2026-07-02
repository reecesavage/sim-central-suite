# Sim Central Suite v1.25.1 — Injected page blocks now work on every skin (no more jQuery dependency)

Fix release for the suite's injected UI on public pages, prompted by the join form on LCARS-family skins.

## Fixes

- **Injected blocks appear on ALL skins now.** The suite places its UI (the join form's "Link Discord" card, the login page's Discord button placement, the anti-spam question, the display-name field, the admin update-notice row, and every other injected block) with a small emitted script. That script was **jQuery-based** — and on skins that don't load jQuery on their public pages (LCARS among them), it silently did nothing. On an affected skin with *Require linking Discord to join* on, that was nasty: the "Link Discord" card never rendered, so applicants had no way to link, and the server-side gate bounced their submit with no visible explanation — the form just seemed to do nothing. The emitted script is now **plain vanilla JavaScript with zero dependencies**, so placement works identically on every skin. It also waits for the DOM to be ready when needed, executes any inline guards the blocks carry, and handles table-row insertion correctly.
- **The join form now shows the gate message.** When the server-side "Discord linking is required" gate bounces a submit back to the join form, the reason now renders at the top of the Link Discord card instead of being silently swallowed.
- **Readable on dark skins.** The join form's Link Discord card sets explicit dark text on its light background, so skins with light body text (Titan etc.) no longer render it light-on-light. Same lesson as the v1.24.1 admin-panel fix: content on a light surface must bring its own text colour.

## Upgrade

Use the **Update Now** button on the dashboard, or `POST /Api/suite`. No database changes. No configuration changes.

If your sim runs a skin where the join form's Link Discord card (or the anti-spam question) never appeared, it will simply start appearing after this update.

## Credits

MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
