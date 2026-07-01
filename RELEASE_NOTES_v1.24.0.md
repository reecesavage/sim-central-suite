# Sim Central Suite v1.24.0 — Drop the parser_events (MY_Parser) dependency

## What changed

The suite no longer listens to any `parse_string` events, so the third-party **parser_events** Nova
mod (`application/libraries/MY_Parser.php`) is **no longer required by any feature**. It can be left
in place or removed; the suite behaves identically either way, and there is no double-processing if
another extension still uses it.

Previously, three features still rode on parser_events:

- **Mission Post Summary** — the summary line in post notification emails.
- **URL Parser** — expanding `[tag|title|display]` shortcodes into links in post / log / news emails.
- **Discord Sign-In** — the Discord row in the new-applicant email (added in v1.23.0).

The on-site display of Summary and URL Parser links never used parser_events (it uses Nova's own
view events), so **nothing changes on the website.** Only the email-time work moved.

## How it moved

The Summary and URL Parser email injection now runs through the suite's own Write-controller email
shim (the same `Email::filter()` hook that already builds the ordered timeline and post numbering).
Because that hook reuses the same URL expander as the on-site display, the emailed links match the
website exactly.

## Upgrade notes (please read)

- **Sims running Mission Post Summary or URL Parser _without_ Ordered Mission Posts:** these features
  now use the shared **"Post email code"** shim on the Write controller, which those sims did not have
  before. After updating, open the suite dashboard and **Install Shim** for Summary / URL Parser when
  prompted. Until you do, post / log / news notification emails will send without the summary line or
  expanded links (the website is unaffected). Sims that already run Ordered Mission Posts already have
  this shim and need no action.
- **Discord new-applicant email:** the Discord row that v1.23.0 added to the GM notification email has
  been **removed** (it depended on parser_events). The applicant's linked Discord account still shows
  on their **account page** when a sysadmin views the pending user, which remains the reliable place
  to see it.

## Upgrade

Use the **Update Now** button on the dashboard, or `POST /Api/suite`. No database changes. Then
install the Post email code shim for Summary / URL Parser if the dashboard flags it.

## Credits

MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
