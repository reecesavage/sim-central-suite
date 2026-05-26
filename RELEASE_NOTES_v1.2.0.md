# Sim Central Suite v1.2.0 — Content Filter

New feature that age-gates mission post bodies on the public site and in the RSS feed for sims that allow explicit sexual content or violence (rating 3 on the [rpgrating.com](https://rpgrating.com/create) Sex / Violence scales). Designed for two main use cases: protecting minors who hit the public site, and keeping mature content out of automated RSS-driven Discord broadcasts.

## What's new

### 🛡️ Content Filter feature
Toggle on the Sim Central Suite dashboard. When enabled:

- **Configurable sim ratings** (Language / Sex / Violence, each 0–3) on the *Content Filter → Configure* page. Language is recorded for completeness but never triggers filtering — only Sex 3 or Violence 3 do.
- **Per-post age-gate toggle** on the write/edit-post form, rendered only when the sim allows Sex 3 or Violence 3. Default: **checked** (post is gated). The writer can untick it to mark a specific post as safe for public viewing.
- **Dimension-aware definitions** under the toggle. A 322 sim (Violence only at 3) doesn't show the sexual-content definition. A 333 sim shows both. The writer is only ever asked to attest to what the sim actually allows.
- **Submit-time confirm.** When the toggle is unchecked and the writer submits, a JS confirm names exactly what they're attesting to and gives one last chance to reconsider.

### Guest experience when a post is gated
- **`/sim/viewpost/N`** — body replaced with an editable notice (default: *"This post is rated for mature audiences. Log in to view the full content."*).
- **`/feed/posts`** RSS feed — entry still includes title, authors, mission, timeline, and location so feed consumers know a post exists, but the body is the same notice. Keeps mature content out of public Discord channels and other RSS-driven aggregators.
- **Post listings** (`/sim/listposts`, `/sim/missions/id/N`) — unchanged. They never showed the body anyway.

### Logged-in users
Always see everything regardless of gating.

## Database change

One new column added to `posts` when you enable the feature:

| Column | Type | Default |
| --- | --- | --- |
| `nova_ext_content_filter_age_gated` | TINYINT | `1` |

The default is `1` (gated) — safer to over-gate a row that hasn't been migrated than to leak a body. Click **Set Up Database** on the dashboard row to apply.

## Replacing the standalone

The suite registers `nova_ext_content_filter` as the standalone equivalent. If you're running it in `extensions.php`, the dashboard shows the usual **Disable Standalone** button — click to strip the enable line, invalidate opcache, and remove the standalone's menu item. Feed.php keeps a fallback path that uses the standalone's sim-wide check, so guests are protected during the brief migration window even if you forget to enable the suite feature immediately after disabling the standalone.

## What's NOT in v1.2.0

- **Bio / news / personal-log gating** — posts only, by design. Add as a separate feature later if needed.
- **Per-post Language gating** — only Sex and Violence trigger filtering, even if Language is at 3.
- **Auto-detect explicit content** — fully writer-attested. The toggle defaults to gated; the writer opts out manually with the confirm step.

## Upgrade

Use the **Update Now** button on the dashboard (v1.1.0+). After the reload, enable the *Content Filter* feature, click **Set Up Database** (adds the new column), set your sim's ratings, customise the definition text and notice text to taste, and you're done.

## Credits

Same as v1.0.x / v1.1.x. MIT licensed. Rating model from [rpgrating.com](https://rpgrating.com/create).

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
