# Sim Central Suite v1.4.0 — Required Discord linking + branded button

Two big additions to the Discord Sign-In feature: a site-wide enforcement mode that requires every user to keep Discord linked, and properly Discord-branded sign-in buttons across the four places they appear.

## What's new

### 🔒 Require all users to keep Discord linked
New checkbox on *Discord Sign-In &rarr; Configure*. When on:

- **Logged-in users without a linked Discord** are redirected to a dedicated forced-link page on every request until they finish linking. The page has one big **Link Discord** button and a small "sign out" link as the escape hatch.
- **Unlinking is disabled.** The Unlink button on the User &rarr; My Account page is replaced with **Change Discord account** &mdash; users can switch which Discord account is linked, but can't fully detach.
- **The email + password login form still works.** Users can still sign in with their sim password (so they aren't locked out if Discord OAuth is temporarily down); they just can't navigate anywhere except the forced-link page until linking is finished.
- **Required-on-join is implicitly forced.** The earlier "Require linking Discord to join" checkbox becomes mandatory when global require is on. The UI shows it as disabled + checked to make the relationship clear.

The forced-link enforcement runs in `init.php` (post-controller-constructor time), so a redirect actually short-circuits the rest of the request without rendering the original page first. URLs that have to stay reachable are skipped: the Discord auth controller itself, the login/logout routes, and asset paths.

### 🎨 Discord-branded button
The four places that show "Sign in with Discord" / "Link Discord" / "Sign up with Discord" / "Change Discord account" buttons now render with Discord's actual brand styling:

- **Background:** `#5865F2` (Discord Blurple) with `#4752C4` on hover and `#3C45A5` on click
- **Logo:** inline SVG of Discord's official Clyde mark, sized to match the text height
- **Font stack:** prefers Discord's "gg sans" if installed locally, falls back to Inter / system sans-serif on every platform

The four button surfaces:
- **`/login`** &mdash; Sign in with Discord
- **`/main/join`** &mdash; Link Discord card at the top of the form
- **`/user/account`** &mdash; Link Discord *or* Change Discord account (depending on mode)
- **Forced-link page** &mdash; Link Discord

A new `events/discord_auth_template_render.php` injects the button CSS on every render when the feature is enabled, so the styles work on every page the buttons appear.

## Why "Discord is down" doesn't lock you out

A sim that requires Discord linking still allows sim-password sign-in. The check happens AFTER login. So:

- **Existing users with Discord already linked** &mdash; sign in normally with either method, use the site as usual.
- **Users with no Discord linked yet** &mdash; can sign in, but get parked on the forced-link page until Discord OAuth is reachable again.

This matches what the user asked for: don't tie login itself to Discord availability.

## Internal additions

- `DiscordAuth::requiresLink()` &mdash; reads `setting.discord_auth_required` (0/1).
- `DiscordAuth::requiredPageUrl()` &mdash; canonical URL for the forced-link page.
- `DiscordAuth::shouldEnforceLink($uri)` &mdash; deciding function used by the init.php hook; combines the setting check, the session check, the skip-URL list, and the per-user Discord-ID lookup.
- `DiscordAuth::brandedButtonHtml($label, $url)` &mdash; single source of truth for the branded button markup. Includes the Clyde SVG and the `nova-ext-discord-button` class consumed by the injected CSS.
- `controllers/DiscordAuth.php` &mdash; new `required()` route that renders the forced-link page; `unlink()` now refuses when require-link is on and surfaces a clear flash message.
- `views/main/pages/discord_auth_required.php` &mdash; new view, single CTA, with a sign-out fallback.
- `events/discord_auth_template_render.php` &mdash; new event that injects the button CSS on every render.

## Upgrade

Use the **Update Now** button on the dashboard. After reload:

- **No DB migrations.** Existing rows untouched; no new columns.
- **No behaviour change unless you flip the switch.** The new "Require all users to keep Discord linked" checkbox starts OFF.

To enable globally-required linking after the upgrade:

1. *Sim Central Suite &rarr; Discord Sign-In &rarr; Configure*
2. Scroll to the new **Site-wide enforcement** section.
3. Tick **Require all users to keep Discord linked** &rarr; **Save Configuration**.
4. Existing users without Discord will be prompted to link on their next page load.

## Credits

Same as v1.3.x. MIT licensed. Discord brand assets used per [Discord's brand guidelines](https://discord.com/branding).

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
