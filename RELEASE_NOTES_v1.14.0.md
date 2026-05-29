# Sim Central Suite v1.14.0 &mdash; REST API write endpoints

The REST API gains its first **write** endpoints: flip user activation status (and cascade to their characters) and fully manage event webhooks. Read endpoints are unchanged; the new surface is gated behind dedicated `*:write` scopes that existing tokens don't carry, so nothing becomes writable until an admin deliberately issues a token for it.

## What's new

### User activation status

Two endpoints under the new `users:write` scope:

- **`POST /Api/users/disable`** &mdash; sets the user to `status = inactive` and every *currently-active* linked character to `crew_type = inactive` (stamping `date_deactivate` / `leave_date`).
- **`POST /Api/users/reactivate`** &mdash; sets the user to `status = active` and, by default, every *previously-inactive* linked character back to `active`. Pass `reactivate_characters: false` to reactivate only the user.

Identify the user by **`user_id`** (always works) or **`discord_id`** (the user's linked Discord account). The Discord lookup reads the column owned by the *Discord Sign-In* feature, so if that feature is off the endpoint returns a clear `409` telling you to use `user_id`. If both are supplied, `user_id` wins.

Response summarises the cascade:

```json
{
  "user_id": 42,
  "discord_id": "123456789012345678",
  "status": "inactive",
  "characters": { "status": "inactive", "affected": 2, "ids": [10, 11] }
}
```

### Event webhook management

CRUD over the suite's [event webhooks](WEBHOOKS.md), under `webhooks:read` / `webhooks:write`:

| Verb | Path | Scope |
|---|---|---|
| `GET` | `/Api/webhooks` &middot; `/Api/webhooks/{id}` | `webhooks:read` |
| `POST` | `/Api/webhooks` (create &rarr; `201`) | `webhooks:write` |
| `PATCH` / `PUT` | `/Api/webhooks/{id}` (partial update) | `webhooks:write` |
| `DELETE` | `/Api/webhooks/{id}` | `webhooks:write` |

Create/update bodies take the full webhook field set (label, url, format, events, news-type filter, role ping, and all `template_*` fields). Updates are **partial** &mdash; omitted fields keep their stored values. All of these require the *Event Webhooks* feature to be enabled (`409` if off, `503` if on but un-migrated).

## Design notes

- **`active` ↔ `inactive` only.** User disable/reactivate touches a character only when flipping between `active` and `inactive`. Characters with `crew_type = 'pending'` or `'npc'` are deliberately left untouched in both directions &mdash; reactivating a user won't promote a pending application or activate an NPC, and disabling won't deactivate NPCs.
- **The link is `characters.user`.** Affected characters are *all* of the user's owned characters (matching the crew-type filter), found via `characters.user = userid` &mdash; not just their `main_char`.
- **Write scopes are opt-in.** `users:write`, `webhooks:read`, and `webhooks:write` are new; tokens issued before this release don't have them, so the API stays read-only for existing integrations until you re-issue a token with the new scopes ticked.
- **One validation path for webhooks.** Webhook field validation/normalisation now lives in `Webhooks::validateWebhookInput()`, shared by both the ACP form and the API, so the two can't drift on rules or accepted values.
- **Pings in `content` only** (unchanged) and all the other webhook semantics carry over exactly &mdash; the API is just another front door to the same config the ACP writes.

## Implementation notes

- `controllers/Api.php` &mdash; new `users()` and `webhooks()` actions; helpers for HTTP-method enforcement, request-body parsing (JSON / form / query, JSON wins), user resolution by id or Discord id, the `active`/`inactive` character cascade, and a webhook projector. Webhook writes route through the shared library validator; updates merge the existing row first so PATCH is partial.
- `libraries/Webhooks.php` &mdash; added `availableEvents()` / `availableFormats()` / `availableNewsTypes()` registries and `validateWebhookInput()`. `controllers/Manage.php` now delegates to these (single source of truth) instead of keeping its own copies.
- `libraries/ApiEndpoints.php` &mdash; the seven new endpoints added to the catalog; `toOpenApi()` now emits `requestBody` from body params, honours non-`GET` verbs and custom success codes (`201`), and documents the `409` feature-gate and `422` validation responses. Explorer + OpenAPI stay in lockstep.
- `controllers/Manage.php` &mdash; the three new scopes added to the token-creation registry, so they render as checkboxes on the *REST API &rarr; Configure* page.

## Upgrade

Use the **Update Now** button on the dashboard. **No database changes** &mdash; the write endpoints reuse existing columns (`users.status`, `characters.crew_type`, the webhooks table), and the new scopes are just strings stored in a token's existing scope list. No *Setup database* step is required for this release.

To start using the write endpoints, go to *REST API &rarr; Configure*, create a new token (or re-issue an existing one) and tick `users:write`, `webhooks:read`, and/or `webhooks:write`. Existing tokens keep working unchanged for the read endpoints.

## Credits

Same as v1.13.0. MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
