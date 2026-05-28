# Sim Central Suite v1.12.0 &mdash; Webhooks for logs & news

Extends the Event Webhooks feature with two new events: `log.posted` (personal logs) and `news.posted` (news items). Both fire on activation only.

## What's new

### `log.posted`

Fires when a personal log is activated. Simpler than a mission post &mdash; the Discord embed carries just author, title, and body:

```
{sim_name} Log | {title}
*A personal log by {author}*

{body excerpt}

[Read the full log]({url})
```

Generic JSON payload uses a `log` object (id, title, content, status, date, url_public, url_admin) alongside the usual `authors`, `actor`, and `sim`.

### `news.posted`

Fires when a news item is activated. The Discord embed adds category and type:

```
{sim_name} News | {title}
*{category} · {Public|Private} · by {author}*

{body excerpt}

[Read the full item]({url})
```

Generic JSON uses a `news` object that adds `category` and `type` (`"public"` / `"private"`).

### News public/private filter

Each webhook subscribed to `news.posted` chooses which news types it wants &mdash; **Public** (default), **Private**, or **Both**. Useful for routing public announcements to a member channel while keeping private/staff news on a separate webhook (or off entirely). The filter is applied before delivery, so a public-only webhook never sees private items in either format.

## Design notes

- **Activation only.** Logs and news don't get a `saved` event &mdash; only `log.posted` / `news.posted`, firing on the transition to `activated` (not on edits of already-live items, same transition rule as `post.posted`).
- **No pinging.** Like `post.posted`, these are public announcements: plain "Rank Name" bylines, empty `content`, no notification spam. (Author @mention pings remain exclusive to `post.saved`.)
- **Fixed formats.** `log.posted` and `news.posted` use sensible built-in Discord layouts tailored to each content type. Only `post.posted` is templateable &mdash; logs and news don't expose `template_*` fields. If there's demand for custom log/news templates we can add per-event templating later.
- **Single dispatch path.** The library was refactored so all three content types normalise into one flat "item" shape and flow through one `dispatch()`; payload builders branch on the item type. Adding a future content type is mostly a loader + a couple of format methods.

## Implementation notes

- `libraries/Webhooks.php` &mdash; new `onLogChanged()` / `onNewsChanged()` entry points, `loadLog()` / `loadNews()` normalisers, `discordLogPosted()` / `discordNewsPosted()` builders, generic payload branches, and the news type filter in `dispatch()`. Author/character loading generalised to `charactersFromCsv()` so logs (single author) and posts (CSV) share one path.
- Four new model shims: `News_model::create_news_item` + `update_news_item`, `Personallogs_model::create_personal_log` + `update_log`. Same managed-block pattern as the post shims, **without** `standalone_marker_*` fields (the v1.11.1 lesson &mdash; those cause same-file shims to strip each other).
- New `sim_central_webhooks.news_types` column (`'public'` / `'private'` / `'both'`, default `'public'`). Added to fresh installs via the CREATE DDL and to existing installs via a `requires_db` ALTER, so **Setup database** picks it up on upgrade.
- ACP: `log.posted` / `news.posted` added to the events list; a news-types radio group (shown when `news.posted` is ticked); save/load wired through `_saveWebhook`.

## Upgrade

Use the **Update Now** button on the dashboard. Then, on the *Event Webhooks* row:

1. **Setup database** &mdash; adds the new `news_types` column to the existing table.
2. **Install Shim** &mdash; adds the four new managed blocks (News_model + Personallogs_model). Your existing post shims are left untouched. **Without this step, log/news webhooks won't fire.**
3. *Configure* &mdash; edit an existing webhook (or make a new one), tick `log.posted` and/or `news.posted`, choose the news type filter, save.
4. **Test** &mdash; the per-row Test dropdown now lists all subscribed events, including the new ones; pick `log.posted` or `news.posted` and confirm a test embed lands.

Existing post webhooks keep working unchanged through all of this.

## Credits

Same as v1.11.0. MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
