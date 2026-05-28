# Sim Central Suite v1.11.1 &mdash; Event Webhooks shim install hotfix

Bug-fix release. Webhook shim install was stuck in a ping-pong loop: clicking **Install Shim** / **Update Shim** flickered the dashboard and the same message came back. Webhooks couldn't be installed at all.

## What's fixed

### Webhook shim cross-collision on install

The Event Webhooks feature installs two managed blocks into `application/models/Posts_model.php` &mdash; one wrapping `create_mission_entry`, one wrapping `update_post`. In v1.11.0 both shim entries set `standalone_marker_ns: 'nova_ext_sim_central'` with their own tag as `standalone_marker_tag`. That was wrong.

The `standalone_marker_*` fields are meant to identify a **predecessor** extension's block that the suite should detect and take over (the way the `display_name` shim watches for the legacy standalone `nova_ext_display_name` extension's `character` marker). For a brand-new feature with no predecessor, those fields should be omitted entirely.

Because they weren't, both webhook shims got added to the cross-feature "known standalone markers" list. So:

1. Click **Install Shim** &rarr; loop iteration 1 writes the `webhooks_create` block.
2. Iteration 2 checks `webhooks_update` state. The block isn't installed, but the standalone-marker check finds `nova_ext_sim_central:webhooks_create` (just installed!) in the file. State is wrongly classified as `standalone_shim`.
3. `standalone_shim` triggers the strip-and-replace path, which strips **every** registered standalone marker (including the one we just installed), then writes `webhooks_update`.
4. File now contains only one block. Dashboard re-checks: the missing shim sees the present shim's marker as a foreign standalone, says "Standalone extension shim present &mdash; take over with Update Shim." Forever.

Fix: drop the `standalone_marker_ns` / `standalone_marker_tag` fields from both webhook shim entries. The shims no longer register themselves into the cross-shim pool; the two blocks coexist peacefully on the same file.

The fix also documents the rule directly in [Manage.php](controllers/Manage.php) above the webhook shim entries, so a future maintainer doesn't reintroduce it.

## Upgrade

Use the **Update Now** button on the dashboard. After reload:

1. Suite admin &rarr; *Event Webhooks* row should now show **Install Shim** (not the stuck "Update Shim" / standalone-shim state).
2. Click **Install Shim**. Both managed blocks land in `Posts_model.php`. Status should turn green.
3. Verify your existing webhooks still fire by editing a saved post and clicking **Post**.

If your `Posts_model.php` currently contains exactly one of the two webhook blocks (the most likely state after the ping-pong loop), the upgrade handles it cleanly:

- The dashboard sees the present block as `current` and the missing one as `missing`.
- Combined state is `missing` &mdash; you get the **Install Shim** button.
- Clicking it appends only the missing block; the existing one is left alone.

If you somehow ended up with neither block (rare but possible if you clicked rapidly), the upgrade is the same &mdash; **Install Shim** writes both fresh.

## Credits

Same as v1.11.0. MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
