# Sim Central Suite v1.21.0 — Grant Sim Central access, remote upgrades, Discord link exemptions

Three additions this release: a one-click **Grant Sim Central access** button that hands the
Sim Central service a pre-scoped token via your broker, a **`suite:update`** API capability for
inspecting and upgrading the suite remotely, and a **link-exemption list** for Discord Sign-In.

## Grant Sim Central access

REST API → *Configure* now has a **Sim Central access** card. One click mints a single
pre-scoped, sysadmin-bound API token and registers it with your broker (which forwards it to
your storage of choice). The token is **read across the board** plus the management capabilities
Sim Central needs — webhook config, member activation, and suite upgrades — but deliberately
**no post-authoring scopes**:

```
posts:read  characters:read  missions:read
webhooks:read  webhooks:write  users:write  tokens:read  suite:update
```

- **Broker configuration** lives on the same page: set the **Broker URL** and a **shared secret**
  (which must match the broker's `SC_SHARED_SECRET`). The secret field is write-only — leave it
  blank to keep the stored value.
- The card shows live status (granted / active / revoked) and the last broker sync result.
- **Revoking or deleting the token notifies the broker** — from the access card, the normal token
  list, or `DELETE /tokens/{id}` over the API — so Sim Central stops using a dead credential.

The raw token is shown once at grant time (and delivered to the broker automatically when a
Broker URL is set); copy it if you also want it on hand.

## Suite management over the API *(new `suite:update` scope)*

A new sysadmin-bound capability lets Sim Central see what each sim is running and push upgrades
without anyone logging into the ACP — the same one-click updater the dashboard uses.

### `GET /suite`
Version status — any valid token:
```json
{ "version": "1.21.0", "latest_version": "1.22.0", "update_available": true }
```

### `POST /suite/update`
Runs the updater. Requires the `suite:update` scope **and** a sysadmin-bound token. Body:

| Field | Default | Notes |
|---|---|---|
| `version` | latest release | Target version; omit to take the newest published release |
| `force` | `false` | Reinstall even if already on `version` |

Returns `{ "status": "success", "version": "1.22.0", "backup": "..." }`. The update swaps the
extension on disk, so re-read `GET /suite` afterwards to confirm. See `REST_API.md` for details.

## Discord Sign-In: exempt users from the link requirement

Discord Sign-In → *Site-wide enforcement* gains an **Exempt user IDs** box. Nova user IDs listed
there are never redirected to the forced-link / Discord-only page, even while enforcement is on —
handy for service accounts or members who legitimately can't link Discord.

These are **Nova user IDs**, not Discord IDs, and there is **no automatic sysadmin exemption** for
the link requirement: list any sysadmins you want exempt here too.

## Implementation notes

- New libraries `Broker.php`, `SimCentralAccess.php` — broker client and the access-token
  lifecycle (state kept in a self-contained `settings` row).
- `controllers/Api.php` — new `suite()` endpoint; `tokens()` PATCH/DELETE notify the broker when
  the Sim Central token is killed.
- `controllers/Manage.php` + `views/admin/pages/rest_api.php` — the access card and broker config;
  `views/admin/pages/discord_auth.php` — the exempt-IDs box.
- `libraries/ApiAuth.php` — adds the `suite:update` scope; `libraries/DiscordAuth.php` —
  `isLinkExcluded()` short-circuits both enforcement gates.

## Upgrade

Use the **Update Now** button on the dashboard. New settings (broker URL/secret, exempt IDs)
default to empty/off and require no database changes.

## Credits

MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
