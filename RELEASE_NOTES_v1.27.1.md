# Sim Central Suite v1.27.1 — Webhooks (and friends) no longer require other features' columns

Fix release, prompted by a fleet sim's logs: **Event Webhooks silently failed to fire** on sims that had never enabled Discord Sign-In.

## The bug

Several code paths *enrich* their output with columns that belong to other features — the webhook author lookup selects the linked Discord ID (for @mentions) and the character display name (for bylines). But those columns only exist once the owning feature's **Set Up Database** has run. On a sim with webhooks enabled and Discord Sign-In never touched, the author query referenced a column that didn't exist, the query died, and the webhook never fired:

```
Query error: Unknown column 'nova_users.nova_ext_discord_auth_id' in 'SELECT'
Exception: Call to a member function result() on bool ... Webhooks.php
```

The same latent assumption existed for `display_name` in the REST API's `/me` character list, the post-lock owner name, and four Mobile Site queries — any of which could break on a sim that never enabled Display Name.

## The fix

Optional columns are now genuinely optional. A shared, per-request-cached column check (`Migrations::hasColumn`) gates every cross-feature select:

- **Webhooks fire on every sim.** No Discord Sign-In → authors render as plain "Rank First Last" instead of @mentions, exactly as they do for unlinked users. No Display Name → bylines use First/Last. Everything else about the embed is unchanged.
- REST API, post locking, and Mobile queries apply the same rule for `display_name`.
- All downstream formatting already handled the fields being empty, so behaviour with the features **enabled** is byte-for-byte identical.

## Upgrade

Use the **Update Now** button on the dashboard, or `POST /Api/suite`. No database changes; no configuration changes; post-update housekeeping (v1.27.0) has nothing to do here.

## Credits

MIT licensed. Thanks to the USS Blackhawk for the error logs.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
