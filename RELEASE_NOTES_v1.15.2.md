# Sim Central Suite v1.15.2 &mdash; Update checker reliability fix

The in-app "update available" check stopped detecting new releases.

## Root cause

The update checker queried GitHub's computed **`/releases/latest`** endpoint. For this repo that endpoint has started returning **504 Gateway Timeout** consistently (a GitHub-side issue with that specific computed endpoint &mdash; the regular releases list works fine). On any non-200 the checker returns its cached value and just bumps the "last checked" time, so the dashboard kept showing the old version and never surfaced the new release.

## What's fixed

`UpdateCheck` now queries the **releases list** (`/releases?per_page=30`) and picks the highest published version itself (skipping drafts and pre-releases) via `version_compare`. The list endpoint is reliable, and selecting the newest version locally is actually more correct than trusting the computed "latest" flag.

No behaviour change otherwise: same 24h cache, same short timeouts, same graceful fallback to cache on failure, same manual "recheck" button.

## Implementation notes

- `libraries/UpdateCheck.php` &mdash; `RELEASES_API_URL` now points at the list endpoint; `fetch()` iterates the returned array and keeps the highest non-draft, non-prerelease `tag_name`.

## Upgrade

**Heads up:** sims on v1.15.1 or earlier still have the broken checker, so they won't *auto*-detect this release while GitHub's `/releases/latest` is timing out. Update **once via the dashboard's Update Now button** (which pulls the latest release directly, independent of the check) or via the manual route; after that the checker is fixed and future releases surface normally.

If the dashboard still shows an older version right after updating, click the **recheck** button next to the version &mdash; the cached result refreshes immediately.

## Credits

Same as v1.15.1. MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
