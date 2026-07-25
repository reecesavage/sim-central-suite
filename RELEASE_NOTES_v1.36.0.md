# Sim Central Suite v1.36.0 — public post archive

Everything an external site needs to publish a **readable archive** of a sim's mission posts: the display fields on the post list, a mission filter with a real reading order, numbered pagination, and a mission `id` on the snapshot's story list to link it all together.

Built for the Astrolabe **Story Archive** (a sim's mission list, and inside each mission a paginated list of its posts, readable off-site) — but nothing here is Astrolabe-specific.

**Everything is additive. No existing field changed shape, no existing consumer breaks.**

## What's new

### Posts carry the fields a *reader* needs

`GET /posts` and `GET /posts/{id}` previously returned what a writing app needs — ids, status, word count. Every post object now also carries:

- **`author_names`** — the authors' display names, e.g. `["Jane Doe", "John Smith"]`, in the stored order. `[]` when there are none; ids that no longer resolve are dropped rather than null-padded. Honours the *Display Name* override when that feature is on, so a byline reads the same as the character's name on the manifest.
- **`published_at`** — the activation timestamp, ISO 8601 UTC. `null` unless the post is `activated`, so a draft's last-save time is never mistaken for a publication time.
- **`url`** — the post's own public page on the sim, absolute `https://`. For a "read on the sim" link and a `rel="canonical"`, so the sim keeps the search-engine credit for its own content.
- **`excerpt`** — plain-text preview of the body: HTML stripped, entities decoded, whitespace collapsed, capped at 300 chars. `null` when the body is empty. Same treatment as the snapshot's `recent_posts[].excerpt`.

**`authors` is untouched** — still Nova's comma-joined charid string (`"17,21"`), the value an edit form explodes to map ids onto an author picker. Names arrive *alongside* it in `author_names`, on both the list and the single post. Turning `authors` into a name array would have broken every existing consumer, so it didn't happen.

Author names cost one batched query per page, not one per post.

### `GET /posts` — mission filter and reading order

- **`?mission_id=`** — the documented spelling, matching the field on the post object and the snapshot's `stories[].id`. `?mission=` still works as a legacy alias.
- **`?sort=order`** — lists a mission in **reading order, ascending**, matching what the sim itself shows on its mission page: with *Ordered Mission Posts* on, that mission's own scheme (day / date / stardate, then time); otherwise post date ascending. `post_date` is always the final tie-break, so paging can't reshuffle rows between pages. The default stays newest-first.

### `?content=0` — metadata-only list rows

`GET /posts` has always returned each post's full body. For an index page that's dead weight: 25 posts of 1,200 words is a ~235 KB response, and a sim whose posts approach Nova's 64 KB `TEXT` ceiling can hit 1.6 MB — on the page *every* archive visitor loads, not the one some of them click into. On shared hosting that's where a 15-second read timeout starts failing.

`?content=0` (or `false` / `no` / `off`) drops the `content` key and nothing else — ~14 KB per page. Fetch each body from `GET /posts/{id}` when a reader actually opens that post.

**Opt-out, not opt-in.** The default response is unchanged, so no existing caller has to do anything: `REST_API.md` promises response-shape breaks only on a major version, and the documented n8n pattern walks `data[]` off this endpoint. Making metadata-only the default would have silently emptied those flows.

`word_count` and `excerpt` are unaffected — both are derived from the body, so the sim still reads and counts it. What you save is the serialization, the memory, and the transfer, not the column read. `meta` is unaffected too: `total` and `last_page` still describe the whole result set. List-only; `GET /posts/{id}` always returns `content`.

### Numbered pagination on every list

Every list envelope gained a **`meta`** block:

```json
{ "data": [ … ], "page": 3, "per_page": 25, "total": 587,
  "meta": { "current_page": 3, "last_page": 24, "per_page": 25, "total": 587 } }
```

`last_page` is the new information — enough to render a real paginator instead of prev/next. The flat `page` / `per_page` / `total` keys are unchanged. Applies to `/posts`, `/characters`, `/missions`, and `/positions`.

### Snapshot stories carry the mission id

`snapshot.stories[]` entries now include **`id`** — the Nova mission id, the value to pass back as `mission_id` on `GET /posts`. So a mirrored story index can link into that mission's archive instead of dead-ending.

No `slug` key: Nova has no mission slug column, and inventing one would produce URLs the sim itself can't resolve.

`posts_count` was already the publicly visible count (activated posts only) — the same set `GET /posts?mission_id=…&status=activated` returns, so an index count matches what the reader then sees.

## Documented (no code change)

The post object's `word_count`, `saved_character_id`, `saved_user_id`, and `saved_user_name` were returned by the API but missing from the `REST_API.md` field table. Now listed.

`word_count` in particular: it is computed per request from the body — `str_word_count(strip_tags($content))`, the suite's one canonical definition — not read from a stored column. So it is present on **every** post regardless of status, drafts included, and is never null. An integration that built its own draft word-count fallback because the field looked activated-only can drop it; the numbers already agree, because that's the same rule the sim uses on Manage Missions and `/sim/missions`.

## Fixed

**Snapshot story titles were empty.** `stories[].title` read `mission_name`, a column that doesn't exist on Nova's missions table (it's `mission_title`), so every story arrived as `""` — on an undefined property PHP warns and yields null rather than throwing, so the section's error guard never caught it. Titles now resolve. Consumers that cached blank titles refill on their next poll; the snapshot cache is version-aware, so the update invalidates it immediately rather than serving up to a TTL of stale data.

## Security

Unchanged, and re-verified: a token without a posts read scope gets `403` from `/posts` outright — no `data` key, drafts or otherwise. A public `posts:read` token still defaults to activated posts only. `sort=order` never orders by a column that isn't installed, and the mission lookup behind it runs before the posts query is built rather than resetting it mid-flight.

## Upgrade

Use the **Update Now** button on the dashboard, or `POST /Api/suite`. No database changes, no configuration changes. Each new field lights up on its own as consumers start reading it.

## Credits

MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
