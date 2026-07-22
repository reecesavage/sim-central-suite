# Sim Central Suite v1.35.1 — location + timeline on post objects

Additive fields so an external editor can prefill a post's location and timeline.

## What's new

Every post returned by `GET /posts`, `GET /posts/{id}`, and the `POST` / `PATCH` write responses now carries:

- **`location`** — the post's in-character location (Nova's `post_location`).
- **`timeline`** — the post's free-text timeline (Nova's `post_timeline`), the field shown when a mission uses the stock timeline rather than *Ordered Mission Posts*.

Both are always present as strings, `""` when unset, so a consumer can prefill an edit form without null-guarding. Both were already accepted on `POST` / `PATCH` writes; reads are now symmetrical with writes.

**`timeline` is the raw stored value, not a rendered one.** The `timeline` key in the *webhook* payload is a different thing: it folds the *Ordered Mission Posts* day/time into a display string and only falls back to the raw column when the mission isn't ordered-configured. The API field never does that. The structured `ordered` object on the post is unchanged.

Both fields appear in the API Explorer and OpenAPI spec. No extra queries — the values were already on the row the API loads.

## Also clarified

`PATCH /posts/{id}` has always distinguished **omit** from **clear**, but it wasn't written down. Now documented: a key you don't send is left unchanged; a key sent as `""` clears the stored value. No behaviour changed — `{"location":""}` emptied the location before this release too.

## Upgrade

Use the **Update Now** button on the dashboard, or `POST /Api/suite`. No database changes, no configuration changes.

## Credits

MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
