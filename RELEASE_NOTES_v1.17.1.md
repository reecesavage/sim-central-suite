# Sim Central Suite v1.17.1 — Mobile Site hotfix

Fixes two bugs in the v1.17.0 Mobile Site that made it unreachable.

## 1. `/mobile` threw a 500

`controllers/Mobile.php` extends `Nova_controller_main` but was missing the `require_once` for that base class that every Nova controller does, so the class failed to load (`Class "Nova_controller_main" not found`). Added the require.

## 2. The route hook hijacked other URLs (404s)

The `/mobile` rewrite hook matched **any** path containing `mobile`, so once the hook was registered, `/extensions/nova_ext_sim_central/Manage/mobile` (the feature's own config page) — and any Nova URL with a `mobile` segment — got rewritten to a broken path and 404'd.

The hook now anchors to the **app-root** `mobile` segment only: it strips the install base dir (and any `/index.php`) and matches `^/mobile(/…)?$`. So `/mobile` and `/mobile/...` are rewritten, while `/Manage/mobile`, the capital-M extension path, and everything else are left alone.

## Implementation notes

- `controllers/Mobile.php` — add `require_once MODPATH.'core/libraries/Nova_controller_main.php';`.
- `hooks/mobile_route_hook.php` — root-anchored matching via `SCRIPT_NAME` base + `^/mobile(/.*)?$`.

The hooks.php registration from v1.17.0 is unchanged (same file + function), so no re-setup is needed — updating the files is enough. If you registered it manually, likewise no change.

## Upgrade

Use the **Update Now** button on the dashboard. The Mobile config page (*Sim Central Suite → Mobile Site*) is reachable again, and `/mobile` loads.

## Credits

Same as v1.17.0. MIT licensed. Thanks to the admin who caught the 404/500 immediately on rollout.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
