# Sim Central Suite v1.21.5 — Mobile display fix + skin readability

## Fixes

- **Mobile read-only views now render HTML instead of showing raw tags.** On the mobile site,
  posted posts, viewed posts, personal logs, and mission descriptions displayed their body as
  literal markup (e.g. `<a href="...">` shown as text) instead of rendering it. The read-only
  renderer fell back to `PostWrite::storedToEditorHtml()` — which escapes everything except
  `<strong>/<em>/<u>` because it is built to load content *into the mobile editor*, not for
  display — whenever the language helper wasn't loaded. `Mobile.php` now loads the language helper
  and renders display bodies through `text_output()` (nl2br + HTMLPurifier), the same path the
  desktop site uses. The editor-load paths are unchanged.

- **Suite admin pages stay legible under skins with light text / dark controls (e.g. Titan).**
  The API Explorer, REST API, Webhooks, Discord Sign-In, Content Filter, and Mobile settings pages
  use light-background info boxes and native inputs, so under those skins their text went
  light-on-light. A shared, high-contrast baseline (`sc_readable.css`) is now scoped to a
  `.sc-readable` wrapper around suite pages — Nova's own pages are untouched. The write / edit-post
  date, time, day, and stardate inputs the suite injects are also pinned to readable colours.

## Upgrade

Use the **Update Now** button on the dashboard, or `POST /Api/suite`. No database changes.

## Credits

MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
