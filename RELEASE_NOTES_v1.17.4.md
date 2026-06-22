# Sim Central Suite v1.17.4 — Mobile Discord login returns to /mobile

Fixes the mobile Discord sign-in dropping users on the main site instead of `/mobile`.

## Cause

The mobile login's Discord button passes `intent=mobile`, and the callback already honoured it — but `DiscordAuth::start()` whitelists the intent against `['login','join','link']` and falls back to `login` for anything else. So `mobile` was silently downgraded to `login`, and the post-login redirect went to the site root.

## Fix

`start()` now accepts `mobile` as a valid intent, so it survives the broker round-trip and the callback returns the user to `/mobile`.

## Implementation notes

- `controllers/DiscordAuth.php` — add `'mobile'` to the allowed intents in `start()`. (The callback redirect for `intent=mobile` was already in place from v1.17.0.)

## Upgrade

Use the **Update Now** button on the dashboard. No database or config changes.

## Credits

Same as v1.17.3. MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
