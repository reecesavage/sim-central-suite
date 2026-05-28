# Sim Central Suite v1.11.0 &mdash; Event Webhooks

Adds an HTTP webhook system that fires when posts change state. Discord-formatted with `<@id>` author mentions, or generic JSON for n8n. Multiple webhooks per event. Fire-and-forget delivery with per-webhook status visibility.

## What's new

### New feature: Event Webhooks

Ninth feature in the suite. Toggle on from *Sim Central Suite &rarr;* **Event Webhooks**, run **Setup database**, then **Configure** to add webhooks.

Two events:

- `post.saved` &mdash; fires when a draft post is created or saved (post_status = `saved`). For nudging co-authors that a draft has been updated.
- `post.posted` &mdash; fires on the transition from a non-activated status to `activated` (i.e. when a post is actually posted). The main announcement event. Edits of already-activated posts do **not** re-fire this.

Two delivery formats:

- **Discord** &mdash; paste your channel's webhook URL straight in. Renders a rich embed (title with sim name, italic byline, Mission/Location/Timeline fields, smart-truncated body excerpt with HTML stripped and basic Markdown applied, random embed colour, *Read the full post* link). Authors with a linked Discord ID (via the existing Discord Sign-In feature) are mentioned via `<@discord_id>` in the message content so they get a notification ping; un-linked authors render as `Rank First Last` plain text.
- **Generic JSON** &mdash; flat structured payload (`event`, `post`, `authors`, `actor`, `sim`, `fired_at`). Drop-in for n8n's webhook trigger, custom scripts, anything that consumes JSON.

Each webhook subscribes to one or more events, has an enabled toggle, and (for Discord) admin-customisable embed templates for `post.posted` with `{sim_name}`, `{post_title}`, `{authors}`, `{authors_plain}`, `{mission}`, `{location}`, `{timeline}`, `{body}`, `{url}`, `{url_admin}`, `{actor}` variables. `post.saved` uses a fixed format (always tags every linked author so the whole co-author group sees the update).

### Test button

Each webhook gets a per-event **Test** button on the manage page. Fires a synthetic event with realistic-looking dummy data so you can verify wiring before the first real post lands.

### Failure visibility

Every delivery records `last_fired_at`, `last_status` (HTTP code, or `0` for network/timeout), and `last_error` (truncated response body or curl error message). The manage table shows these inline with green/red colouring so a broken integration jumps out at a glance.

## Why fire-and-forget?

A webhook URL going slow or going down should never make the writer page hang. The delivery is one cURL POST with a 2-second timeout + 2-second connect timeout. We do not retry, queue, or batch. Failures are recorded but don't block the save.

This matches what Discord's own webhook docs recommend ("at-most-once delivery is fine, this isn't a transaction log") and is what every production webhook system in the suite's reference set does. If you need exactly-once or retries, route through a generic webhook to a real queue (n8n, Cloudflare Queues, etc.) and do the retries there.

## How post detection works

Hooks into Nova at the model layer via two managed-block shims on `Posts_model.php`: one wraps `create_mission_entry`, one wraps `update_post`. Each shim calls `parent::` first, then (on success) hands the post id + new data + previous status to `\nova_ext_sim_central\Webhooks::onPostChanged()`.

The shim writer uses the suite's existing pattern (same managed-block format that `display_name` uses on `Characters_model.php`), so install / upgrade / disable all just work &mdash; the shim blocks come and go cleanly when you toggle the feature.

We capture the *previous* `post_status` before the update happens so we can tell `saved → saved` (just a draft revision) apart from `saved → activated` (the post is actually going live). Without that distinction, `post.posted` would re-fire on every edit of an already-published post, which is exactly what nobody wants.

## Why no Mission "[4]" post number in the default template?

The number you sometimes see in the parsed n8n output (`UnderMind [4]`) is part of the mission_title field on some sims &mdash; usually a manual naming convention. We deliberately render `{mission}` as just the mission title verbatim, so whatever the sim chose to call the mission is exactly what shows up. If your sim wants per-mission post numbers, they're in the ordered_mission_posts feature already &mdash; we could add a `{post_number}` template variable in a follow-up if there's demand.

## Implementation notes

- New table `sim_central_webhooks` (id, label, url, format, events JSON, enabled, template_title, template_description, created_at/by, last_fired_at, last_status, last_error). Installed via the standard *Setup database* flow.
- New library `libraries/Webhooks.php` &mdash; `onPostChanged()` dispatcher, `buildDiscord()` / `buildGeneric()` payload builders, `htmlToMarkdown()` and `smartTruncate()` ports of the n8n recipe a lot of sims use today, `deliver()` for fire-and-forget cURL, `testWebhook()` for the manage-page test button. Loaded conditionally from `init.php` when the feature is on.
- New shim files `posts_model_create.txt` and `posts_model_update.txt` &mdash; thin managed-block wrappers that call the dispatcher after `parent::` succeeds. Both gated by `class_exists()` so they're inert when the feature library hasn't loaded.
- New `_featureRegistry` entry &mdash; two shim tags (`webhooks_create`, `webhooks_update`) pointing at the same target file (`Posts_model.php`). First time the suite has shimmed two methods on the same model; the existing writer handles it because each shim has its own tag.
- New `Manage::webhooks()` route + private helpers (`_saveWebhook`, `_deleteWebhook`, `_toggleWebhook`, `_webhookAvailableEvents`, `_webhookAvailableFormats`).
- New view `views/admin/pages/webhooks.php` &mdash; create/edit form, Discord template fields that toggle visibility on format change, list table with status, per-row Test / Toggle / Delete actions.
- New doc `WEBHOOKS.md` &mdash; full reference covering events, formats, template variables, JSON payload shape, worked example, troubleshooting, security notes.

## Upgrade

Use the **Update Now** button on the dashboard. After reload:

1. *Sim Central Suite &rarr; Event Webhooks &rarr;* **Enable** &rarr; **Setup database**.
2. **Install shim** on the Event Webhooks row &mdash; this adds the two managed blocks to `application/models/Posts_model.php`. Without this step the dispatcher never fires.
3. **Configure** &rarr; create a webhook. For Discord: grab the channel webhook URL from *Channel Settings &rarr; Integrations &rarr; Webhooks &rarr; New Webhook*. Pick `discord` format, tick `post.posted` (and `post.saved` if you want draft notifications too), enable, save.
4. Click **Test** on the row &mdash; should drop a "Test Post" embed into the channel within a couple of seconds. Status column should turn green with `HTTP 204` (Discord's success code for webhooks).
5. Real test: edit a saved post and click **Post**. Discord channel should get the announcement.

If you don't want this feature, leave it off &mdash; the shims won't install, the table won't be created, and no webhooks fire.

If something breaks, the recovery path is:

- Disable the feature from the dashboard &mdash; this removes the shim blocks from `Posts_model.php`.
- Or wipe configured webhooks: `UPDATE nova_sim_central_webhooks SET enabled = 0;` (or `DROP TABLE nova_sim_central_webhooks;`).

## Credits

Same as v1.10.0. MIT licensed. Smart-truncate + colour palette ported from the n8n recipe a lot of Nova sims have been using externally &mdash; thanks to everyone who's been hammering it into shape there.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
