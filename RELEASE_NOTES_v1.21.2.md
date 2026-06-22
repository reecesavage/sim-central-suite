# Sim Central Suite v1.21.2 — Mobile editor focus fix + updater API hardening

## Fixes

- **Mobile editor: clicking into the text area no longer bounces focus out.** On the mobile
  site's new-post and new-log forms, the rich-text editor was wrapped in a `<label>`. Because a
  label forwards clicks to its first form control, clicking into the editor was redirected to the
  **Bold** toolbar button — stealing focus from the editor and jumping the page (you had to press
  **Tab** to get the caret in). The editor now sits under a plain section heading, like the
  *Authors* block, so a direct click focuses it as expected.

  This was browser-dependent (some mobile browsers tolerated it, others didn't), which is why it
  reproduced for some users and not others. Affects both the **post** and **personal log** editors.

- **Suite-update API hardened.** The remote-upgrade endpoint now routes purely by HTTP method —
  **`GET /suite`** for status, **`POST /suite`** to upgrade — instead of relying on a URL path
  segment. The old `POST /suite/update` still works, but the preferred `POST /suite` avoids two
  host-specific gotchas: URL-rewriting differences that could mis-route the status call, and
  mod_security rules that block the literal word "update" in a path before it reaches the app.

## Upgrade

Use the **Update Now** button on the dashboard. No database changes.

## Credits

MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
