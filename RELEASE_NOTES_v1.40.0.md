# Sim Central Suite v1.40.0 — mobile reading & writing comfort

Three member-requested improvements to the mobile site.

## Text size controls

New **A−** and **A+** buttons in the mobile header, next to the light/dark toggle. They scale the text you actually read and write — mission posts, personal logs, messages, mission and tour text, and the post/log editor — across five steps from slightly smaller to half again as large.

Navigation, buttons and form labels keep their size, so the layout stays put at every setting. Your choice is remembered on that device and applies the moment a page opens, with no flicker.

## Formatting buttons stay with you

The **B / I / U** toolbar now stays pinned just below the header while you scroll through a long post, so you can bold or italicise a word without scrolling back to the top. It releases once you scroll past the editor.

## Easier pasting

If you draft your posts elsewhere (so you don't risk losing work), there's now a **Paste** button in the editor toolbar. One tap drops your copied text straight in — no need to tap into the box first to place a cursor. Line breaks and paragraph spacing come through exactly as you wrote them.

Your device will ask permission the first time. Where the clipboard isn't available — some sims are served over plain `http`, where browsers block it — the button still puts the cursor in the editor and tells you to long-press and choose Paste.

The empty editor also now shows a faint "Tap here to write, or use Paste above." hint, so it's obvious where to tap.

All three apply to both the **mission post** editor and the **personal log** editor.

## Upgrade

Use the **Update Now** button on the dashboard, or `POST /Api/suite`. No database changes, no configuration changes.

## Credits

MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
