# Sim Central Suite v1.20.0 — Mobile phase 2: logs, manifest, missions, tour, themes

Significantly expands the mobile site (`/mobile`) with personal log authoring,
read-only crew manifest, mission browsing with post listings, ship tour with images,
and a light/dark/system colour-theme toggle. Also fixes a rich-text editor rendering
bug that caused formatting tag names to leak into post content.

## New features

### Personal logs
Members can now write and manage personal logs from the mobile site. The workflow
mirrors mission posts: draft → post, with edit, delete, and "return to draft"
actions. The author selector shows only your own characters (single dropdown). Tags
are supported. Webhooks fire through Nova's overridden `Personallogs_model` exactly
as they do on the desktop site.

Routes: `/mobile/logs` (list) · `/mobile/log` (new) · `/mobile/log/{id}` (view/edit)

### Crew manifest
`/mobile/manifest` lists all active crew and NPCs, ordered by rank then name.
Each card shows the character's rank, name, position, and their first assigned photo
(with a placeholder icon when none is set). Grouped into Crew and NPCs sections.

### Mission browser
`/mobile/missions` lists all missions grouped by status (Current / Upcoming /
Completed) with a short description blurb. Tapping a mission opens its detail page
(`/mobile/mission/{id}`), which shows mission images, description, summary, and a
chronological list of activated posts. Each post links to a full read-only view
(`/mobile/viewpost/{id}`) that adds Edit/Return-to-draft actions when you are an
author on the post.

### Ship tour
`/mobile/tour` lists all displayable tour items with a thumbnail preview. Each item
detail page (`/mobile/touritem/{id}`) shows the full-size primary image, a scrollable
row of additional images, the summary text, and any dynamic tour fields configured
through Nova's tour field system.

### Light / dark / system theme
A small **Auto / Light / Dark** cycling button in the header persists your preference
via `localStorage`. The CSS is built on custom properties so the switch is instant
with no page reload. System mode (`Auto`) follows the OS `prefers-color-scheme`
setting. The chosen theme is applied before the stylesheet renders to prevent any
flash of the wrong colours.

## Bug fix

**Rich-text editor: formatting tags leaking as plain text.** When reopening a post
or log that contained bold, italic, or underlined text, the tag name (`strong`, `em`,
`u`) appeared as literal text alongside the formatted word — e.g. `strongBOLDstrong`
instead of **BOLD**. Root cause: `PostWrite::storedToEditorHtml()` used
`PREG_SPLIT_DELIM_CAPTURE` with a regex containing a capturing inner group
`(strong|em|u)`, which caused PHP to include the bare tag name in the split result.
Fixed by changing the inner group to non-capturing `(?:strong|em|u)`. Affects all
three formatting commands equally.

## Navigation changes

The mobile header is now two rows: sim name + theme toggle on top, a horizontally
scrollable nav bar below with links to all sections:
**Posts · Logs · Manifest · Missions · Tour · Log out**

## Upgrade

Use the **Update Now** button on the dashboard. No database or config changes required.

## Credits

Same as v1.19.0. MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
