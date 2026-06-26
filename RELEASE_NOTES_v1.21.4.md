# Sim Central Suite v1.21.4 — REST API Configure fix on fresh sites

## Fix

- **REST API → Configure no longer 500s on sites without the Display Name feature.** The
  "bind token to a user" dropdown query selected `characters.display_name` unconditionally, but
  that column only exists once the *Display Name* feature has run its **Setup database**. On a sim
  that hadn't, the query failed (`Unknown column 'c.display_name'`) and the whole Configure page
  errored. The column is now included only when present, and the view reads it defensively.

## Also tidied (PHP 8 warnings)

- `init.php` — the Discord-enforcement hook checked `$ci->session` directly; on controllers that
  don't load the session library (e.g. the RSS `Feed`) that warned. Now guarded with `isset()`.
- `events/discord_auth_template_render.php` — appended to `$event['data']['javascript']` before it
  existed in some render contexts; now coalesces first.

## Upgrade

Use the **Update Now** button on the dashboard (the main Sim Central Suite page works even when the
REST API Configure page is erroring), or `POST /Api/suite`. No database changes.

## Credits

MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
