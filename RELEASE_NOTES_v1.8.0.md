# Sim Central Suite v1.8.0 — Discord-only login

Lock down sign-in so users must authenticate via Discord, with a sysadmin email + password escape hatch for when Discord OAuth is down or you need to get into the admin urgently.

## What's new

### "Lock sign-in to Discord" setting

New checkbox on the *Discord Sign-In &rarr; Configure* page, under **Site-wide enforcement**. When on:

- **Login page UX** &mdash; the email + password form is hidden by default. Visitors see only the Discord-branded **Sign in with Discord** button. A small "Sysadmin sign-in with email + password &raquo;" toggle reveals the form for sysadmins who need it.
- **Enforcement on every request** &mdash; the suite checks every logged-in user against three conditions:
  - **Sysadmin** &rarr; allowed regardless of sign-in method (the escape hatch).
  - **Signed in via Discord** (or just linked Discord this session) &rarr; allowed.
  - **Everyone else** &rarr; bounced to a forced sign-in page on every request until they sign in via Discord (or link it first if they haven't).

### The forced page adapts

The existing forced-link page (`/extensions/nova_ext_sim_central/DiscordAuth/required`) now serves both cases:

| User state | Page title | CTA |
| --- | --- | --- |
| No Discord linked | *Link your Discord to continue* | **Link Discord** (intent=link) |
| Discord linked, signed in via email + password | *Please sign in with Discord to continue* | **Sign in with Discord** (intent=login) |

Same template, copy and button adapt. The "sign out" link at the bottom stays for both cases as the escape route to a different account.

### Why a session marker

The suite tags every Discord-flow session with a `discord_auth_signed_in` userdata flag. That's how the enforcement hook tells the difference between:

- *"You signed in via Discord just now"* (marker present &rarr; allowed)
- *"You signed in via email + password and may not be allowed here"* (marker absent &rarr; check conditions)

The marker is also set when a logged-in user successfully **links** Discord through the callback &mdash; linking proves they own the Discord account, equivalent to a fresh sign-in for the purposes of this gate.

### Implied dependencies

Turning on *Lock sign-in to Discord* implicitly turns on *Require all users to keep Discord linked* (you can't require Discord sign-in for users who don't have Discord linked). The config-page copy calls this out explicitly.

## Why a "sysadmin escape hatch"?

Because the alternative is dangerous. If Discord OAuth is down (Discord outage, or your broker is broken, or a Cloudflare issue), and sign-in is locked to Discord with no escape hatch, **nobody can get into the sim &mdash; including the people who can fix the configuration**. Email + password sign-in restricted to sysadmins is the standard pattern for this; it's how every "SSO required" setting works in commercial products.

If you don't want this, the workaround is: don't turn the setting on. The existing v1.4.0 *Require all users to keep Discord linked* setting forces Discord linking without removing email + password as a sign-in option.

## Implementation notes

- `DiscordAuth::loginDiscordOnly()` &mdash; new accessor for the setting.
- `DiscordAuth::requiresLink()` &mdash; now returns true implicitly when `loginDiscordOnly()` is on.
- `DiscordAuth::shouldEnforceDiscordOnly($uri)` &mdash; the per-request check used by `init.php`. Returns true only when the setting is on, the user is logged in, isn't a sysadmin, doesn't have the `discord_auth_signed_in` marker, and isn't on a skip-listed URL.
- `DiscordAuth::loginUserById()` &mdash; sets the marker on every successful Discord sign-in.
- `controllers/DiscordAuth::callback()` link branch &mdash; sets the marker on successful link too.
- `controllers/DiscordAuth::required()` &mdash; gained state-aware CTA selection. Page title and button label come from whether the user has Discord linked yet.
- `views/main/pages/discord_auth_required.php` &mdash; gained an `if ($has_linked)` branch for the adapted copy.
- `events/discord_auth_location_login_index.php` &mdash; injects a CSS-hide + JS-reveal toggle around the stock email + password form when the setting is on.

The actual security boundary is server-side (the `init.php` enforcement hook). The form-hide is just polish so the right sign-in method is the visible default.

## Upgrade

Use the **Update Now** button on the dashboard. After reload:

1. *Sim Central Suite &rarr; Discord Sign-In &rarr; Configure*
2. Scroll to **Site-wide enforcement**
3. Tick **Lock sign-in to Discord (sysadmin email + password escape hatch)** &rarr; **Save Configuration**.
4. Sign out, visit `/login`. You should see only the **Sign in with Discord** button, with a small "Sysadmin sign-in" toggle below.

If anything breaks, the recovery path is:

- Open a terminal on your hosting and run:
  ```sql
  UPDATE nova_settings
  SET setting_value = REPLACE(setting_value, '"discord_auth_login_discord_only":1', '"discord_auth_login_discord_only":0')
  WHERE setting_key = 'sim_central_state';
  ```
- Or sign in as a sysadmin via the reveal toggle and untick the setting from the admin UI.

## Credits

Same as v1.7.0. MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
