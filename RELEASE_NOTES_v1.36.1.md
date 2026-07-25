# Sim Central Suite v1.36.1 — plain text that reads like plain text

Fixes how the suite flattens rich text into the plain-text fields it publishes: story descriptions, position descriptions, and post excerpts, in both the Astrolabe snapshot and the REST API.

No new fields, no new parameters, no shape change. Existing values get better.

## What was wrong

`strip_tags()` deletes a tag without leaving anything in its place, and a block boundary is a word boundary. So a mission description written as a heading and two paragraphs arrived like this:

```
STORY ONE: ASSEMBLING THE TROUBLESHOOTERSSeptember - November 1877By the fall of 1877, …
```

`TROUBLESHOOTERS` + `September` and `1877` + `By` are each two sides of a deleted `</h2>` / `</p>`. It also ate the 300-character budget, because the cap was being spent on a run-on string.

Three more faults in the same function, all of them things a reader saw:

**A bare `<` in prose destroyed the rest of the text.** `strip_tags` treats `<` as a tag opener followed by *anything*, and eats to the next `>` — or to the end of the string if there isn't one. A description reading *"The fee is <20 credits"* lost every character from `<20` onward. In testing, 184 of 194 characters vanished silently.

**A non-ASCII excerpt could break the entire response.** Truncation had a byte-length branch that fired for any text whose UTF-8 encoding was longer than its character count — i.e. all non-ASCII — and cut mid-sequence. A 210-character Japanese description at the 300-character cap came back as 101 characters of invalid UTF-8, and `json_encode()` then failed for the **whole payload**: the snapshot degraded to `{"error":"encode_failed"}`, and a REST list returned an empty body. Not one bad field — the whole response.

**Malformed input blanked the field.** `preg_replace` with a `/u` pattern returns null on invalid UTF-8, and there was no guard, so a Latin-1 `"Café latte"` produced `null` instead of text.

**Stray `<script>` or `<style>` leaked their source.** `strip_tags` removes a tag but keeps its contents, so CSS or JavaScript could appear as prose in a public excerpt.

## What changed

Flattening moved into one shared library, `PostText`, and now:

- **Block-level tags become a single space.** `p`, `div`, `br`, `hr`, `h1`–`h6`, `li`, `ul`, `ol`, `dl`/`dt`/`dd`, table rows and cells, `blockquote`, `pre`, the HTML5 sectioning elements, and the legacy ones that dominate older sim content — **`center` above all**, which is how pre-HTML5 editors centred chapter headers and scene breaks. Matched on the full tag name, so `<preamble>`, `<header>` vs `<head>`, and custom elements like `<p-tag>` aren't mistaken for block tags.
- **Inline tags still become nothing.** `<em>un</em><em>breakable</em>` stays `unbreakable`.
- **A `<` only opens a tag when a letter or `/` follows it** — the HTML5 tokenizer's own rule, the same one the mobile editor already applies to saves. `I <3 you`, `5 <= 6`, `a < b`, and `in <20 minutes` now survive as the prose they are.
- **Entities decode before tags are removed**, so markup stored escaped (`&lt;p&gt;`) is removed as the markup it is instead of surfacing to a reader as a literal `<p>`. Tags are removed in **two passes with different trust**: the first runs on the stored string, where anything tag-shaped is markup an editor emitted; the second runs after decoding and removes **only real HTML element names**, because a `<` there was typed by a human. That's what keeps `Fire if enemy&lt;shields and range&gt;two` and `the manifest uses Map&lt;name,rank&gt;` intact — decoding without that restriction would have reintroduced the data loss above for escaped prose, and since every WYSIWYG editor stores a typed `<` as `&lt;`, escaped is the *common* form.
- **`script`, `style`, `template`, `svg`, `iframe`, `title` and friends lose their contents too.** Comments are removed first, so a commented-out `<!-- <script src=…> -->` no longer swallows the whole description.
- **A tag holding an unbalanced quote no longer leaks.** `<script src="/a.js?v=1>` used to defeat every pattern and reach the field as raw markup; so did an attribute whose value gained a stray quote on decoding (`alt="the 6&quot; blade"`). Both are now removed.
- **Truncation counts characters, never bytes**, so an excerpt is always valid UTF-8 and the cap is still a hard ≤ 300 including the ellipsis.
- **Invalid input can't blank a field**: text is normalised to UTF-8 first (without needing `mbstring`), and every pattern falls back to leaving the string unmodified rather than returning null.
- Comments, doctypes and `</>` collapse away without becoming a boundary, matching what a browser shows. NUL and the other invisible C0 controls are dropped rather than passed through into JSON.

Affects `stories[].description`, `recent_posts[].excerpt`, `open_positions[].description` and `posts[].excerpt`. The snapshot cache is version-keyed, so the fix appears on the first request after updating rather than up to 10 minutes later.

**Every multi-paragraph excerpt and description string changes** — the spaces are new, and the 300-character cap now lands in a different place. Nothing changes type, and nothing becomes null that wasn't null before.

Two deliberate trade-offs, both narrower than the bugs they replace:

- Prose that *discusses a real element by name* in escaped form (`use &lt;p&gt; for paragraphs`) loses that tag, because after decoding, escaped markup and prose about markup are indistinguishable. Only genuine element names are affected — `&lt;shields&gt;` and `&lt;name,rank&gt;` are left alone.
- A genuinely unclosed `<script>` or `<style>` in *stored* markup still swallows the rest of the field, which is what a browser does with it. Prose that merely *mentions* those names doesn't, because the second pass never does that.

## Also

Flattening now lives in one shared library rather than inside the Astrolabe snapshot class, so the snapshot's excerpts and the API's excerpt are produced by the same code and the same cap — they can't drift apart. Three other places in the suite still flatten with `strip_tags` (the Discord webhook body, the mobile mission blurbs, and the word count) and are **unchanged in this release**; they can now converge on the shared library in a future one.

## Upgrade

Use the **Update Now** button on the dashboard, or `POST /Api/suite`. No database changes, no configuration changes.

## Credits

MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
