# Sim Central Suite v1.15.1 &mdash; REST API write endpoints vs. CSRF

Fix for v1.15.0 (and, it turns out, for the v1.14.0 write endpoints too). Token-authenticated **POST** requests to the API &mdash; creating a post, creating a webhook, disabling a user &mdash; were rejected by Nova's CSRF protection with *"The action you have requested is not allowed."* before the API code ever ran.

## Root cause

Nova/CodeIgniter's CSRF protection requires a session CSRF token on every `POST`. API clients authenticate with the `X-API-Key` header and have no such token, so every API `POST` was blocked. (Only `POST` is affected &mdash; CodeIgniter skips CSRF for `GET`/`PATCH`/`PUT`/`DELETE`, which is why reads and updates/deletes worked and only *creates* failed.)

This hit every write endpoint that uses `POST`, including the webhook and user-management endpoints shipped in v1.14.0 &mdash; it just hadn't surfaced until a real `POST` was exercised.

## What's fixed

The suite now adds the API path to CodeIgniter's CSRF allowlist automatically. Visiting *REST API &rarr; Configure* ensures this line exists in `application/config/config.php`:

```php
$config['csrf_exclude_uris'][] = 'extensions/nova_ext_sim_central/Api/.*';
```

- **Why `config.php`** and not `nova.php` or a core file: `config.php` is loaded at bootstrap **before** the CSRF check runs (`application/config/nova.php` is autoloaded later, during controller init &mdash; too late), and it lives in `application/`, so a Nova upgrade won't overwrite it. The core `nova_config.php` is never touched.
- **Idempotent + self-healing.** The check runs on each Configure visit, detects an existing entry (auto-added or hand-added) and no-ops; only `POST` to the API path is excluded, everything else stays CSRF-protected.
- **Graceful fallback.** If `config.php` isn't writable by the web server, the Configure page shows the exact line to paste in by hand instead.

## Implementation notes

- `controllers/Manage.php` &mdash; new `_ensureApiCsrfExclusion()` (writes/locates the allowlist entry in `APPPATH/config/config.php`), called from `rest_api()`.
- `views/admin/pages/rest_api.php` &mdash; a confirmation note when the entry is added, or an action-needed note with the manual line if the file can't be written.
- `REST_API.md` &mdash; documents the CSRF behaviour and the manual fallback.

No code changes to the endpoints themselves; this is purely the CSRF allowlisting.

## Upgrade

Use the **Update Now** button on the dashboard, then open *REST API &rarr; Configure* once &mdash; that visit applies the CSRF exclusion. If the page reports it couldn't write `config.php`, add the one line above by hand. (Already had it working via a manual `csrf_exclude_uris` entry? No change needed &mdash; the check sees it and leaves it alone.)

## Credits

Same as v1.15.0. MIT licensed. Thanks to the admin who hit the CSRF wall testing post creation.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
