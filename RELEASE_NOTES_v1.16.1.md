# Sim Central Suite v1.16.1 — Config-aware post timelines

Hardens how the post-authoring API handles **Ordered Mission Posts** timelines. Each mission using that feature has one of three schemes, and previously the API accepted any timeline field regardless — so sending the wrong one for a mission was silently stored and ignored.

## What's changed

`POST` / `PATCH /posts` now validate the ordered-timeline fields against the **mission's** configured scheme:

| Mission `config` | Expected field | (`ordered_time` applies to all) |
|---|---|---|
| `day_time` | `ordered_day` | ✓ |
| `date_time` | `ordered_date` | ✓ |
| `stardate` | `ordered_stardate` | ✓ |

- Sending a field that doesn't match the mission's scheme now returns **`422`** with a clear message (e.g. *"This mission uses the 'date_time' timeline scheme; send 'ordered_date' (not 'ordered_day')."*), instead of accepting it and quietly dropping it.
- Omitting the timeline is still fine — the mission's defaults apply (drafts can fill it in later).
- The legacy "chronological" day/time storage variant is still handled automatically; clients always send `ordered_day` / `ordered_time`.

Clients can read a mission's scheme from `ordered.config` on `GET /missions/{id}` and send the matching field.

## Implementation notes

- `libraries/PostWrite.php` — new `timelineErrors()`: looks up the mission's `mission_ext_ordered_config_setting` and flags any ordered field that doesn't match (no-op when the feature is off or the mission has no scheme).
- `controllers/Api.php` — `_postCreate()` / `_postUpdate()` call it after resolving the (effective) mission and return `422` on a mismatch.

## Upgrade

Use the **Update Now** button on the dashboard. No database changes. This only affects requests that were sending a mismatched timeline field (which weren't working anyway); correct requests are unchanged.

## Credits

Same as v1.16.0. MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
