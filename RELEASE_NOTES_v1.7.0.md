# Sim Central Suite v1.7.0 — Required Discord guild membership

Limit Discord sign-in / sign-up / linking to users who are a member of one or more specific Discord servers. No bot required &mdash; the broker fetches the user's guild list via Discord's OAuth `guilds` scope and the suite checks it locally before letting them in.

## Requires

**Sim Central Broker v1.1.0 or newer.** The broker's `?guilds=1` opt-in (added in 1.1.0) is what makes the guild claim appear on the JWT. If you point the suite at an older broker AND configure guild requirements, sign-in is refused with a clear "broker is out of date" message.

If you've already updated the canonical broker at `auth.simcentral.host` to v1.1.0, you're set. Sims pointing at their own broker need to deploy the broker update first.

## What's new

### Required Discord guild IDs

New section on *Discord Sign-In &rarr; Configure*:

- **Required guild IDs** &mdash; one Discord snowflake per line (or comma-separated). Empty = no check.
- **Match mode** &mdash; *Any of* (must be in at least one listed server) or *All of* (must be in every listed server).
- **Help text shown on refusal** &mdash; admin-editable; HTML allowed so you can paste invite-link anchors like `<a href="https://discord.gg/yoursim">join the community</a>`.

The check runs after JWT signature verification, before any of the login / link / join branches. Same gate for all three flows: sign in, link existing account, sign up via join form.

### Backward compatibility

- **Sims with no guild check configured** &mdash; nothing changes. The suite doesn't pass `?guilds=1`, the broker uses the smaller `identify email` scope, no extra Discord consent prompt for users.
- **Sims with guild check configured + broker v1.1.0+** &mdash; broker requests the `guilds` scope, fetches the user's server list, includes it in the JWT, suite enforces.
- **Sims with guild check configured + broker v1.0.0** &mdash; suite refuses sign-in with a friendly `broker_lacks_guilds_claim` error pointing the admin at the broker upgrade.

### Where the check happens

```
User clicks "Sign in with Discord"
  -> Broker (passes ?guilds=1)
  -> Discord (asks user to authorize identify + email + guilds)
  -> Broker (fetches /users/@me + /users/@me/guilds)
  -> Suite callback
     -> verify JWT signature      ← always
     -> verify email_verified=true ← always
     -> guild check                ← v1.7.0+, only when configured
     -> [login / link / join branches]
```

## Implementation notes

- `DiscordAuth::requiredGuildIds()` returns the parsed snowflake array. Empty = no check, so the rest of the codebase short-circuits.
- `DiscordAuth::requiresGuildCheck()` is what `brokerStartUrl()` consults to decide whether to add `&guilds=1`. Sims that haven't configured a check keep the old, scope-minimal flow.
- `DiscordAuth::guildCheckPasses($claims)` returns `array('ok', null)` on pass, or `array('error', 'guild_not_member' | 'broker_lacks_guilds_claim')`.
- Save handler accepts the textarea in either newline- or comma-separated form, filters to digits-only (Discord snowflake shape), de-duplicates.
- The discord-auth error view now renders messages as trusted HTML so the admin's invite-link anchors render. Every existing call site already passes pre-safe content; the contract is documented inline in the view.

## Upgrade

1. **Make sure the broker is on v1.1.0+.** (Canonical broker `auth.simcentral.host` &mdash; already done. Self-hosted &mdash; `git pull && wrangler deploy`.)
2. Use the **Update Now** button on the Sim Central dashboard.
3. *Sim Central Suite &rarr; Discord Sign-In &rarr; Configure* &rarr; scroll to **Required Discord guild membership** &rarr; paste guild IDs, pick mode, write help text &rarr; **Save Configuration**.

Sign out, try **Sign in with Discord** as a user not in any of the required servers: should see the refusal page with your help text. Join one of the servers, click *Try again*, should let you in.

## What's NOT in v1.7.0

- **Real-time guild kick detection.** The check happens only at sign-in. A user who's already signed in and gets kicked from the required server keeps their session until it expires or they sign out.
- **Discord role checks within a guild.** Membership only. Role-based gating requires the bot scope and a heavier integration; potential v1.8 if there's demand.

## Credits

Same as v1.6.x. MIT licensed. Broker is at <https://github.com/reecesavage/sim-central-broker>.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
