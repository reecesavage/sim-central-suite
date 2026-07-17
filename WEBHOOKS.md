# Sim Central Suite — Event Webhooks Reference

Fires HTTP POST notifications when posts change state. Two events, two delivery formats, multiple webhooks per event. Configured under *Sim Central Suite → Event Webhooks → Configure*.

---

## Events

| Event | Fires when |
|---|---|
| `post.saved` | A draft mission post is created or saved (`post_status = 'saved'`). Both inserts and updates. |
| `post.posted` | A mission post **transitions** to `activated`. Edits of already-activated posts do **not** re-fire. |
| `post.updated` *(v1.32.0+)* | An already-`activated` post is edited and re-saved **without** a status change. Mainly for machine sync (e.g. Astrolabe refreshing its mirrored copy). Generic payload is identical in shape to `post.posted` (with `event: "post.updated"`); the Discord format renders the same embed as `post.posted` but has its **own role-ping opt-in**. Leave it unchecked on announcement channels or every edit re-announces. |
| `log.posted` | A personal log **transitions** to `activated`. Activation only — no saved event. |
| `news.posted` | A news item **transitions** to `activated`. Activation only. Honours the per-webhook public/private type filter. |

Detection is hooked into the model layer via managed-block shims:

| Event(s) | Shimmed model + methods |
|---|---|
| `post.*` | `Posts_model::create_mission_entry`, `update_post` |
| `log.posted` | `Personallogs_model::create_personal_log`, `update_log` |
| `news.posted` | `News_model::create_news_item`, `update_news_item` |

We snapshot the previous status before each update so a transition to `activated` is told apart from an edit of an already-live item.

### News public/private filter

`news.posted` only fires for the news types a webhook opts into. On the Configure page each webhook subscribed to `news.posted` picks one of:

- **Public** (default) — only public news items (`news_private = 'n'`)
- **Private** — only private news items (`news_private = 'y'`)
- **Both** — every news item

This lets you route public announcements to a member-facing channel and keep private/staff news in a separate webhook (or skip it entirely).

---

## Formats

### Discord

Pick this when the webhook URL is a Discord channel webhook (`https://discord.com/api/webhooks/...`). The payload is a Discord-standard webhook body with a `content` field (where author `<@id>` mentions go so they actually ping) and an `embeds` array.

**`post.posted` embed**

Configurable per webhook via `template_title` and `template_description`. Defaults:

```
title:       {sim_name} Post | {post_title}
description: *A mission post by {authors}*

             **Mission** - {mission}
             **Location** - {location}
             **Timeline** - {timeline}

             {body}

             [Read the full post]({url})
```

The embed gets a random colour from a Discord-friendly palette (same one a lot of sims use in their existing n8n flows) and a timestamp of the post's date.

**`post.saved` embed** (not templateable — fixed format)

```
title:       A saved mission post has been updated
description: **Title** - {post_title}
             **Mission** - {mission}
             **Saved by** - {actor}

             [View the post]({url_admin})
```

Tags the **other** linked co-authors in the `content` line so they get a notification ping that the draft moved — the author who actually saved is **not** pinged (no point notifying someone of their own edit). Optionally also pings a configured Discord role if `post.saved` is checked under *Mention role* (see below).

**`log.posted` embed** (templateable, no author ping)

Configurable per webhook via `template_log_title` and `template_log_description`. Defaults:

```
title:       {sim_name} Log | {title}
description: *A personal log by {authors}*

             {body}

             [Read the full log]({url})
```

**`news.posted` embed** (templateable, no author ping)

Configurable per webhook via `template_news_title` and `template_news_description`. Defaults:

```
title:       {sim_name} News | {title}
description: *{meta}*

             {body}

             [Read the full item]({url})
```

`{meta}` expands to the `Category · {Public|Private} · by {authors}` line (category is omitted when unset). Use `{category}` and `{type}` directly if you want a different layout.

`post.posted`, `log.posted`, and `news.posted` are all templateable. `post.saved` uses a fixed format (it's a lightweight ping, not an announcement).

**Mention role**

Each webhook can store an optional Discord **role ID** (`mention_role_id`) plus a per-event opt-in list (`mention_role_events`, a JSON array). When a role is set, it's pinged via `<@&id>` in the message `content` on exactly the events checked under *Ping role on* — any of `post.saved`, `post.posted`, `log.posted`, `news.posted` (intersected with the events the webhook is actually subscribed to). For `post.saved` the role ping rides alongside the co-author pings; for the announcement events it's the only thing in `content` (the embed stays silent). Role mentions only fire from `content`, which is why the opt-in is needed. Leave all boxes unchecked for no role ping.

### Pinging vs. not pinging

- **`post.saved` pings.** The whole point of the saved event is to alert co-authors that a draft they're on has a new revision. The other linked authors go in the `content` field as `<@id>` mentions (which is what actually triggers a Discord notification); the author who made the save is excluded. A configured `mention_role_id` is also pinged here as `<@&id>`.
- **Authors are only pinged on `post.saved`.** `post.posted`, `log.posted`, and `news.posted` are public announcements — bylines render plain "Rank Name" text and `content` carries no author mentions, so authors don't get pinged every time their content goes live.
- **A role can ping on any event** you opt into via *Mention role* — that's the one thing that puts a mention in `content` for the announcement events.

**Template variables**

| Variable | Meaning |
|---|---|
| `{sim_name}` | The sim's name from Nova settings |
| `{post_title}` | The post's title field |
| `{post_type}` | Always `Mission Post` for v1 |
| `{authors}` | Author list as plain `Rank Name` text (no mentions, no pings) — the default for the public `post.posted` byline |
| `{authors_plain}` | Alias for `{authors}` |
| `{authors_mentions}` | Author list with Discord `<@id>` mentions where linked, else plain text. Renders clickable but stays **silent** on `post.posted` (a mention only pings when it's in the payload `content` field, which `post.posted` leaves empty) |
| `{mission}` | Mission title (or `(no mission)` if unset) |
| `{location}` | Raw `post_location` field |
| `{timeline}` | Pulled from the ordered_mission_posts columns if that feature is on, else falls back to `post_timeline` |
| `{body}` | HTML-stripped, Markdown-flavoured body excerpt, smart-truncated to ~800 chars (excerpt only — the *Read the full post* link is the template's `{url}` line, not baked into the body) |
| `{url}` | Public post URL: `/sim/viewpost/{id}` |
| `{url_admin}` | Backend write URL: `/write/missionpost/{id}/view` |
| `{actor}` | Display name of the character whose user did the save |

The `log.posted` and `news.posted` templates share `{sim_name}`, `{title}`, `{authors}`, `{authors_plain}`, `{authors_mentions}`, `{body}`, `{url}`, `{url_admin}`, and `{actor}`. News additionally exposes `{category}`, `{type}` (`Public`/`Private`), and `{meta}` (the combined `Category · Type · by Author` line). `{post_title}` remains an alias for `{title}` on posts.

Author rendering rule: `<@discord_id>` if the linked Nova user has `nova_ext_discord_auth_id` set (requires the Discord Sign-In feature to be configured and the user to have linked their account), else `Rank First Last`. The list is joined human-friendly: `"A"`, `"A & B"`, `"A, B, & C"`.

### Generic JSON

Pick this for n8n, custom scripts, anything that wants the raw data. Same shape for both events:

```json
{
  "event":     "post.posted",
  "fired_at":  "2026-05-28T01:13:32+00:00",
  "post": {
    "id":         42,
    "title":      "The stars look very different",
    "content":    "<p>Lieutenant Jason...</p>",
    "status":     "activated",
    "mission_id": 4,
    "mission":    "UnderMind",
    "location":   "USS Excalibur - Intergalactic Void - Universe K-11",
    "timeline":   "Day 4 at 1900",
    "date":       "2026-05-28T01:13:00+00:00",
    "url_public": "https://yoursim.example/sim/viewpost/42",
    "url_admin":  "https://yoursim.example/write/missionpost/42/view"
  },
  "authors": [
    {
      "id":         123,
      "name":       "Alex Flynn",
      "rank":       "Commander",
      "rank_name":  "Commander",
      "discord_id": "111122223333444455",
      "user_id":    8
    }
  ],
  "actor": {
    "id": 123, "name": "Alex Flynn", "rank": "Commander",
    "discord_id": "111122223333444455", "user_id": 8
  },
  "sim": { "name": "USS Excalibur" }
}
```

Author `discord_id` will be `null` for characters whose linked user hasn't connected Discord (or for sims without the Discord Sign-In feature enabled at all).

**`log.posted`** uses a `log` object instead of `post`:

```json
{
  "event": "log.posted",
  "fired_at": "...",
  "log": {
    "id": 12, "title": "Personal Log, Stardate...", "content": "<p>...</p>",
    "status": "activated", "date": "...",
    "url_public": "https://yoursim.example/sim/viewlog/12",
    "url_admin":  "https://yoursim.example/write/personallog/12/view"
  },
  "authors": [ { "id": 123, "name": "Alex Flynn", "rank": "Commander", "discord_id": "...", "user_id": 8 } ],
  "actor":   { "id": 123, "name": "Alex Flynn", ... },
  "sim": { "name": "USS Excalibur" }
}
```

**`news.posted`** uses a `news` object with `category` and `type`:

```json
{
  "event": "news.posted",
  "fired_at": "...",
  "news": {
    "id": 7, "title": "Shore Leave Approved", "content": "<p>...</p>",
    "category": "Announcements", "type": "public",
    "status": "activated", "date": "...",
    "url_public": "https://yoursim.example/main/viewnews/7",
    "url_admin":  "https://yoursim.example/write/news/7/view"
  },
  "authors": [ ... ],
  "actor":   { ... },
  "sim": { "name": "USS Excalibur" }
}
```

`news.type` is `"public"` or `"private"`. The generic payload is sent regardless of the webhook's public/private filter setting only when the item matches that filter — i.e. the filter is applied before delivery, so you won't receive private items on a public-only webhook.

---

## Delivery

- **Fire-and-forget.** Single POST with a 2-second cURL timeout + 2-second connect timeout. We do not retry, queue, or block the save under any circumstance.
- **At-most-once.** A failed webhook is a failed webhook. The admin can see the failure on the manage page and click **Test** to verify the fix.
- **SSL verification on.** Production sims should use https:// webhook URLs. Self-signed certs will fail delivery.
- **Per-webhook status logging.** Every delivery updates `last_fired_at`, `last_status` (HTTP code or 0 for network/timeout), and `last_error` (response body or curl error, truncated to 500 chars).

---

## Worked example

A writer hits **Post** on a mission post. The Posts_model shim sees:

- `update_post(42, ['post_status' => 'activated', ...])`
- Previous `post_status` was `saved`.
- That's the `saved → activated` transition → fire `post.posted`.

Two webhooks are subscribed to `post.posted`:

1. **Discord** to `#mission-posts` channel, format = `discord`, default templates.
2. **n8n** to `https://n8n.example/webhook/abc`, format = `generic`.

The Discord webhook receives (note: empty `content` — `post.posted` doesn't ping):

```json
{
  "content": "",
  "embeds": [{
    "title":       "USS Excalibur Post | The stars look very different",
    "description": "*A mission post by Commander Alex Flynn, Zayin Theta-108, & The Commander*\n\n**Mission** - UnderMind\n**Location** - USS Excalibur - Intergalactic Void - Universe K-11\n**Timeline** - Day 4 at 1900\n\nLieutenant Jason Koloamatangi's excitement about being assigned the alpha shift helmsman...\n\n...\n\n[Read the full post](https://yoursim.example/sim/viewpost/42)",
    "url":         "https://yoursim.example/sim/viewpost/42",
    "color":       0x3498DB,
    "timestamp":   "2026-05-28T01:13:00+00:00"
  }]
}
```

The `post.posted` byline (`{authors}`) is plain "Rank Name" text — a public announcement shouldn't ping every author each time a post goes live. For the ping behaviour, that's `post.saved`: it puts every linked author's `<@id>` in the `content` field so they each get notified that a draft they're on changed.

The n8n webhook receives the structured JSON shown in the **Generic JSON** section. From there your flow can do whatever — repost to a different channel, archive to a spreadsheet, fan out to per-author DMs, whatever you want.

---

## Troubleshooting

### Webhook fires but no Discord message appears

Most common cause: the webhook URL is correct but the channel was deleted or the webhook itself was revoked from Discord. Click **Test** on the manage page; if you get `HTTP 404 Unknown Webhook` or similar, regenerate the URL in Discord's *Channel Settings → Integrations → Webhooks*.

### Webhook returns 401/403

Discord webhook URLs include their auth token in the path. If you accidentally truncated the URL when copy-pasting, you'll see 401. Re-copy the full URL.

### "Last status: 0 (network error)"

cURL couldn't reach the URL at all. Either the host doesn't exist, DNS failed, the port is closed, or your sim's web server blocks outbound HTTPS. Check the `last_error` column for the specific curl error message.

### `post.posted` doesn't fire when I edit an activated post

By design — `post.posted` is the *transition* event, not "post was saved while in activated status." As of **v1.32.0**, that's exactly what **`post.updated`** is for: subscribe the webhook to it and you'll get an event on every edit of an already-activated post. (`post.saved` still covers saved-status drafts only.)

### Authors aren't getting pinged

First check the event: only **`post.saved`** pings. `post.posted` is a public announcement and deliberately never pings (it shows plain "Rank Name" bylines). If you want a ping when a draft changes, subscribe a webhook to `post.saved`.

For `post.saved`, pinging requires the author's linked Nova user to have `nova_ext_discord_auth_id` set. That happens automatically when a user signs in via *Discord Sign-In* or links their Discord from the User Account page. Authors whose linked user hasn't connected Discord, or whose Nova user isn't linked to a character, render as plain text — they appear in the message but don't get pinged.

### My Discord template variables aren't substituting

Make sure they're surrounded by `{` and `}` exactly, no spaces inside. `{post_title}` works; `{ post_title }` won't. Variable names are case-sensitive.

---

## Security notes

- Webhook URLs are stored unencrypted in the database. Treat them like API keys &mdash; a leaked URL lets anyone post to your channel.
- The feature is admin-only. Only sysadmins with `site/settings` access can create or modify webhooks.
- Fire-and-forget delivery means the worst case for a slow/malicious webhook URL is a 2-second pause on every post save. We never expose webhook responses back to the user. If you suspect a webhook URL is being abused, **Disable** or **Delete** it from the manage page immediately.
- Always use HTTPS URLs. Plain HTTP webhook URLs will work but the payload (including author Discord IDs) travels in clear text.
