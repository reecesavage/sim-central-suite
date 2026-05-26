# Sim Central Suite v1.3.0 — Discord Sign-In

Adds Discord OAuth2 sign-in via the [Sim Central Broker](https://github.com/reecesavage/sim-central-broker) (or any compatible broker). One Discord app, one redirect URI, however many sims you want.

## What's new

### 🔐 Discord Sign-In feature

Toggle on the dashboard like any other feature. When enabled:

- **"Sign in with Discord" button** appears on the login form.
- **"Sign up with Discord" button** appears on the join form *(auto-create mode only)*.
- **Link Discord** section appears on the User Settings page so existing users can attach their Discord account at any time.
- **Unlink Discord** button on the same page, gated behind a "you need a password set" check so users can't accidentally lock themselves out.

### Two account-creation modes

Configurable on the *Discord Sign-In &rarr; Configure* page:

- **Link-only** *(default)* &mdash; Discord sign-in only matches existing users by Discord ID. New users have to sign up the normal way first, then link Discord from User Settings. Safer default; nobody can create a sim account just by having a Discord account.
- **Auto-create** &mdash; If Discord sign-in matches no existing user, a new account is created on the spot using the email and username from Discord. Equivalent to "Sign up with Discord."

Both modes refuse any Discord account whose email isn't verified &mdash; the broker enforces this and the suite re-checks it as a safety net.

### Broker model

The actual Discord OAuth dance happens in the broker, a tiny Cloudflare Worker hosted at `auth.simcentral.host`. The sim never has to be registered as a redirect URI with Discord. The broker mints a short-lived signed JWT and redirects the user back to the sim, which verifies the signature locally with the broker's public key.

If you'd rather run your own broker, the [sim-central-broker repo](https://github.com/reecesavage/sim-central-broker) has the source and a deploy walkthrough (~15 minutes on Cloudflare Workers' free tier).

### Per-feature config page

- **Broker URL** &mdash; defaults to `https://auth.simcentral.host`. Override if self-hosting.
- **Broker public key (PEM)** &mdash; pasted in, OR fetched automatically from the broker's `/.well-known/jwks.json` endpoint via a **Fetch from broker JWKS** button.
- **Account creation mode** &mdash; link-only / auto-create radio.
- **Callback URL** display &mdash; shown for reference; no need to register it anywhere since the broker accepts any return_to on the fly.

## Database changes

Five new columns added to `users` when you enable the feature:

| Column | Type | Index |
| --- | --- | --- |
| `nova_ext_discord_auth_id` | VARCHAR(32) NULL | UNIQUE |
| `nova_ext_discord_auth_username` | VARCHAR(100) NULL | &mdash; |
| `nova_ext_discord_auth_avatar` | VARCHAR(64) NULL | &mdash; |
| `nova_ext_discord_auth_email_verified` | TINYINT NULL | &mdash; |
| `nova_ext_discord_auth_linked_at` | INT NULL | &mdash; |

The UNIQUE index on `nova_ext_discord_auth_id` prevents two sim users from being linked to the same Discord account. Click **Set Up Database** on the dashboard row to apply.

## Security model

| Layer | Protection |
| --- | --- |
| HTTPS (browser &harr; broker &harr; sim) | Network sniffing of the JWT |
| Random state token, single-use, 600s TTL (broker side) | OAuth CSRF, callback replay |
| `aud` claim in JWT, checked against the sim's own origin | A token issued for Sim A can't be replayed against Sim B |
| `exp` claim (5 min) | A leaked URL can't be used hours later |
| `email_verified` gate at broker | Unverified Discord accounts can't sign in to ANY sim |
| `email_verified` re-check at suite | Belt-and-suspenders if a non-canonical broker has looser policy |
| UNIQUE index on Discord ID | One sim user per Discord account |

The broker's private key never leaves Cloudflare's secret store. The suite stores only the public key.

## Requirements (additions)

- PHP **OpenSSL** extension (used for JWT signature verification &mdash; ships with virtually every PHP build)
- The broker must be reachable from end-user browsers (i.e. publicly hosted)

## Upgrade

Use the **Update Now** button on the dashboard. After reload:

1. Enable the *Discord Sign-In* feature, click **Set Up Database**.
2. Visit *Configure* &rarr; click **Fetch from broker JWGS** &rarr; **Save Configuration**.
3. Choose your account-creation mode.
4. Test by signing out, then clicking the new **Sign in with Discord** button on the login page.

## What's NOT in v1.3.0

- **Discord-required mode** &mdash; disabling email/password sign-in entirely. Coming in a later release; ping if you want it sooner.
- **Discord guild role sync** &mdash; mapping a user's Discord roles to sim access roles. Requires the bot scope and a heavier integration. Spec-able later if you have a use case.
- **"Sign in to link" bridge flow** &mdash; if a user comes through Discord in link-only mode without an existing match, they get a clear "sign in first, then link from User Settings" message. A smoother one-screen flow is a v1.4 candidate.

## Credits

Same as v1.2.x. MIT licensed. Broker is at <https://github.com/reecesavage/sim-central-broker>.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
