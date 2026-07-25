# Sim Central Suite v1.37.0 — one flattener, everywhere

v1.36.1 fixed how the suite turns rich text into plain text, but only for the Astrolabe snapshot and the REST API's `excerpt`. Three other places still used `strip_tags`: the **word count**, the **Discord webhook body**, and the **mobile mission and tour blurbs**. All three carried the same bugs. They now share the one implementation.

## ⚠️ Your word counts will change — that is the fix

Post and mission word counts move on every sim: in the REST API, on Manage Missions in the ACP, and on the public `/sim/missions` page. They move in **both directions**, because `str_word_count(strip_tags($content))` was wrong in four different ways at once:

| What was wrong | Effect on the count |
|---|---|
| A pasted `<style>` block's CSS counted as prose — `strip_tags` removes the tag but keeps its contents | **Falls sharply.** The most noticeable change, and it hits anything pasted from Word or Google Docs |
| `&nbsp;` counted as the word "nbsp"; so did `&amp;`, `&quot;`, `&mdash;` | Falls |
| A block boundary deleted the tag and fused the words either side | Rises |
| A bare `<` in prose — `the fee is <20 credits` — made `strip_tags` eat to the next `>` **or to the end of the body** | **Rises, by however much of the post was being silently discarded** |

Plain prose and ordinary `<p>`-per-paragraph posts that end their paragraphs in punctuation don't move at all. Everything else depends on what the body actually contains, so the net direction for any one post isn't predictable in advance — but every individual change is toward the truth.

**If you reimplemented the old formula** to cross-check our number, stop: read the field instead. `str_word_count(strip_tags($content))` was published in `REST_API.md`, `ASTROLABE.md` and the OpenAPI spec as *the* definition, and it no longer is.

## Discord embeds

The embed body had every bug the excerpt used to have, and one more of its own.

- **A bare `<` truncated the message.** A post reading *"Fuel reserves are &lt;20 percent," Kade said.* delivered **18 characters** and dropped the rest of the post.
- **Headings, `<div>`s and list items fused** — only the exact `</p><p>` pair was handled, so `<h2>TROUBLESHOOTERS</h2><p>September…` arrived as `TROUBLESHOOTERSSeptember…`.
- **A stray `<style>` block put raw CSS in your channel.**
- **Markup stored escaped reached readers as literal `<p>` tags.**

Paragraph structure is preserved exactly as before — `<br>` is still a line break, two `<br>`s are still a paragraph, and the smart truncation still prefers to cut at a paragraph boundary. It now finds one more often, because posts structured with headings or `<div>`s previously had no paragraph breaks at all.

**A silent failure this also fixes:** a post body stored in Windows-1252 rather than UTF-8 made `json_encode` fail, and the webhook **never fired at all** — no error, no message, nothing in the channel. Those deliveries now go through.

## Mobile blurbs

The mission-list and tour-list blurbs escaped the text *before* truncating it, then measured the unescaped string to decide whether to add an ellipsis. Three consequences, all fixed by flattening and capping first and escaping last:

- A cut could land **inside an HTML entity**, leaving a stray `&` before the ellipsis.
- A blurb could be **silently cut with no ellipsis** when escaping pushed it past the limit.
- A stored `&amp;` was **escaped twice** and rendered as `&amp;` to the reader.

Blurbs are now at most one character shorter (the ellipsis counts inside the 120-character budget rather than being appended outside it), and a description with no readable text no longer renders an empty blurb.

## Also

- **`GET /posts` excerpts are unchanged.** The flattener gained an optional separator argument for Discord's benefit; every existing caller uses the default and produces byte-identical output. Verified against the previous implementation over a fixed table plus 4,000 generated bodies — zero differences.
- **A truncation artefact is gone.** For bodies over 16 KB, the excerpt's input bound could cut through an escaped tag and leave a fragment like `<img src=x onerror=…` in the output. The bound now ends on a boundary that can't be mid-tag. Present since v1.36.1; found by re-running the hostile-input sweep.

## Upgrade

Use the **Update Now** button on the dashboard, or `POST /Api/suite`. No database changes, no configuration changes.

## Credits

MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
