# Sim Central Suite v1.12.1 &mdash; Dashboard crash hotfix

Critical bug-fix. After upgrading to 1.12.0, the Sim Central Suite dashboard threw a fatal error on **any sim that hadn't set up Event Webhooks**:

```
Query error: Table '...nova_sim_central_webhooks' doesn't exist
  - Invalid query: SHOW COLUMNS FROM `nova_sim_central_webhooks`
Call to a member function result_array() on bool
```

If you'd already run **Setup database** on the Event Webhooks feature you wouldn't have seen it (your table existed). Everyone else &mdash; including sims that never touched webhooks at all &mdash; hit it the moment they opened the suite dashboard.

## Root cause

1.12.0 added a `news_types` column to the webhooks table, declared via the feature's `requires_db` so existing installs would get it through **Setup database**.

But `sim_central_webhooks` is a table the suite *creates itself* (via `requires_tables`). The dashboard's `_missingColumns()` check runs for **every** feature on every render and called CodeIgniter's `list_fields()` &mdash; which issues `SHOW COLUMNS FROM ...` &mdash; without first checking the table exists. On any sim where webhooks was never set up, that table isn't there, so the query failed and took the whole dashboard down.

Every other feature's `requires_db` points at core Nova tables (`posts`, `users`, etc.) that always exist, so this class of bug had never surfaced before. Webhooks is the first feature to declare a column on a table it also creates.

## What's fixed

- `_missingColumns()` now checks `table_exists()` before calling `list_fields()`, and skips tables that don't exist yet. The missing table is still reported by `_missingTables()` (which drives the **Setup database** prompt), and a single Setup database run creates the table and adds the column together &mdash; so the user experience is unchanged, it just no longer crashes during status display.
- The same guard was added defensively to `_setupDatabase()`'s column-add loop, in case a table's `CREATE` fails earlier in the same run.

## Upgrade

Use the **Update Now** button on the dashboard.

If your sim is currently crashing on the suite dashboard and you can't reach the **Update Now** button, the fastest recovery is to upgrade via the manual route (replace the extension folder with the 1.12.1 release) or, as a stopgap, create the table by hand:

```sql
CREATE TABLE IF NOT EXISTS nova_sim_central_webhooks (
  id int(11) NOT NULL AUTO_INCREMENT,
  label varchar(120) NOT NULL,
  url text NOT NULL,
  format varchar(20) NOT NULL,
  events text NOT NULL,
  enabled tinyint(1) NOT NULL DEFAULT 1,
  news_types varchar(20) NOT NULL DEFAULT 'public',
  template_title text DEFAULT NULL,
  template_description text DEFAULT NULL,
  created_by int(11) DEFAULT NULL,
  created_at datetime NOT NULL,
  last_fired_at datetime DEFAULT NULL,
  last_status int(11) DEFAULT NULL,
  last_error text DEFAULT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
```

(Replace the `nova_` prefix with your sim's table prefix.) Once 1.12.1 is in place the manual table isn't necessary &mdash; the dashboard loads fine whether or not the table exists.

No data changes; existing webhooks are unaffected.

## Credits

Same as v1.12.0. MIT licensed. Thanks to the admin who reported the dashboard crash.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
