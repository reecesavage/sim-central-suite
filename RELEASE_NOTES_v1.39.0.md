# Sim Central Suite v1.39.0 — the API can tell you what changed

Everything here is additive. No existing field changes shape or meaning, and no consumer has to do anything.

## Change tracking

Posts, characters and personal logs now each carry two new fields:

| Field | Meaning |
|---|---|
| `content_hash` | SHA-256 identifying this version of the row |
| `content_updated_at` | ISO 8601 UTC (`Z`) instant at which that hash last changed |

`GET /posts`, `GET /characters` and `GET /logs` accept **`?updated_since=`** to return only what changed, so a consumer that polls no longer has to re-read the whole sim each time.

Four properties it guarantees:

- **A no-op save is not a change.** Opening a post and pressing Save without editing anything does not move `content_updated_at` — the hash it derives from didn't move.
- **Metadata edits count.** The hash covers what a reader sees, not just the body, so a retitle, a relocation or an author change registers. For a character it also covers every visible bio value, so a bio edit registers even though that write lands in a different table.
- **`updated_since` never hides a row.** It matches `>=`, and a row whose tracking column isn't filled in yet is included rather than skipped. Being handed an unchanged row is cheap; missing one silently is not.
- **Timezones never enter into it.** Every new timestamp is UTC with a `Z`, and a value you send with no offset is read as UTC.

`GET /missions` gains **`posts_updated_at`** — the newest change across that mission's posts. One already-paginated call is now the whole dirty check for a per-mission sync: a completed mission whose value matches what you stored needs nothing fetched. It's scoped to what your own token can enumerate, so a mission can't report itself dirty and then hand you an empty delta.

Hashes are opaque — compare them, don't recompute them. The list envelope's `meta.hash_version` says which recipe produced them.

## Personal logs

New `GET /logs` and `GET /logs/{id}`, behind a new **`logs:read`** scope. Activated logs only; a draft 404s rather than 403s, since saying "forbidden" would confirm a draft with that id exists.

The shape mirrors a post wherever the two have the same thing to say — `content`, `excerpt`, `word_count`, `url`, `content_hash` — so one consumer code path can walk both. A log has one author, so `character_name`, `rank_name`, `position` and `user_name` come back flat rather than as parallel arrays.

`logs:read` is the only new scope, so it's the only reason to reissue a token. Existing tokens pick up every other field on this page automatically.

## Characters carry their roster context

`GET /characters` returned a bare numeric `rank` id and no position at all, so building a departmental roster with working `characters/{id}` links meant joining the snapshot — which carries no ids — by display name. Now on both the list and the single read:

`rank_name`, `rank_short`, `rank_order`, `position_primary` and `position_secondary` (`{id, name, department_id, department}`).

`GET /characters?status=any` is enough on its own.

## How it's maintained, and why it isn't triggers

The obvious way to keep a hash current is a database trigger. We didn't, for two reasons.

The usual argument for triggers — that a post can be edited through Nova's own controllers, which an extension can't see — doesn't hold here. Every write path in Nova core funnels through `Posts_model::create_mission_entry` / `update_post` and the personal-log and character equivalents, and the suite already installs managed blocks that override exactly those methods. Interception is total, one layer below the controllers.

And a trigger that outlives the extension — because the suite was removed, or its columns dropped — makes every `UPDATE` on `nova_posts` fail with *Unknown column*, which takes the sim's posting offline. Triggers also need the `TRIGGER` privilege and, on servers with binary logging and no `SUPER`, `log_bin_trust_function_creators`; shared hosting grants neither reliably. A hook we install and remove ourselves has none of those failure modes.

**Press Setup database on the REST API card** (or let the automatic post-update housekeeping do it) to add the columns. Until then the new fields come back `null` and `updated_since` is ignored — you get a superset, which is still correct for a sync, just not incremental.

Existing sims will also see **Install shim** on the REST API card. The post and log blocks are shared with Event Webhooks and have moved to v2; the two character blocks are new. If you run Event Webhooks, its blocks now do both jobs and its behaviour is unchanged.

## Also fixed

**A shim could strip another extension's code.** The installer decided a managed block was a "take-over" candidate whenever *any* known predecessor marker appeared in the target file — regardless of whether the feature being installed had a predecessor at all. That never bit, because no two features shimmed the same file. This release puts two more blocks in `Characters_model.php`, which is exactly where the standalone `nova_ext_display_name` extension lives; installing them would have deleted its block on any sim running it with the suite's own Display Name feature off. Take-over is now limited to features that actually declare a predecessor.

## Upgrade

Use the **Update Now** button on the dashboard, or `POST /Api/suite`. Then **Setup database** and **Install shim** on the REST API card if the dashboard flags them.

## Credits

MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
