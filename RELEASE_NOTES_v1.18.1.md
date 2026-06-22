# Sim Central Suite v1.18.1 — Mobile editor timeline scheme validation fix

Fixes a false "wrong timeline scheme" error when saving a post on a mission that uses
the `day_time` (or `date_time` / `stardate`) timeline scheme.

## Cause

The mobile editor shows only the timeline input that matches the selected mission's
scheme and hides the others with `display:none`. However, hidden inputs still submit
their values. If the post had a non-empty value stored for a non-active scheme field
(e.g. an `ordered_stardate` from a previous edit or mission change), that value was
submitted in the form and tripped the `timelineErrors()` validation check, producing:

> This mission uses the 'day_time' timeline scheme; send 'ordered_day' (not 'ordered_stardate').

## Fix

The `tl()` JS function in the editor now clears the value of hidden timeline inputs
whenever it hides their section (on page load and on mission change). Hidden fields
always submit as empty strings, which the validator correctly ignores.

## Implementation notes

- `controllers/Mobile.php` — one-line addition inside the inline `tl()` script in
  `_editor()`: clear `inp.value` when hiding a `[data-tl]` section.

## Upgrade

Use the **Update Now** button on the dashboard. No database or config changes.

## Credits

Same as v1.18.0. MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
