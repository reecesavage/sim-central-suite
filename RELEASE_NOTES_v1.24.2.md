# Sim Central Suite v1.24.2 — Readable tables on dark skins

## Fix

- **Tables in the suite's admin pages are now readable on dark skins (Titan etc.).** v1.24.1 gave the
  suite pages a light panel, but tables kept their skin styling: dark skins paint table rows a dark
  colour (`tr.alt td` / `tr.light_gray`), so the cell contents (parameter rows in the API Explorer,
  the token list on the REST API page, the configured-webhooks list) stayed dark-on-dark. Table cell
  backgrounds are now neutralised to sit on the light panel with a subtle zebra stripe, and cell text
  is pinned dark at a specificity that beats the skin's table rules. Inline colours (like the green
  "active" / "enabled" status) are preserved. Verified against a Titan-style dark table.

## Upgrade

Use the **Update Now** button on the dashboard, or `POST /Api/suite`. No database changes.

## Credits

MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
