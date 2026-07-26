# Sim Central Suite v1.37.1 — the mobile editor stops adding blank lines

Opening a post in the mobile editor and saving it added a blank line to every paragraph gap in the post — including gaps in text the save never touched. Rows typed fresh in the mobile editor were unaffected, which is why it looked like the editor was damaging *old* content specifically.

## What was happening

Nova's own desktop composer is a `<textarea>`, and HTML form submission stores every submitted value with **CRLF** line endings. So any post written or edited on the desktop site is stored with `\r\n` at each break — the mobile editor's round trip matched `\n` alone.

That single blind spot cost a line break twice per save:

- `storedToEditorHtml()` replaced the `\n` with `<br>` and left the `\r` sitting in the editor HTML. The browser read that stray carriage return back as a newline and posted it as a **fresh** CRLF.
- `editorHtmlToStored()`'s "collapse 3+ newlines to 2" rule could never fire, because the surviving `\r` split every run of newlines.

So a paragraph gap stored as `\r\n\r\n` came back as `\r\n\n\r\n\n` — two line breaks where the author typed one. Rendered, one blank line became three.

| Gap the author typed | Stored before | After one mobile save (old) | After one mobile save (now) |
|---|---|---|---|
| Line break, desktop-written | `\r\n` | `\r\n\n` — gains a blank line | `\n` — unchanged |
| Blank line, desktop-written | `\r\n\r\n` | `\r\n\n\r\n\n` — gains two | `\n\n` — unchanged |
| Anything typed on mobile | `\n` | `\n` — already correct | `\n` |

## The fix

`PostWrite::editorHtmlToStored()` now folds `\r\n` and bare `\r` to `\n` before anything else runs, so the whole round trip is carriage-return-free and every rule downstream sees the line breaks it was written to match. Because `storedToEditorHtml()` normalises through that same method, one change covers both directions.

**Posts already affected repair themselves.** Open one in the mobile editor and the corrected spacing is what you see; save it and that is what's stored. Nothing else needs doing, and no post has to be re-saved to keep working — the extra blank lines are cosmetic.

Line endings in stored content are now `\n` throughout after a mobile save. Nothing renders differently: Nova's display pipeline (`nl2br`) already counted `\r\n` and `\n` as one break each.

## Verified

- The reported sequence, driven through a real browser form POST rather than a simulation: a desktop-written body holds at four line breaks across repeated saves, where it previously went to eight on the first one and stayed there.
- 6,000 generated bodies mixing CRLF, LF, bare CR, block markup, entities and bare `<`: carriage returns surviving into storage went from **1,731 to 0**, and round trips that were not a fixed point went from **1,698 to 659**.
- 657 of those 659 fail identically on v1.37.0 — a separate, pre-existing case where a literal HTML tag name typed in prose (`Use the <p> tag`) is read as markup. Untouched here.
- The other 2 are bodies whose heading or list item ends with a blank line. The existing "a `<br>` filling the end of a block is padding, not a break" rule now trims it; on v1.37.0 the stray `\r` re-added one every save, so the two faults cancelled and it looked settled. Worst case, any body settles in two saves; none fails to settle.
- Text preservation is unchanged: 642 bodies alter their text under v1.37.0 and the same 642 under this release.

## Upgrade

Use the **Update Now** button on the dashboard, or `POST /Api/suite`. No database changes, no configuration changes.

## Credits

MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
