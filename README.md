# Sim Central Suite - A [Nova](https://anodyne-productions.com/nova) Extension

<p align="center">
  <a href="https://github.com/reecesavage/sim-central-suite/releases/tag/v1.17.2"><img src="https://img.shields.io/badge/Version-v1.17.2-brightgreen.svg"></a>
  <a href="http://www.anodyne-productions.com/nova"><img src="https://img.shields.io/badge/Nova-v2.7.19+-orange.svg"></a>
  <a href="https://www.php.net"><img src="https://img.shields.io/badge/PHP-v8.2+-blue.svg"></a>
  <a href="https://opensource.org/licenses/MIT"><img src="https://img.shields.io/badge/license-MIT-red.svg"></a>
</p>

One Nova extension that consolidates nine sim-management features behind a single admin dashboard. Toggle each feature on or off independently, configure them in one place, and let the suite manage the database columns, controller shims, and menu plumbing so you don't have to install or update each one separately.

This release rolls up:

- **Display Name** &mdash; custom display names on the manifest in place of First/Last/Suffix
- **Anti Spam Questions** &mdash; random security question on the join + contact forms
- **Mission Post Summary** &mdash; short TL;DR field on long posts, also shown in emails and RSS
- **URL Parser** &mdash; site-wide shortcode tags that expand to anchors (`[docs|getting-started]`)
- **Ordered Mission Posts** &mdash; order posts by Day/Time, Date/Time, or Stardate; optional post numbering; configurable date + time display formats; HTML5 native date/time inputs; inline word counts on the missions pages
- **Content Filter** &mdash; age-gates mission post bodies on the public site and in the RSS feed for sims that permit adult language, violence, or sexual content; configurable default for the per-post toggle; writer attests at submit time
- **Discord Sign-In** &mdash; "Sign in with Discord" / "Sign up with Discord" via the [Sim Central Broker](https://github.com/reecesavage/sim-central-broker); one Discord app serves any number of sims (no per-sim redirect URI registration); link/unlink controls on User Account; optional site-wide enforcement that requires every user to keep Discord linked; optional Discord-only sign-in mode with a sysadmin email + password escape hatch; optional gate by Discord server membership (any-of / all-of); Discord-branded buttons everywhere
- **REST API** *(v1.9.1+)* &mdash; HTTP API for external integrations (n8n flows, scripts, dashboards, mobile apps); admin-issued bearer tokens with per-scope grants (`posts:read`, `characters:read`, `missions:read`, plus write scopes `users:write`, `webhooks:read`, `webhooks:write` *(v1.14.0+)* and post-authoring scopes `posts:read.own` / `posts:write` / `posts:delete` + `*.all` sysadmin variants *(v1.15.0+)*, and `tokens:read` / `tokens:write` *(v1.16.0+)*); read endpoints for posts/characters/missions, `GET /me` identity, **post authoring** (create/update/delete, save or activate) for user-bound tokens, **API token management** (list/create/revoke/delete, sysadmin-bound) *(v1.16.0+)*, plus write endpoints to disable/reactivate users + their characters and manage event webhooks; per-token rate limiting; one-time-display token reveal; surface suite-feature fields (summary, ordered post day/time, display name, etc.) in the JSON when those features are on
- **Mobile Site** *(v1.17.0+)* &mdash; a lightweight, phone-friendly site at `/mobile` where members log in and write, save, post, edit, or delete their own mission posts without fighting Nova's desktop views on a phone. Honours Discord Sign-In settings on login (Discord-only sims show the Discord button, not a password form), enforces Nova's permissions, and reuses the suite's post engine so webhooks/emails/ordered-timelines/moderation behave identically to the desktop site.
- **Event Webhooks** *(v1.11.0+)* &mdash; fire HTTP webhooks when posts are saved or activated. Discord-formatted (rich embed with @mentions of linked authors via stored Discord IDs) or generic JSON (for n8n etc.). Multiple webhooks per event, per-webhook event subscriptions, customisable Discord embed templates for `post.posted`, `log.posted`, and `news.posted` *(v1.13.0+)*, an optional per-event Discord role ping *(v1.13.0+)*, **Test** button for each webhook, fire-and-forget delivery with status visibility (last_status + last_error per webhook).

## Requirements

- Nova **2.7.19+**
- PHP **8.2+**
- PHP **cURL** extension (used by the daily update check, the one-click updater, and the Discord Sign-In JWKS fetcher)
- PHP **ZipArchive** extension (used by the one-click updater; ships with almost every PHP build)
- PHP **OpenSSL** extension (used by Discord Sign-In to verify broker-issued JWTs; ships with virtually every PHP build)

## Installation

1. Copy the entire directory into `application/extensions/nova_ext_sim_central`.
2. Add the following to `application/config/extensions.php`:
   ```php
   $config['extensions']['enabled'][] = 'nova_ext_sim_central';
   ```
3. Visit your admin control panel and choose **Sim Central Suite** under Manage Extensions. Every feature is OFF by default.

## Updating

### One-click in-app update (recommended, v1.1.0+)

When a newer published release exists on GitHub, the dashboard banner shows an **Update Now** button next to the *Update available: vX.Y.Z* notice. Clicking it (with a confirm prompt) will:

1. Download the release zipball from GitHub.
2. Validate the archive (expects `init.php` + `config.json`, and the version field in the new `config.json` must match the release tag).
3. Rename the current extension folder to `nova_ext_sim_central.backup-YYYYMMDD-HHMMSS` next to the live folder.
4. Move the freshly-extracted code into place.
5. Recursively invalidate the PHP opcache so the next request reads the new bytecode.
6. Render a "reload to finish" page; click the button and you're on the new version.

Your settings row in the `nova_settings` table is **untouched** through the whole flow, so feature toggles, edited labels, and per-feature settings carry across unchanged.

**If anything goes wrong:** the backup folder is kept indefinitely (we never auto-prune). Roll back from the shell:

```sh
cd application/extensions
rm -rf nova_ext_sim_central
mv nova_ext_sim_central.backup-YYYYMMDD-HHMMSS nova_ext_sim_central
```

**Requirements for the in-app updater:**

- The web user (e.g. `www-data`, `nginx`, your PHP-FPM user) needs write access to `application/extensions/` *and* the existing `application/extensions/nova_ext_sim_central/`.
- PHP `cURL` and `ZipArchive` extensions must be loaded.

Hosts that deploy via SSH/git under a separate user from the web user will see a clear "directory not writable" error on the dashboard. Manual upgrade still works in that case &mdash; see below.

### Manual update (any release)

1. Back up the existing folder: `cp -r application/extensions/nova_ext_sim_central application/extensions/nova_ext_sim_central.backup`
2. Replace the folder contents with the new release.
3. Reload the dashboard. The state row in the database survives the swap; the new version reads it on first load.

## Migrating from the standalone extensions

The suite replaces these standalone Nova extensions:

| Suite feature           | Standalone extension                  |
| ----------------------- | ------------------------------------- |
| Display Name            | `nova_ext_display_name`               |
| Anti Spam Questions     | `nova_ext_anti_spam_questions`        |
| Mission Post Summary    | `nova_ext_mission_post_summary`       |
| URL Parser              | `nova_ext_url_parser`                 |
| Ordered Mission Posts   | `nova_ext_ordered_mission_posts`      |
| Content Filter          | `nova_ext_content_filter`             |
| Discord Sign-In         | `nova_ext_discord_account_confirmation` |

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

When you upgrade the suite (either via the in-app updater or manually), the new `config.json` ships fresh defaults but your settings row is left alone. On load the two are deep-merged with your state winning, so customisations persist and any newly-added defaults flow through.

## Update checking

The dashboard checks GitHub once every 24 hours for a newer published release and shows an *Update available* banner if one exists. The check uses a 2&ndash;3 second timeout and degrades silently on network or rate-limit failure, so it never blocks the admin page. Cached result lives in a separate `settings` row (`setting_key = 'sim_central_update_check'`); you can clear it at any time if you want to force a re-check.

Since v1.2.1 the dashboard also shows a *"Last checked X ago"* indicator next to the version line, with a **Check now** button that bypasses the 24h cache and refreshes the check on demand &mdash; useful when you know a new release just dropped and don't want to wait or run SQL.

Since v1.6.0 the same cached result also surfaces on Nova's admin home page (`/admin/index`) for gamemasters &mdash; as of v1.6.1, as a row at the top of the **Notifications** panel (the Notifications nav badge increments to match). The row links to the suite dashboard (where the one-click *Update Now* button lives) and to the GitHub release notes. Non-GMs never see it. Nothing renders if the cache is empty or the installed version is already current.

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

Date and time inputs on the admin forms are **native HTML5** (`<input type="date">` / `<input type="time">`) &mdash; no jQuery datepicker / timepicker dependency, native calendar UI with year/month navigation, and they work on mobile.

**Display format** is configurable on the *Ordered Mission Posts &rarr; Configure* page (v1.1.1+):

- Date: `YYYY-MM-DD`, `YYYY/MM/DD`, `MM/DD/YYYY`, or `DD/MM/YYYY`
- Time: 24-hour (`23:00`) or 12-hour with AM/PM (`11:00 PM`)

Storage is unchanged regardless of the format setting &mdash; values are always stored as ISO `YYYY-MM-DD` for dates and `HHmm` for times. Only the rendered output on the public mission view, post view, RSS feed, post emails, and posts list goes through the formatter.

With **Post Numbering** enabled, each post's title is prefixed with its 1-based chronological position (`Post 1`, `Post 2`, ...) on the website, in post emails, and in the RSS feed.

**Legacy mode**: if `chronological_mission_posts` columns are still present on the posts table, the per-feature config page exposes a toggle that lets existing missions reuse those Day/Time values when set to Day Time.

The suite also shows inline word counts per mission on both the admin **Manage Missions** page (current / upcoming / completed) and the public `/sim/missions` page. Counts are computed in a single batched query per page load.

### Content Filter (v1.2.0+, simplified in v1.5.0)
Age-gates mission post bodies on the public site and in the RSS feed for sims that permit explicit content. Useful for protecting minors who hit the public site and for keeping mature content out of automated RSS-driven Discord broadcasts.

Adds a `nova_ext_content_filter_age_gated` column to `posts` (TINYINT, default `1`). Configure on the *Content Filter &rarr; Configure* page:

- **Permitted explicit content** &mdash; three independent yes/no toggles, one each for **Adult language**, **Violence**, and **Sex**. The filter activates as soon as any are ticked. Definitions follow [rpgrating.com](https://rpgrating.com/create); all three are admin-editable.
- **New posts are age-gated by default** &mdash; controls the initial state of the per-post toggle. **On** (default) means writers have to deliberately untick the box to publish ungated; **off** means writers opt IN per post (useful for sims with rare explicit content).
- **Also confirm when saving a draft** *(v1.14.1+)* &mdash; the submit-confirmation popup (shown when a writer leaves the age-gate toggle off) fires **only on Post** by default, since drafts aren't public. Tick this to also show it when clicking **Save**. **Off** by default; the popup never fires on **Delete**.

When the filter is active, the write/edit-post form gains an **Age-gate this post** checkbox. The helper text shows only the definitions for whichever dimensions are actually permitted on this sim. If a writer unchecks the box, clicking **Post** triggers a JS confirm that names exactly what they're attesting to (and **Save** too, when the option above is on).

For gated posts viewed by guests (logged-out users):

- **`/sim/viewpost/N`** &mdash; body replaced with an editable notice (default: *"This post is rated for mature audiences. Log in to view the full content."*).
- **`/feed/posts`** RSS feed &mdash; entry header (title, authors, mission, timeline, location) preserved so feed consumers know a post exists, but the body is replaced with the same notice.
- **`/sim/listposts`** and `/sim/missions/id/N` &mdash; unchanged (they never showed the body anyway).

Logged-in users always see everything regardless of gating. If your sim permits none of the three dimensions, the feature has nothing to gate and can be left disabled.

### Discord Sign-In (v1.3.0+)
Lets users sign in to the sim with their Discord account, link Discord to an existing sim account, or attach a Discord identity to a new account during the normal join flow. The actual Discord OAuth dance happens in the [Sim Central Broker](https://github.com/reecesavage/sim-central-broker) (a small Cloudflare Worker hosted at `auth.simcentral.host`), so this sim never has to be registered as a redirect URI in any Discord app. The broker mints a short-lived RSA-signed JWT and bounces the user back to the sim, which verifies the signature locally with the broker's public key.

Adds five columns to `users`: `nova_ext_discord_auth_id` (UNIQUE-indexed Discord snowflake), `_username`, `_avatar`, `_email_verified`, `_linked_at`. Configure on the *Discord Sign-In &rarr; Configure* page:

- **Broker URL** &mdash; defaults to `https://auth.simcentral.host`; override only if you've self-hosted your own broker.
- **Broker public key (PEM)** &mdash; paste the broker's RSA public key, or click **Fetch from broker JWKS** to retrieve it automatically from `<broker>/.well-known/jwks.json`.
- **Require linking Discord to join** *(v1.3.1+)* &mdash; when on, the join form refuses to submit unless the user has clicked "Link Discord" first. Client-side enforcement; admins should still verify at character-approval time if strictness matters.
- **Require all users to keep Discord linked** *(v1.4.0+)* &mdash; site-wide enforcement. Logged-in users without a Discord ID are redirected to a dedicated forced-link page on every request until they link. Unlinking is disabled while this is on; users can still <em>change</em> to a different Discord account. The email + password login form itself is NOT blocked &mdash; users can still sign in with their sim password (so they aren't locked out if Discord OAuth is down), they just can't navigate anywhere except the forced-link page until linking is finished.
- **Required Discord guild membership** *(v1.7.0+)* &mdash; optional gate by Discord server membership. Paste one or more Discord guild snowflake IDs, pick **Any of** (default) or **All of**, write a help-text snippet (HTML allowed for invite-link anchors). Users not satisfying the gate are shown a refusal page with the help text. Requires broker v1.1.0+; the suite passes `?guilds=1` to opt into the broker's `guilds` scope only when this is configured (so sims without a guild check see no change to their Discord consent prompt). No bot required &mdash; uses Discord's OAuth `guilds` scope on the user's own access token.
- **Lock sign-in to Discord** *(v1.8.0+)* &mdash; hide the email + password form on the login page (revealable behind a "Sysadmin sign-in" toggle) and bounce every non-sysadmin who signs in via email + password to a forced Discord sign-in page on every request. Sysadmins can still use email + password (escape hatch for when Discord OAuth is down). Implicitly enables *Require all users to keep Discord linked*. The forced page adapts: "Link Discord" if they're not linked yet, "Sign in with Discord" if they are linked but haven't authenticated via the Discord flow this session.

The suite always rejects Discord accounts whose email isn't verified (enforced at both the broker and the suite as defense-in-depth).

UI additions when enabled (all using Discord-branded buttons in Discord Blurple `#5865F2` with the official Clyde mark):
- **Sign in with Discord** button on the login form &mdash; logs the user in if their Discord ID is already linked to a sim account.
- **Link Discord** card at the top of the join form &mdash; pre-fills email from Discord and stamps the Discord identity onto the new user row when the join form is submitted. The character still queues for GM approval like any other join.
- **Link Discord / Unlink Discord / Change Discord account** section on the **User &rarr; My Account** page. The action shown depends on whether linking is required globally: optional mode shows Unlink, required mode shows Change instead. Unlink is gated behind a "you need a password set" check so users can't lock themselves out.
- **Forced-link page** at `/extensions/nova_ext_sim_central/DiscordAuth/required` &mdash; the one-page landing the enforcement hook bounces unlinked users to when global require is on.

The suite does not auto-create user accounts from Discord sign-ins &mdash; every new user goes through Nova's normal join flow (including character approval). Discord auth is identity attachment, not a join bypass.

For complete self-hosting instructions, broker architecture, and the security model, see <https://github.com/reecesavage/sim-central-broker>.

### Mobile Site *(v1.17.0+)*

Nova's front-end isn't responsive, so the sim is rough on a phone. The Mobile Site is a separate, deliberately minimal interface (its own mobile-first HTML/CSS, not a restyle of the desktop skin) at **`/mobile`** where members manage their mission posts on the go.

- **Login** uses Nova's own auth and **honours the Discord Sign-In feature**: if the sim enforces Discord-only login, the mobile login shows only the "Sign in with Discord" button (no password form); otherwise it shows both. Discord sign-ins run through the existing broker flow (guild checks and required-link enforcement included) and return to `/mobile`.
- **Posts**: members see their drafts and recent posts, then create / edit / **save** (draft) / **post** (activate) / delete — limited to posts they author, exactly like the desktop site. Co-authors can be added; at least one of the member's own characters is required.
- **Shared engine**: all writes go through the same `PostWrite` path as the REST API, so the `post.saved` / `post.posted` webhooks, save/post emails, ordered-mission-post timelines (validated against each mission's scheme), and per-user moderation all behave identically.
- **The clean `/mobile` URL** is served by a tiny `pre_system` hook (auto-registered in `application/config/hooks.php` when you configure the feature, with a manual fallback shown if that file isn't writable). The full URL `/extensions/nova_ext_sim_central/Mobile/index` always works too.
- **Off by default**; enable under *Sim Central Suite &rarr; Mobile Site*. Phase 1 is mission posts; personal logs and news may follow.

### REST API *(v1.9.1+)*

Exposes an HTTP API for external integrations &mdash; n8n workflows, automation scripts, dashboards, anything that needs to read mission posts, characters, or missions, or to manage user activation status and event webhooks, programmatically. The API is **off by default** and stays invisible (every endpoint 404s) until enabled; once on, authentication is by admin-issued bearer token only. There is no fallback to Nova session cookies, and there is no per-user self-service token issuance &mdash; only sysadmins with `site/settings` access can create or revoke tokens. Read endpoints use `*:read` scopes; the mutating endpoints *(v1.14.0+)* are gated behind `users:write` / `webhooks:write` &mdash; issue those only to trusted automation. **Post authoring** *(v1.15.0+)* binds a token to a Nova user so it can create/update/delete that user's posts; the `*.all` variants are sysadmin bypasses.

Adds one table: `sim_central_api_tokens` (which becomes `<dbprefix>sim_central_api_tokens` on disk &mdash; typically `nova_sim_central_api_tokens`). Columns: label, hashed token + display prefix, JSON scope list, created/last-used/expires/revoked timestamps, per-token rate counter. Configure on the *REST API &rarr; Configure* page:

- **Create token** &mdash; pick a free-form label, tick the scopes, optionally **bind a user** (required for the post `read.own`/`write`/`delete` scopes, which act as that user) and set an expiry. The raw token is shown **exactly once** on the next page render &mdash; copy it immediately. Only its SHA-256 hash survives in the database; if you lose the token, revoke and re-issue.
- **Revoke** &mdash; soft-disable a token. Sets `revoked_at`, preserves the row for audit, and starts returning `401 Token has been revoked.` to any client still using it.
- **Delete** &mdash; hard removal for cleanup. Confirm dialog because it's irreversible.
- **Rate limit** &mdash; rolling 60-second window per token, default 60 requests/minute. Override via the `rest_api_rate_limit_per_minute` setting (set to `0` to disable). Exceeding it returns `429`.

Tokens look like `scapi_<40 hex chars>` and authenticate via the `X-API-Key: scapi_...` header. Apache strips the standard `Authorization` header before PHP can see it on most shared hosts, so the suite uses `X-API-Key` exclusively &mdash; works on every install with no server config.

Endpoints (all under `/extensions/nova_ext_sim_central/Api/`):

| Method | Path | Scope |
|---|---|---|
| `GET` | `/ping` | any valid token |
| `GET` | `/me` | any valid token *(user-bound)* |
| `GET` | `/posts` *(filters: `?mission=`, `?status=`, `?page=`, `?per_page=`)* | `posts:read` / `posts:read.own` / `posts:read.all` |
| `GET` | `/posts/{id}` | `posts:read` / `posts:read.own` / `posts:read.all` |
| `POST` | `/posts` *(create; save or activate)* | `posts:write` |
| `PATCH`/`PUT` | `/posts/{id}` *(update; `body_mode` replace/append)* | `posts:write` |
| `DELETE` | `/posts/{id}` | `posts:delete` |
| `GET` | `/characters` *(filters: `?status=`, `?page=`, `?per_page=`)* | `characters:read` |
| `GET` | `/characters/{id}` | `characters:read` |
| `GET` | `/missions` *(filters: `?status=`, `?page=`, `?per_page=`)* | `missions:read` |
| `GET` | `/missions/{id}` | `missions:read` |
| `POST` | `/users/disable` *(body: `user_id` or `discord_id`)* | `users:write` |
| `POST` | `/users/reactivate` *(body: `user_id`/`discord_id`, optional `reactivate_characters`)* | `users:write` |
| `GET` | `/webhooks` &middot; `/webhooks/{id}` | `webhooks:read` |
| `POST` | `/webhooks` *(create)* &middot; `PATCH`/`PUT` `/webhooks/{id}` *(update)* &middot; `DELETE` `/webhooks/{id}` | `webhooks:write` |
| `GET` | `/tokens` &middot; `/tokens/{id}` | `tokens:read` *(sysadmin-bound)* |
| `POST` | `/tokens` *(create)* &middot; `PATCH` `/tokens/{id}` *(revoke)* &middot; `DELETE` `/tokens/{id}` | `tokens:write` *(sysadmin-bound)* |

The write endpoints *(v1.14.0+)*: **user activation** &mdash; `disable` sets the user `inactive` and flips their *active* linked characters to `inactive`; `reactivate` sets the user `active` and (unless `reactivate_characters=false`) flips their *inactive* characters back. Only the `active`↔`inactive` transition is touched &mdash; `pending` and `npc` characters are left alone. Users are addressable by `user_id` or by `discord_id` (the latter requires the *Discord Sign-In* feature, else `409`). **Webhook management** mirrors the ACP form (shared validation) and requires the *Event Webhooks* feature on (`409` if off); create/update bodies accept the full webhook field set (label, url, format, events, templates, role ping, etc.).

**Token management** *(v1.16.0+)*: list/create/revoke/delete API tokens over the API (`tokens:read` / `tokens:write`), the same actions as the ACP token page. Every token endpoint requires the calling token to be bound to a **sysadmin** user (`403` otherwise), mirroring the ACP's `site/settings` gate. Create returns the raw token exactly once; the hash is never exposed. Treat a `tokens:write` token as highly privileged &mdash; it can mint tokens with any scope.

**Post authoring** *(v1.15.0+)*: tokens **bound to a user** (selected on the token page) can create, update, and delete that user's mission posts &mdash; built so mobile apps can post where Nova's web UI is awkward. A bound token reads its own drafts via `posts:read.own`, writes via `posts:write` (save **or** activate; `body_mode=append` to append to an existing body), and deletes via `posts:delete`. The saving character (and webhook `actor`) is derived from the user's characters on the post (main char if present, else highest rank). Activating runs through Nova's own model path, so it fires the `post.posted` webhook, stamps `last_post`, sends the crew email, and honours per-user moderation (moderated authors land as `pending`); the *Ordered Mission Posts* and *Content Filter* columns are populated by their existing hooks. Sysadmin-bound tokens may carry `posts:read.all` / `posts:write.all` / `posts:delete.all` to reach any post. `GET /me` reports the bound user, their characters, and the token's scopes. See [`REST_API.md`](REST_API.md) for the full request/response detail.

Response JSON uses whitelisted, documented fields &mdash; not raw column dumps &mdash; so internal schema churn doesn't leak through. Suite-feature fields are **layered on conditionally**: when *Mission Post Summary* is on, posts gain a `summary` key; when *Ordered Mission Posts* is on, posts gain an `ordered` object and missions gain ordering config; when *Display Name* is on, characters gain `display_name` and a precomputed `preferred_name`; when *Content Filter* is on, posts gain an `age_gated` boolean (full content is still returned &mdash; the flag lets consumers decide whether to redact). Field *presence* is the signal that a feature is enabled &mdash; consumers can detect what's available without an extra config endpoint.

Designed primarily for [n8n](https://n8n.io/) consumers but works with any HTTP client. See [`REST_API.md`](REST_API.md) for the full endpoint reference: every parameter, every response field, curl + n8n examples, and the error-code matrix.

**API Explorer + OpenAPI spec** *(v1.10.0+)*: The suite ships an interactive explorer at *REST API &rarr; API Explorer* &mdash; lists every endpoint, lets you fire requests against the live API from the admin page, and shows the JSON response inline. Every endpoint also has a "Copy curl" button. The same catalog is exposed as a machine-readable **OpenAPI 3.0** document at `/extensions/nova_ext_sim_central/Api/openapi` (public when the feature is on, 404 when off &mdash; same as every other endpoint), importable into Postman, Insomnia, Stoplight, n8n's OpenAPI nodes, or any other OpenAPI-aware tooling.

### Event Webhooks *(v1.11.0+)*

Fires HTTP webhook notifications when content changes state. Events:

- `post.saved` &mdash; a draft mission post was created or saved (status = saved). Useful for nudging co-authors that a draft has been updated and needs their attention. Discord pings the authors.
- `post.posted` &mdash; a mission post transitioned from non-activated to activated (i.e. publicly posted). The main announcement event.
- `log.posted` *(v1.12.0+)* &mdash; a personal log was activated. Simpler payload: author, title, content.
- `news.posted` *(v1.12.0+)* &mdash; a news item was activated. Payload adds category and type (public/private). Each webhook chooses which news types it wants (**Public** / **Private** / **Both**, default Public).

Logs and news fire on activation only (no saved event).

Each webhook is one row in `sim_central_webhooks` (label, URL, format, events subscription, enabled flag, optional Discord templates, optional role-ping config, last-fired metadata). Multiple webhooks per event are supported. Two formats:

- **Discord** &mdash; renders a rich embed at the webhook URL (paste your Discord channel's webhook URL straight in). For `post.posted` the embed mimics the layout a lot of sims build by hand in n8n today: title with sim name, italic *"A mission post by..."* byline, **Mission** / **Location** / **Timeline** fields, smart-truncated body excerpt (HTML stripped, basic Markdown applied), random embed colour, *Read the full post* link. Authors with a linked Discord ID (via the *Discord Sign-In* feature) are mentioned via `<@discord_id>` in the message `content` so they actually get a notification ping; un-linked authors render as plain "Rank First Last". For `post.saved` the format is fixed and lighter (title + author mentions + saved-by + admin link) so co-authors see "hey, a draft you're on changed" &mdash; and *(v1.13.0+)* the author who actually saved is no longer pinged about their own edit.
- **Generic JSON** &mdash; flat structured payload (`event`, `post`, `authors`, `actor`, `sim`, `fired_at`). Use for n8n, scripts, or any tool that wants the raw shape and will do its own formatting downstream.

Discord embed templates are admin-customisable per-webhook for `post.posted`, `log.posted`, and `news.posted` *(logs/news added in v1.13.0)*. Common variables: `{sim_name}`, `{title}` (alias `{post_title}` on posts), `{authors}`, `{authors_plain}`, `{authors_mentions}`, `{body}`, `{url}`, `{url_admin}`, `{actor}`. Posts add `{post_type}`, `{mission}`, `{location}`, `{timeline}`; news adds `{category}`, `{type}`, and `{meta}` (the combined byline). Leave a field blank to use the bundled default. `post.saved` stays a fixed lightweight ping.

**Role ping** *(v1.13.0+)*: each webhook can store a Discord **role ID** plus a per-event opt-in list. When set, the role is pinged via `<@&id>` in the message `content` on exactly the events you check (`post.saved`, `post.posted`, `log.posted`, `news.posted`). This is the one mention that can ping on the announcement events &mdash; author bylines stay silent in the embed. Leave the boxes unchecked for no role ping.

Delivery is **fire-and-forget** with a 2-second cURL timeout. We never retry, never queue, never block the save &mdash; a slow or broken webhook URL cannot make the writer page hang. Each webhook row stores `last_fired_at`, `last_status` (HTTP code or 0 for network error), and `last_error` (truncated response or curl error message), which the manage page surfaces inline so admins can spot broken integrations at a glance. Each webhook also has a **Test** button that fires a synthetic event with realistic-looking dummy data, letting you verify wiring before saving the first real post.

Hooks into Nova at the model level via `Posts_model` shims (one for `create_mission_entry`, one for `update_post`) &mdash; same managed-block pattern the other suite features use. Disabling the feature removes both shim blocks; the table stays for audit.

See [`WEBHOOKS.md`](WEBHOOKS.md) for the full reference: every template variable, every JSON payload field, and worked-example Discord embed output.

## Reset / uninstall

- **Reset state to defaults**: `DELETE FROM nova_settings WHERE setting_key = 'sim_central_state';` &mdash; on the next page load, state is re-seeded from `config.json` (all features off).
- **Force an update re-check**: `DELETE FROM nova_settings WHERE setting_key = 'sim_central_update_check';`
- **Remove a shim**: disable the corresponding feature from the dashboard. The shim block is stripped from the target controller, unless another still-enabled feature shares the same file (e.g. summary and ordered both inject into `Feed.php`'s `posts()`).
- **Full uninstall**: disable every feature (so all shims are removed), then comment out / remove the `$config['extensions']['enabled'][] = 'nova_ext_sim_central';` line and delete the extension folder. Database columns are intentionally left in place.
- **Clean up update backups**: the in-app updater leaves the previous version in `application/extensions/nova_ext_sim_central.backup-YYYYMMDD-HHMMSS/`. Delete these by hand once you're satisfied with the upgrade. The suite never auto-prunes them.
- **Unlink everyone's Discord at once**: `UPDATE nova_users SET nova_ext_discord_auth_id = NULL, nova_ext_discord_auth_username = NULL, nova_ext_discord_auth_avatar = NULL, nova_ext_discord_auth_email_verified = NULL, nova_ext_discord_auth_linked_at = NULL;` &mdash; useful if you ever rotate the broker (changing public keys would invalidate every existing token but already-linked rows would still work for matching).
- **Revoke every REST API token at once**: `UPDATE nova_sim_central_api_tokens SET revoked_at = NOW() WHERE revoked_at IS NULL;` &mdash; useful if you suspect a token leak or are handing the sim to a new admin. Or drop the table outright to wipe the audit history too: `DROP TABLE nova_sim_central_api_tokens;` (recreate via *REST API &rarr;* **Setup database** if you re-enable the feature later).
- **Disable every webhook at once**: `UPDATE nova_sim_central_webhooks SET enabled = 0;` &mdash; stops all deliveries without losing the URL configuration. Or drop the table to wipe everything: `DROP TABLE nova_sim_central_webhooks;` (recreate via *Event Webhooks &rarr;* **Setup database** if you re-enable later).

## Issues

Report bugs or feature requests at: <https://github.com/reecesavage/sim-central-suite/issues>

## License

Copyright &copy; 2026 Reece Savage.

This module is open-source software licensed under the **MIT License**. The full text of the license may be found in the `LICENSE` file.
