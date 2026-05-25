# Sim Central Suite - A [Nova](https://anodyne-productions.com/nova) Extension

<p align="center">
  <a href="https://github.com/reecesavage/sim-central-suite/releases/tag/v1.0.0"><img src="https://img.shields.io/badge/Version-v1.0.0-brightgreen.svg"></a>
  <a href="http://www.anodyne-productions.com/nova"><img src="https://img.shields.io/badge/Nova-v2.7.19+-orange.svg"></a>
  <a href="https://www.php.net"><img src="https://img.shields.io/badge/PHP-v8.x-blue.svg"></a>
  <a href="https://opensource.org/licenses/MIT"><img src="https://img.shields.io/badge/license-MIT-red.svg"></a>
</p>

One Nova extension that consolidates five sim-management features behind a single admin dashboard. Toggle each feature on or off independently, configure them in one place, and let the suite manage the database columns, controller shims, and menu plumbing so you don't have to install or update each one separately.

This release rolls up:

- **Display Name** &mdash; custom display names on the manifest in place of First/Last/Suffix
- **Anti Spam Questions** &mdash; random security question on the join + contact forms
- **Mission Post Summary** &mdash; short TL;DR field on long posts, also shown in emails and RSS
- **URL Parser** &mdash; site-wide shortcode tags that expand to anchors (`[docs|getting-started]`)
- **Ordered Mission Posts** &mdash; order posts by Day/Time, Date/Time, or Stardate; optional post numbering; HTML5 time inputs; inline word counts on the missions pages

## Requirements

- Nova **2.7.19+**

That's it. The suite is fully self-contained &mdash; no other Nova extensions, mods, or PHP libraries required. Earlier releases of the standalone extensions needed `jquery`, `timepicker`, and the `parser_events` Nova mod; the suite vendors a small copy of the jQuery-generator DSL and handles the rest internally. If you have those installed for other extensions, leave them; the suite simply doesn't depend on any of them.

## Installation

1. Copy the entire directory into `application/extensions/nova_ext_sim_central`.
2. Add the following to `application/config/extensions.php`:
   ```php
   $config['extensions']['enabled'][] = 'nova_ext_sim_central';
   ```
3. Visit your admin control panel and choose **Sim Central Suite** under Manage Extensions. Every feature is OFF by default.

## Migrating from the standalone extensions

The suite replaces these standalone Nova extensions:

| Suite feature           | Standalone extension                  |
| ----------------------- | ------------------------------------- |
| Display Name            | `nova_ext_display_name`               |
| Anti Spam Questions     | `nova_ext_anti_spam_questions`        |
| Mission Post Summary    | `nova_ext_mission_post_summary`       |
| URL Parser              | `nova_ext_url_parser`                 |
| Ordered Mission Posts   | `nova_ext_ordered_mission_posts`      |

If a standalone equivalent is still enabled in `application/config/extensions.php`, the dashboard refuses to enable the matching suite feature and offers a **Disable Standalone** button instead. Clicking it:

1. Strips the `$config['extensions']['enabled'][] = '<standalone>';` line from `extensions.php` (commented lines are left alone).
2. Invalidates the PHP opcache for that file so the next request reads the fresh copy.
3. Deletes any `menu_items` rows whose `menu_link` points into the standalone's URL space.

Database tables/columns the standalone created are intentionally **kept** &mdash; the suite reuses them, so your existing data is preserved.

After that, enable the matching feature in the suite. For each feature the dashboard walks you through:

- **Set Up Database** &mdash; adds any columns/indexes the feature needs to `posts`, `missions`, etc. Safe to re-run.
- **Install Shim** &mdash; injects a small managed code block into `application/controllers/Write.php`, `Feed.php`, or similar. The shim has a START/END marker so the suite can update or remove it cleanly. If a standalone's older shim is detected in the same file, the suite takes it over with one click.
- **Configure** &mdash; per-feature page for labels, settings, and (for Ordered) legacy-mode toggle.

Once everything is working, the standalone extension folders can be deleted.

## State and upgrades

All persisted user state &mdash; feature toggles, edited labels, edited settings &mdash; lives in a single row of the Nova `settings` table (`setting_key = 'sim_central_state'`). `config.json` in the repo only ever holds bundled defaults; it is never written to.

When you upgrade the suite, the new `config.json` ships fresh defaults but your settings row is left alone. On load the two are deep-merged with your state winning, so customisations persist and any newly-added defaults flow through.

## Update checking

The dashboard checks GitHub once every 24 hours for a newer published release and shows an *Update available* banner if one exists. The check uses a 2&ndash;3 second timeout and degrades silently on network or rate-limit failure, so it never blocks the admin page. Cached result lives in a separate `settings` row (`setting_key = 'sim_central_update_check'`); you can clear it at any time if you want to force a re-check.

## Feature details

### Display Name
Adds a `display_name` column to `characters`. When set, it replaces the standard First / Last / Suffix combination on the manifest, character bio, join form, and personnel listings. Empty = stock Nova behaviour.

### Anti Spam Questions
Adds a random multi-answer security question to the **contact** and **join** forms. Questions and accepted answers are managed from the suite's *Anti Spam Questions* page (stored as `settings` rows with `setting_key = 'question'`).

### Mission Post Summary
Adds a `nova_ext_mission_post_summary` column to `posts` and a per-mission enable toggle on `missions`. On the write/edit post page a Summary field appears; on the public post view the summary renders below the title. Toggleable inclusion in post emails (`Include summary in post emails`) and always-on inclusion in the RSS feed.

### URL Parser
Creates a `tag` table and lets you define shortcode tags. `[docs|getting-started]` expands to an anchor across post content, news, personal logs, and mission descriptions. Optional `post_url` segment and target=_blank toggle per tag.

### Ordered Mission Posts
Replaces `nova_ext_ordered_mission_posts`. Lets each mission pick a timeline configuration:

- **Nova Default** &mdash; stock activation-time sort
- **Day Time** &mdash; sort by Mission Day + Time
- **Date Time** &mdash; sort by calendar date (YYYY-MM-DD) + Time
- **Stardate** &mdash; sort by decimal stardate + Time

Time inputs are HTML5 `<input type="time">` &mdash; no jQuery timepicker dependency. With **Post Numbering** enabled, each post's title is prefixed with its 1-based chronological position (`Post 1`, `Post 2`, ...) on the website, in post emails, and in the RSS feed.

**Legacy mode**: if `chronological_mission_posts` columns are still present on the posts table, the per-feature config page exposes a toggle that lets existing missions reuse those Day/Time values when set to Day Time.

The suite also shows inline word counts per mission on both the admin **Manage Missions** page (current / upcoming / completed) and the public `/sim/missions` page. Counts are computed in a single batched query per page load.

## Reset / uninstall

- **Reset state to defaults**: `DELETE FROM nova_settings WHERE setting_key = 'sim_central_state';` &mdash; on the next page load, state is re-seeded from `config.json` (all features off).
- **Force an update re-check**: `DELETE FROM nova_settings WHERE setting_key = 'sim_central_update_check';`
- **Remove a shim**: disable the corresponding feature from the dashboard. The shim block is stripped from the target controller, unless another still-enabled feature shares the same file (e.g. summary and ordered both inject into `Feed.php`'s `posts()`).
- **Full uninstall**: disable every feature (so all shims are removed), then comment out / remove the `$config['extensions']['enabled'][] = 'nova_ext_sim_central';` line and delete the extension folder. Database columns are intentionally left in place.

## Issues

Report bugs or feature requests at: <https://github.com/reecesavage/sim-central-suite/issues>

## License

Copyright &copy; 2026 Reece Savage.

This module is open-source software licensed under the **MIT License**. The full text of the license may be found in the `LICENSE` file.
