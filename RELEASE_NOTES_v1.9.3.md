# Sim Central Suite v1.9.3 &mdash; REST API: X-API-Key header

The REST API now authenticates exclusively via the `X-API-Key` header instead of `Authorization: Bearer`. One supported form, works on every install, no server config required.

## Why

Apache strips the `Authorization` header before PHP can read it on most shared hosts &mdash; it considers `Authorization` to be a server-owned header and consumes it itself. Supporting `Authorization: Bearer` meant either:

1. Documenting an `.htaccess` rewrite step that admins had to add manually before the API would work, or
2. Silently accepting requests that some clients couldn't actually make on some hosts.

Both options are footguns. `X-*` headers don't have this problem &mdash; Apache passes them through to PHP untouched, every time, with no config required. So the API uses `X-API-Key` exclusively:

```
X-API-Key: scapi_a1b2c3...
```

The token format is unchanged (`scapi_` + 40 hex chars), the hashing is unchanged, the rate limits are unchanged, the scopes are unchanged. Only the header name on the wire is different from v1.9.2.

## What changed

- `controllers/Api.php` &mdash; `_extractToken()` now reads only `X-API-Key` (case-insensitive). Removed the `Authorization: Bearer` fallback and the `REDIRECT_HTTP_AUTHORIZATION` plumbing.
- `libraries/ApiAuth.php` &mdash; `validateBearer($header, $scope)` renamed to `validateToken($raw, $scope)`. The library no longer parses HTTP headers; it just takes a raw token string. Header extraction is the controller's job now (which is where it belongs). Error message on missing token tightened to point at the right header name.
- `REST_API.md` &mdash; auth section rewritten. New **Troubleshooting** section covers the most common 401/503/429 paths. The `.htaccess` workaround that was briefly part of v1.9.2's docs is gone &mdash; nobody needs it any more.
- `views/admin/pages/rest_api.php` &mdash; the on-page hint shows the single supported header.

## Migration for existing consumers

If you wired up a v1.9.2 consumer using `Authorization: Bearer scapi_...`, change two things:

- **n8n**: edit your Header Auth credential &rarr; change **Header Name** from `Authorization` to `X-API-Key` &rarr; change **Header Value** from `Bearer scapi_...` to just `scapi_...` (drop the `Bearer ` prefix).
- **curl / scripts**: swap `-H "Authorization: Bearer $TOKEN"` for `-H "X-API-Key: $TOKEN"`.

If you added the `.htaccess` rewrite from the v1.9.2 troubleshooting notes, you can remove it &mdash; nothing in the suite needs it any more.

## Upgrade

Use the **Update Now** button on the dashboard. Code-only change &mdash; no DB updates, existing tokens still work, you just have to send them in `X-API-Key` instead.

## Credits

Same as v1.9.2. MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
