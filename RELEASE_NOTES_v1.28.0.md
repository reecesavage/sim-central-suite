# Sim Central Suite v1.28.0 — Word counts in the API, editable token scopes

Two REST API enhancements from the GitHub issue tracker.

## What's new

### Word counts on posts and missions (#2)

Every post in the API now carries a **`word_count`** — the number of words in that post's body, with HTML stripped, using the same definition as the "Word count: N" figures already shown on Manage Missions and `/sim/missions`. So the API and the site agree.

Missions carry a **`word_count`** too — the total across the mission's activated posts — but only for tokens that can read all posts (`posts:read` or `posts:read.all`). A mission total aggregates every author's posts, so an own-only or missions-only token doesn't get it.

Word counts are **per-post and per-mission only**. They are deliberately *not* attributed to individual authors: a post can have several co-authors, so "words written by user X" isn't a thing the data supports. `GET /posts`, `GET /posts/{id}`, `GET /missions`, and `GET /missions/{id}` all include the new field (and it appears in the API Explorer and OpenAPI spec).

### Edit token scopes without re-issuing (#3)

You can now change an existing API token's scopes in place — no need to delete and mint a new key (and re-paste it into every integration).

- **In the panel:** on the *REST API → Configure* token list, click a token's scope list to expand a checkbox editor, adjust, and **Save scopes**.
- **Over the API:** `PATCH /tokens/{id}` now accepts a `scopes` array (in addition to the existing `revoked` toggle). Send either or both; a scopes-only PATCH leaves revocation alone.

Either way, the new scope set is re-validated against the token's **existing user binding**, so you still can't grant a post `read.own` / `write` / `delete` scope to a token with no bound user. The managed Sim Central access token is excluded — change its access with Revoke / re-grant, as before.

No database changes (the scopes column already stored a JSON array).

## Also

- The word-count helper now has one canonical definition (`PostWordCount::countText`) shared by the mission pages and the API, so they can never drift apart.

## Upgrade

Use the **Update Now** button on the dashboard, or `POST /Api/suite`. No database changes, no shims — post-update housekeeping (v1.27.0) has nothing to do here.

## Credits

MIT licensed. Thanks to the folks filing feature requests on GitHub.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
