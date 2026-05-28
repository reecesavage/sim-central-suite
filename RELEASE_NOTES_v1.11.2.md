# Sim Central Suite v1.11.2 &mdash; Webhook polish

Bug-fix + polish release for the Event Webhooks feature shipped in 1.11.0. Discord delivery works great; these are the rough edges from first real-world use.

## What's fixed

### 1. Duplicate "Read the full post" link on `post.posted`

The body excerpt (`{body}`) was appending its own *Read the full post* link, and the default description template *also* ended with `[Read the full post]({url})` &mdash; so the link showed up twice in every posted-post embed.

`smartTruncate()` no longer appends the link; it just truncates the excerpt with an ellipsis. The link now comes solely from the template's `{url}` line. If you wrote a custom description template that relied on the old behaviour, add a `[Read the full post]({url})` line yourself (the default template already has one).

### 2. `post.posted` was pinging authors &mdash; it shouldn't

A public post announcement was tagging every linked author with `<@id>` mentions, pinging them every time one of their posts went live. That's noise. `post.posted` now renders the byline as plain "Rank Name" text (matching the intended look: *"A mission post by Commander Alex Flynn, Zayin Theta-108, The Commander"*) and sends an empty `content` field, so nobody gets pinged.

Pinging is now exclusive to **`post.saved`** &mdash; which is the event where alerting co-authors ("hey, a draft you're on changed, go look") is the whole point.

The `{authors}` template variable now resolves to plain text. A new `{authors_mentions}` variable is available if you specifically want clickable (but still silent, on posted) mentions in a custom template.

### 3. "Open in writer" → "View the post"

The `post.saved` embed's link label was "Open in writer". Renamed to "View the post" (it still points at the backend `/write/missionpost/{id}/view`, which is where a draft lives &mdash; drafts aren't on the public site yet).

### 4. Long webhook URLs overflowed the manage table

A full Discord webhook URL is long enough to push the row's action buttons off the right edge of the screen, making Disable/Delete unreachable. The URL cell now wraps (`word-break` + a max width), so the action buttons stay on screen regardless of URL length.

## Upgrade

Use the **Update Now** button on the dashboard. Code-only change &mdash; no DB updates, no shim changes. Existing webhooks keep their config; the next `post.posted` delivery will have the single link + plain byline, and the manage table will wrap long URLs.

If you'd customised a Discord description template before this release and want to double-check it: open *Event Webhooks &rarr; Configure &rarr; Edit* on the webhook and confirm the body reads how you expect. The variable list on that page now documents the plain-vs-mention distinction.

## Credits

Same as v1.11.0. MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
