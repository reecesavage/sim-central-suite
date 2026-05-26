# Sim Central Suite v1.6.2 — Update notice moves into Notifications

Polish + bugfix for the admin-index update notice added in v1.6.0.

## What changed

### Folded into the Notifications panel

In v1.6.0 the "Sim Central Suite update available" notice rendered as its own dedicated panel in the admin-index left-hand strip, sitting alongside Nova's own *Update* panel. That worked, but it felt slightly off — the other panels in that strip (My Nova, Notifications, Activity, Milestones) are about activity inside the sim, and a generic "system has an update" notice fits more naturally with the rest of *Notifications*.

So in v1.6.2 the notice now appears as a row inside the existing **Notifications** panel instead of as its own menu entry:

> **1** &nbsp; *Sim Central Suite v1.7.0 available* · *release notes*

The first link goes to the suite dashboard (where the one-click *Update Now* button lives). The "release notes" link opens the GitHub release in a new tab.

The Notifications nav badge increments to reflect the extra row, so it's still visible from a glance even without opening the panel.

### Empty-badge bug fixed (was v1.6.0)

The dedicated panel's badge in v1.6.0 used a jQuery UI Theme icon class (`ui-icon-arrowthick-1-n`) which doesn't render in every Nova skin's CSS bundle — some installs (including the one this was reported on) just saw an empty box next to "Sim Central". Moot since the dedicated panel is gone, but worth noting if anyone saw it.

The new row uses the plain text "1" badge style, matching every other notification row in the panel. Renders consistently across all skins.

## How it works

Two event listeners (both unconditional, both self-skip when there's nothing to show):

- **data event** (`['location','view','data','admin','admin_index']`) bumps `$notifycount` by 1. Two effects:
  - Nova renders the notifications `<table>` element at all (it skips the entire table when `notifycount == 0`).
  - The Notifications nav badge displays the higher count.
- **output event** (`['location','view','output','admin','admin_index']`) prepends a `<tr>` into the rendered `.notifications table tbody` via the suite's `Generator`.

Both events check the same conditions: viewer is a gamemaster, `UpdateCheck::latest()` cache has a `latest_version` newer than `Config::version()`. A small helper function (`_sim_central_admin_index_update_pending()`) centralizes that check so both events make the same decision.

The 24-hour GitHub cache from the dashboard is reused as-is — admin/index never makes its own network call.

## Upgrade

Use the **Update Now** button on the dashboard. After reload, visit `/admin/index`. If your sim is up to date, the Notifications panel looks unchanged. If a newer release exists in the cache (or you click **Check now** on the dashboard to refresh it), the new row appears at the top of the Notifications panel and the nav badge shows the updated count.

## Credits

Same as v1.6.0. MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
