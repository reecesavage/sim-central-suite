# Sim Central Suite v1.9.2 &mdash; REST API hotfix

Bug-fix release. The REST API endpoints introduced in v1.9.1 crashed with `Class "nova_ext_sim_central\Config" not found` on every request, because the feature-toggle 404 gate ran in the Api controller's constructor &mdash; before Nova had loaded the suite's namespace.

## What's fixed

### Api controller no longer references the suite namespace from its constructor

Nova loads each extension's `init.php` (which registers the `\nova_ext_sim_central\*` namespace) through a hook attached to `post_controller_constructor`. That hook fires **after** the routed controller's `__construct()` runs. The previous build's constructor immediately called `\nova_ext_sim_central\Config::features()` for the feature-toggle 404 gate, so every request to `/extensions/nova_ext_sim_central/Api/...` fataled with a class-not-found error before the gate could even decide whether to 404.

The fix moves the feature-toggle gate out of the constructor into a small `_gate()` helper that runs as the first line of every endpoint method (`ping`, `posts`, `characters`, `missions`). By the time any action method runs, the `extensions` hook has fired and the suite namespace is fully loaded.

The constructor now does nothing suite-specific &mdash; just `parent::__construct()` + `$this->load->database()`. A comment block in the file explains the constraint so this doesn't regress.

Nothing else changed in this release. v1.9.1 admins should upgrade to get the API actually responding.

## Upgrade

Use the **Update Now** button on the dashboard. After reload the existing tokens, scopes, and rate-limit settings are preserved &mdash; this is a code-only fix, no database changes.

Verify with:

```sh
curl -H "Authorization: Bearer scapi_..." https://yoursim.example/extensions/nova_ext_sim_central/Api/ping
```

Expected: `{"ok":true,"token_label":"...","now":"..."}`. If you still get a 500 after upgrading, double-check that the opcache has been invalidated (the in-app updater does this automatically; if you upgraded manually, restart PHP-FPM or run `opcache_reset()`).

## Credits

Same as v1.9.1. MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
