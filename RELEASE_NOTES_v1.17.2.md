# Sim Central Suite v1.17.2 — Mobile Site routing hotfix

Follow-up to v1.17.1. The Mobile controller loaded, but visiting `/mobile` (or the extension URL) still 404'd with a warning:

```
Trying to access array offset on value of type bool ... CodeIgniter.php 398
404 Page Not Found: __extensions__nova_ext_sim_central__Mobile/
```

## Cause

Nova's extension dispatcher reads the controller method from a raw URI segment and **does not default to `index`** when it's missing. So `/extensions/nova_ext_sim_central/Mobile` (no method segment) — and `/mobile`, which the hook rewrote to exactly that — had an empty method and 404'd. (Every other extension controller is only ever hit with an explicit method, e.g. `/Api/ping`, so this never surfaced before.)

## Fix

- The `/mobile` route hook now rewrites a bare `/mobile` (or `/mobile/`) to `…/Mobile/**index**`, while `/mobile/<path>` still maps to `…/Mobile/<path>`.
- The "full URL always works" reference is now `…/Mobile/index` (the method is required).

## Implementation notes

- `hooks/mobile_route_hook.php` — append `/index` when there's no sub-path.
- `controllers/Manage.php` / `README.md` — document the full URL with `/index`.

No re-setup needed; updating the files is enough (the hook registration is unchanged).

## Upgrade

Use the **Update Now** button on the dashboard, then open `/mobile` — it should reach the login page.

## Credits

Same as v1.17.1. MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
