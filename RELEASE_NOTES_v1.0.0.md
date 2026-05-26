# Sim Central Suite v1.0.0 — Initial release 🎉

One Nova extension that replaces five standalone extensions with a unified dashboard, fewer dependencies, and a friendlier upgrade story.

## Highlights

- **Five features under one roof.** Display Name, Anti Spam Questions, Mission Post Summary, URL Parser, and Ordered Mission Posts — each independently togglable.
- **One install, one update.** Update a single folder instead of tracking five separate repos that haven't been touched in years.
- **Self-contained.** No more chasing down the `jquery`, `timepicker`, or `parser_events` Nova mods — all internalized.
- **Smart standalone migration.** The dashboard detects the standalone extensions you're already running and offers one-click takeover that handles `extensions.php`, the orphan menu items, the controller shims, and (importantly) doesn't touch your existing data.
- **State that survives upgrades.** Feature toggles, edited labels, and per-feature settings live in the Nova `settings` table — a fresh release of the code can't reset your choices.
- **Daily update check.** Dashboard tells you when a new release is available; degrades silently on network errors.

## What's included

### 🪪 Display Name
Custom display names on the manifest, character bio, join form, and personnel listings. Empty = stock Nova behaviour.

### 🛡️ Anti Spam Questions
Random security question on the contact and join forms. Multiple accepted answers per question, managed through the admin UI.

### 📝 Mission Post Summary
Short TL;DR field on long posts. Renders on the public post view, optionally in post emails, and always in the RSS feed.

### 🔗 URL Parser
Site-wide shortcode tags: `[docs|getting-started]` expands to an anchor across post content, news, personal logs, and mission descriptions.

### 📅 Ordered Mission Posts
Per-mission timeline choice (Nova Default / Day Time / Date Time / Stardate), optional post numbering (`Post 1`, `Post 2`, ...) across the website + emails + RSS, and inline word counts on the missions pages (admin + front-end). HTML5 `<input type="time">` instead of the legacy jQuery timepicker.

## Why use this over the standalones

| | Standalones | Sim Central Suite |
| --- | --- | --- |
| Required external deps | `jquery`, `timepicker`, `parser_events` mod | none |
| Admin pages | 5 separate items in *Manage Extensions* | 1 dashboard |
| Update process | 5 repos to track | 1 |
| Upgrade preserves settings | depends — many overwrite `config.json` | yes — state lives in `settings` table |
| One-click takeover from older shims | no | yes |
| Auto-detect new releases | no | yes (24h cache, fail-silent) |
| Time inputs | jQuery timepicker | native HTML5 |
| Word counts on missions list | broken popup | inline, on both admin and public pages |

## Requirements

- Nova **2.7.19+**
- PHP **8.x**

That's everything. No other Nova extensions or mods needed.

## Installation

1. Drop the folder into `application/extensions/nova_ext_sim_central`.
2. Add to `application/config/extensions.php`:
   ```php
   $config['extensions']['enabled'][] = 'nova_ext_sim_central';
   ```
3. Visit *Admin → Manage Extensions → Sim Central Suite*. Every feature starts disabled — turn on the ones you want.

## Migrating from standalone extensions

If you're running any of `nova_ext_display_name`, `nova_ext_anti_spam_questions`, `nova_ext_mission_post_summary`, `nova_ext_url_parser`, or `nova_ext_ordered_mission_posts`:

1. Install the suite (above).
2. Open the dashboard. Any standalone still enabled in `extensions.php` shows a *Standalone is enabled* warning on its row with a **Disable Standalone** button.
3. Click it. The suite strips the enable line from `extensions.php`, invalidates the PHP opcache so the next request reads the fresh file, and deletes the standalone's orphan menu item.
4. Refresh the dashboard. Toggle the suite feature ON.
5. Click **Set Up Database** (no-op if columns already exist — your data carries over) and **Install Shim** (takes over the standalone's existing `_email` / `posts` / `get_character_name` shim by replacing its marker block with the suite's).

Once everything's working, the standalone extension folders can be deleted. If you were using `jquery`, `timepicker`, or had the `parser_events` mod (`application/libraries/MY_Parser.php`) installed solely for these extensions, you can remove those too.

## Known limitations

- The dashboard's update check needs outbound HTTPS to `api.github.com`. Behind a strict firewall it'll fall back silently to showing only the installed version.
- The PHP `opcache_invalidate` call used by **Disable Standalone** is guarded behind `function_exists` — on the rare PHP build without opcache, the menu cleanup may need a second click to stick.
- All database columns the suite (or the standalones it replaces) ever created are intentionally **kept** on uninstall. Full removal of those columns is a manual SQL exercise.

## Credits

Built by [Reece Savage](https://github.com/reecesavage) of [Sim Central](https://discord.gg/simcentral), with inspiration and prior art from:

- [`chronological_mission_posts`](https://github.com/jonmatterson/nova-ext-chronological_mission_posts) (Jon Matterson) — the original "order posts by day/time" idea that became this suite's Ordered Mission Posts feature.
- [`jquery`](https://github.com/jonmatterson/nova-ext-jquery), [`timepicker`](https://github.com/jonmatterson/nova-ext-timepicker), and `parser_events` (Jon Matterson) — the suite vendors small portions of these so the user doesn't have to install them separately.

MIT licensed. See [`LICENSE`](LICENSE).

Issues and feature requests: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
