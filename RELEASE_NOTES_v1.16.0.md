# Sim Central Suite v1.16.0 — API token management

Adds the ability to **manage API tokens over the REST API** — the same list / create / revoke / delete actions as the ACP token page — so automation can issue and rotate its own tokens without an admin opening the dashboard.

## What's new

Two new scopes and a `/tokens` resource:

| Verb | Path | Scope |
|---|---|---|
| `GET` | `/Api/tokens` · `/Api/tokens/{id}` | `tokens:read` |
| `POST` | `/Api/tokens` (create) | `tokens:write` |
| `PATCH` / `PUT` | `/Api/tokens/{id}` (revoke / un-revoke) | `tokens:write` |
| `DELETE` | `/Api/tokens/{id}` | `tokens:write` |

- **Create** mirrors the ACP form: `label`, `scopes`, optional `user_id` binding, optional `expires_at`. It returns the raw token **exactly once** (in the `token` field); only the hash is stored.
- **Revoke** (`PATCH {"revoked": true}`) preserves the row for audit; **delete** removes it entirely.
- **List / get** return metadata only — never the raw token or its hash.

## Security model

Token management is the most privileged thing the API can do (a `tokens:write` token can mint tokens with any scope), so it's gated tightly:

- Every `/tokens` endpoint requires the calling token to carry the `tokens:*` scope **and** be **bound to a sysadmin user** — a `403` otherwise. This mirrors the ACP, where only sysadmins (`site/settings`) manage tokens. The scope is the boundary; the sysadmin binding is the permission.
- Recommendation: bind a `tokens:write` token to a sysadmin, scope it narrowly, and give it a short expiry. Revoke (audit-preserving) is preferred over delete.

## Design / implementation notes

- The canonical **scope registry** and a shared **token-create validator** now live in `ApiAuth` (`availableScopes()`, `validateTokenInput()`), used by both the ACP token form and the new API endpoints, so the two enforce identical rules (label, valid scopes, user-binding requirement for post scopes, future expiry).
- `controllers/Api.php` — new `tokens()` action (verb-dispatched), a `_requireSysadminToken()` gate, and a `_projectToken()` whitelist projector that never emits secret material.
- `controllers/Manage.php` — `_apiAvailableScopes()` and `_createApiToken()` now delegate to the shared `ApiAuth` helpers (no behaviour change to the ACP).

## Upgrade

Use the **Update Now** button on the dashboard. No database changes (reuses the existing `sim_central_api_tokens` table). To use it, issue a token (from the ACP, or from another `tokens:write` token) that is bound to a sysadmin user and carries `tokens:read` / `tokens:write`.

## Credits

Same as v1.15.5. MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
