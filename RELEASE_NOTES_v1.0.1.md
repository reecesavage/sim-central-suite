# Sim Central Suite v1.0.1 — Date picker + form-width fixes

Small follow-up to v1.0.0 fixing three usability issues on the write / manage / add mission post pages.

## Fixes

- **Native date inputs.** The jQuery UI datepicker on the post-date and default-mission-date fields has been replaced with HTML5 `<input type="date">`, matching the v1.0.0 treatment of the time fields. The native picker:
  - Always renders on top of other form elements (no more z-index conflict where the calendar appeared *under* the mission dropdown).
  - Has working previous/next month arrows (the jQuery version was rendering them blank).
  - Lets you click the year/month to jump quickly to a far date — no more typing the date when it's not near today.
  - Works on mobile.
- **Date input now wide enough to show the whole date.** Previously the field was sized so that a stored date like `1982-10-20` truncated to `1982-10-` in the visible area. Added a CSS `min-width: 170px` on the suite's date inputs.
- **Mission dropdown widened on the post forms.** The stock Nova `<select name="mission">` / `<select name="post_mission">` is about 10 characters wide, which truncated long titles and caused them to wrap inside the open dropdown. Added a CSS `min-width: 320px` so titles fit on one line.

## Internal cleanup

- Dropped the legacy `jquery.ui.datepicker.css` + `jquery.ui.datepicker.min.js` asset injection from the template-render listener. Two fewer requests on every admin page render.
- Removed the corresponding `$('.datepick').datepicker(…)` init and the `data-value` round-trip from `ordered_custom.js`.
- The `ordered_manage` CSS file is now also loaded on the admin **Manage Missions → Add / Edit** page so the default-mission-date field gets the same width treatment.

## Upgrade

Drop in over your existing v1.0.0 install — no database changes, no shim re-install required. The suite's settings row carries forward; the dashboard will show **Installed: v1.0.1** automatically.

## Known limitations

- Native HTML5 date input chrome (locale display, picker style) varies by browser. Chrome / Edge / Safari / Firefox all render a usable calendar; older browsers fall back to a plain text input with date validation.
- Native picker shows the date in the user's locale format (e.g. MM/DD/YYYY in US English) even though the underlying value is always submitted as ISO `YYYY-MM-DD`. This matches the stored format, so no conversion is needed and no data is lost.

## Credits

Same as v1.0.0. MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
