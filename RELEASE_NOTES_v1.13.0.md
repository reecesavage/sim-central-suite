# Sim Central Suite v1.13.0 &mdash; Templateable logs/news, role pings & summary fixes

A feature release across two areas of the suite: the **Mission Post Summary** write form now respects the per-mission enable toggle, and **Event Webhooks** gains templateable Discord embeds for logs and news, smarter author pings on `post.saved`, and an optional per-event Discord role mention.

## What's new

### Summary box respects the mission toggle

The summary text box on the write/edit post form used to render for everyone, regardless of whether the selected mission actually had summaries enabled. The toggle JS that was meant to hide it was never enqueued, so the box always showed.

Now the box only appears when the bound mission has `mission_ext_mission_post_summary_enable` set:

- **Editing an existing post** &mdash; gated on that post's mission.
- **New post, single current mission** &mdash; gated on that mission.
- **New post, multiple current missions** &mdash; gated on the dropdown's current selection, and toggled live (inline AJAX to the suite's `ordered_mission` endpoint) whenever the mission dropdown changes.

### `post.saved` no longer pings the author who saved

`post.saved` is the "co-writer ping" event &mdash; it @mentions the post's authors so collaborators know an update landed. It now **excludes the writer who triggered the save**: you don't get pinged for your own edit, only your co-authors do. Solo posts with no other authors simply send no ping. Matching is by Discord ID first, then by character ID.

### Templateable Discord embeds for logs & news

In v1.12.0, `log.posted` and `news.posted` shipped with fixed embed layouts &mdash; only `post.posted` was templateable. Now all three are. Each webhook can override the title and description for logs and news independently, using the same `{variable}` syntax as post templates. Leave a field blank to keep the built-in default.

**Common variables** (all content types): `{sim_name}`, `{title}`, `{body}`, `{authors}`, `{url}`.
**Logs** add nothing beyond the common set. **News** add `{category}`, `{type}`, `{meta}`.

Defaults (unchanged output if you don't template):

```
{sim_name} Log | {title}
*A personal log by {authors}*

{body}

[Read the full log]({url})
```

```
{sim_name} News | {title}
*{meta}*

{body}

[Read the full item]({url})
```

### Optional per-event Discord role ping

Each webhook can now name a Discord **role ID** and pick exactly which of its subscribed events should ping that role. The mention is placed in the message `content` (the only field Discord fires notifications from), so it actually notifies &mdash; embeds never do.

It's opt-in per event via checkboxes: e.g. ping `@Players` on `post.posted` but stay silent on `news.posted`, all from one webhook. On `post.saved` the role mention rides alongside the co-author pings (and still skips the actor). Leave the role ID blank and nothing changes.

## Design notes

- **Pings live in `content`, never embeds.** Discord only sends notifications for mentions in the message `content` field. That's why announcement events historically had empty `content`; the role ping is the deliberate, opt-in exception, and it's the only thing added to `content` for `post.posted`/`log.posted`/`news.posted`.
- **Per-event opt-in, not global.** Rather than one "ping this role" switch, each event is individually selectable (`mention_role_events`), so a single webhook can be loud on some events and quiet on others.
- **Unified template variables.** The builders now share one `templateVars()` that always exposes `{body}`/`{title}` and branches by item type for the post- and news-specific extras. Defaults produce byte-for-byte the same output as before for anyone who doesn't template.

## Implementation notes

- `libraries/Webhooks.php` &mdash; new `DEFAULT_LOG_*`/`DEFAULT_NEWS_*` template constants; `discordLogPosted()`/`discordNewsPosted()` read `template_log_*`/`template_news_*` (isset-guarded so un-migrated installs don't fatal); `authorMentions($item, $excludeActor)` drops the acting writer; new `roleMentionForEvent($webhook, $event)` validates the role ID and the per-event opt-in list and returns `<@&id>` for `content`.
- `controllers/Manage.php` &mdash; CREATE DDL and `requires_db` both gain `mention_role_id`, `mention_role_events`, `template_log_title`, `template_log_description`, `template_news_title`, `template_news_description`. `_saveWebhook()` reads and validates them (role ID via `ctype_digit`; the event list is intersected with the webhook's actual subscribed events and cleared if no role ID is set).
- `views/admin/pages/webhooks.php` &mdash; new role ID field + "Ping role on" checkbox block, and Discord template sections for logs and news with per-variable help text.
- Summary fix lives in `events/summary_location_admin_write_missionpost.php` (server-side mission resolution + `$summaryEnabled` flag + live toggle JS) and `views/admin/pages/summary_form.php` (`display:none` until enabled).

## Upgrade

Use the **Update Now** button on the dashboard. Then, on the *Event Webhooks* row:

1. **Setup database** &mdash; adds the six new columns to the existing table: `mention_role_id`, `mention_role_events`, `template_log_title`, `template_log_description`, `template_news_title`, `template_news_description`. **Without this step the new template and role-ping fields have nowhere to save.**
2. *Configure* &mdash; edit a webhook to set log/news templates, a role ID, and the per-event ping checkboxes as desired. All new fields are optional; leaving them blank preserves existing behaviour.

Existing webhooks keep working unchanged. The summary-box fix needs no setup &mdash; it takes effect as soon as the update lands.

## Credits

Same as v1.12.0. MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
