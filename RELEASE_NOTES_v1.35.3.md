# Sim Central Suite v1.35.3 — mobile editor line-spacing fix

Fixes extra blank lines appearing in posts edited on the mobile site.

## What's fixed

Editing an existing mission post (or personal log) on the **mobile website** added an extra blank line to the text that was already there. Newly typed text looked right at first, then gained the extra line the next time the post was edited — the spacing crept wider with each edit.

The cause was in the mobile editor's save step. When a mobile browser re-serialized an edited line, it wrapped the line in a block element **and** left a trailing "filler" line break inside it (e.g. `<div>your line<br></div>`). The save routine counted **both** the block boundary and that filler break as separate line breaks, so one visual line was stored as two — and Nova's display then rendered the extra blank line. The save routine now ignores a filler break that only sits at the end of a block, so each line stores as exactly one break.

Intentional blank lines between paragraphs are preserved, and the desktop editor and REST API were never affected.

## A note on posts already affected

Posts that were doubled **before** this fix keep their current spacing — the extra blank lines are stored in a way that can't be told apart from deliberate paragraph breaks, so there's no safe automatic cleanup. To tidy an affected post, open it in the editor and delete the extra blank lines by hand; from then on the spacing stays correct.

## Upgrade

Use the **Update Now** button on the dashboard, or `POST /Api/suite`. No database changes, no configuration changes.

## Credits

MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
