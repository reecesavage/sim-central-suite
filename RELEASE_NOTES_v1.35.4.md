# Sim Central Suite v1.35.4 — mobile editor keeps headings, rules & lists

Stops the mobile editor from wiping structural formatting when a post is edited.

## What's fixed

Editing a post (or personal log) on the **mobile site** used to flatten any richer formatting the post already had. Headings collapsed to plain text, horizontal rules (`<hr>`) vanished, block quotes and bullet/numbered lists were stripped — so opening a nicely formatted, desktop-written post in the mobile editor and saving silently destroyed its layout.

The mobile editor now **preserves** these when you save:

- Headings (`H1`–`H6`)
- Horizontal rules
- Block quotes
- Bulleted and numbered lists

…alongside the bold / italic / underline it already kept. Nova's post display already renders all of these, so mobile edits now leave them intact instead of erasing them.

## Also fixed

- **`<` in your writing is no longer eaten.** Text like `I <3 you` or `we leave in <20 minutes` used to disappear from the point of the `<` when a post was opened in the mobile editor. It's now kept exactly as written.

## Safer, too

Mobile saves now go through a strict allow-list: only the tags above survive, every tag attribute is dropped, and anything scriptable (`<script>`, `<style>`, `on…=` handlers, `javascript:` links, `<img onerror>`, etc.) is removed before storage — not just cleaned at display time.

## Known limitations

- **Links, images, and tables are still flattened** on a mobile save — they're outside the preserved set on purpose. If you edit a link-heavy post on mobile, the link text stays but the links themselves are dropped. Preserved: headings, rules, block quotes, lists, and bold/italic/underline.
- This changes what a **mobile save** writes going forward; it doesn't touch posts you don't edit, and there's no bulk change to existing content.

## Upgrade

Use the **Update Now** button on the dashboard, or `POST /Api/suite`. No database changes, no configuration changes.

## Credits

MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
