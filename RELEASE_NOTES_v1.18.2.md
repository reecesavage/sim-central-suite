# Sim Central Suite v1.18.2 — Fix query error when legacy mode is disabled

Fixes a DB query error that prevented posts from saving on any Nova installation that
does not have the Chronological Mission Posts legacy columns.

## Cause

`PostWrite::populateRequestInputs()` unconditionally seeded `$_POST` with both the
modern Ordered Mission Posts keys (`nova_ext_ordered_post_day`, `nova_ext_ordered_post_time`)
and the legacy Chronological Mission Posts keys (`post_chronological_mission_post_day`,
`post_chronological_mission_post_time`). Nova's Ordered Mission Posts `db.*.prepare.posts`
listener writes every key it finds to the UPDATE/INSERT query. On installations where
legacy mode is disabled (the default) those columns do not exist, producing:

> Unknown column 'post_chronological_mission_post_day' in 'field list'

The post appeared to save (the error was caught silently by CI's DB layer) but the
lock was never released, leaving the post locked until it expired.

## Fix

The legacy `post_chronological_*` keys are now only set in `$_POST` when the suite's
`legacy_mode` setting is enabled. The default value is `0` (disabled).

## Implementation notes

- `libraries/PostWrite.php` — `populateRequestInputs()` now reads `Config::load()`
  and gates the two legacy keys behind `legacy_mode`.

## Upgrade

Use the **Update Now** button on the dashboard. No database or config changes.

## Credits

Same as v1.18.1. MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
