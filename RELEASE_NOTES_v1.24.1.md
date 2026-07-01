# Sim Central Suite v1.24.1 — Readable suite pages on dark skins (Titan etc.)

## Fix

- **Suite admin pages are now readable on dark skins like Titan.** The v1.21.5 readability pass
  forced the suite's text dark but left its container transparent, so on a dark skin (dark background,
  light text) everything that wasn't inside a light info box went **dark-on-dark and invisible** (the
  page title, intros, endpoint descriptions, etc.). The suite content now renders inside its own light
  **panel** (light background + dark text + padding), so every bit of suite text sits on a light
  surface and reads cleanly under any skin, light or dark. Verified against a Titan-style dark
  background.
- **Coverage extended:** the readability panel now wraps all the suite's own admin pages, not just the
  REST API / API Explorer / Webhooks / Discord / Content Filter / Mobile pages. Summary, URL Parser
  (and its add/edit tag pages), Anti Spam Questions (and its add/edit pages), Ordered Mission Posts,
  and Display Name config pages are included too. Nova's own pages are untouched.

## Upgrade

Use the **Update Now** button on the dashboard, or `POST /Api/suite`. No database changes.

## Credits

MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
