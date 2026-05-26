# Sim Central Suite v1.6.0 — Admin-index update notification

Surfaces Sim Central updates the same way Nova surfaces Nova updates: a panel on the admin home page.

## What's new

### "Sim Central" panel on the admin index

When a newer Sim Central Suite release is available, gamemasters now see a **Sim Central** entry in the admin home's left-hand panel list (the same `/admin/index` menu that hosts Nova's own *Update* panel, *My Nova*, *Notifications*, *Activity*, etc.). The entry has a small highlight badge to draw the eye.

Clicking it reveals:

- The new version number (e.g. *Sim Central Suite v1.7.2*)
- What version you're currently on
- **Go to Sim Central dashboard** button &mdash; one click to the suite's existing update flow (where the *Update Now* button is)
- **View release notes** link &mdash; opens the GitHub release in a new tab

Game masters who don't want to open the dashboard just to glance at what's available now have the same one-click visibility Nova gives them for itself.

### Self-skipping conditions

The panel only renders when ALL of these are true:

- Suite is enabled (init.php loaded; nothing to inject otherwise).
- Viewer is a gamemaster (`Auth::is_gamemaster` &mdash; sysadmins and assistant GMs). Non-GMs never see it, matching Nova's own update-panel visibility.
- The 24-hour update-check cache has a `latest_version` newer than `Config::version()`.

If the cache is empty (broker unreachable, brand-new install that hasn't done its first check) or the latest matches what's installed, nothing renders. No empty panel, no orphan badge.

## How it hooks in

A new event file `events/system_admin_index_update_notice.php` listens to `['location', 'view', 'output', 'admin', 'admin_index']` and uses the suite's `Generator` library to inject:

- `<li>` into `#panelmenu` (the nav strip)
- `<div class="sim_central_update hidden">` into `#acp-panel .panel` (the body)

The DOM `id` on the nav item matches the class on the panel `div`, which is the pattern Nova's own admin-index JS uses to switch panels on click. So our injected panel slots into the existing tab-switching behaviour with no extra JS.

The event is loaded unconditionally from `init.php` (alongside `UpdateCheck` and `Updater`), not behind any feature toggle. Reading the cached `UpdateCheck::latest()` is a single settings-row read so cost-per-request is negligible &mdash; no GitHub traffic happens here; that's the dashboard's job.

## What this is NOT

- **Not a new periodic check.** Reuses the existing 24h GitHub poll the dashboard runs.
- **Not a one-click updater entry point.** The button on the panel takes you to the existing dashboard, where the *Update Now* button (v1.1.0+) lives. Keeps the actual update flow in one place.
- **Not configurable.** Always on when a newer version is available and the viewer is a GM. If demand arises for a "suppress this" toggle, easy to add later.

## Upgrade

Use the **Update Now** button on the dashboard. After the reload, visit `/admin/index` and confirm: if you tag a fresh release on GitHub *after* upgrading, the new Sim Central panel should appear on the admin index within the 24-hour cache window (or immediately if you click **Check now** on the dashboard first).

## Credits

Same as v1.5.x. MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
