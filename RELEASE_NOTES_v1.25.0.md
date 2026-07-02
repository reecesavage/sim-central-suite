# Sim Central Suite v1.25.0 — Leaner Discord Sign-In: minimal scopes, minimal storage, remember me

Privacy and quality-of-life release for **Discord Sign-In**. The suite now asks Discord for as little as possible, keeps as little as possible, and finally gives Discord sign-ins the same "Remember me" treatment as password logins.

## What's new

### Minimal Discord scopes by default

The suite now sends **v2 requests** to the auth broker (broker v1.2.0+). By default the Discord consent screen asks only for **basic identity** — no email scope at all (plus your server list, only when the sim runs a guild-membership check).

If you want the join form's email field pre-filled from Discord, there's a new **Request Discord email** option on *Discord Sign-In → Configure* (off by default). When on, the email scope is requested, sign-in requires a verified Discord email, and the address is used to pre-fill the join form — it is shown to the user, never stored.

Pointing at a self-hosted broker older than v1.2.0? Nothing breaks — older brokers ignore the v2 flag and keep their legacy behaviour (email scope always requested).

### Minimal storage

Discord Sign-In stores exactly two things on the user record: the user's **public Discord ID** (the snowflake anyone who shares a server with them can see) and **when it was linked**. Nothing else — no email, no auth tokens, nothing that could be used to access anyone's Discord account. Anything else the sim needs (username, avatar) can be looked up live from the ID.

After updating, open the suite dashboard and click **Set Up Database** on Discord Sign-In if prompted — it aligns the `users` table with this minimal footprint.

### Remember me for Discord sign-ins

The login page's **Sign in with Discord** button now has a **Remember me** checkbox, mirroring the one on Nova's password form. Tick it and the suite sets Nova's standard 14-day remember-me cookies after the Discord sign-in completes, so the session survives browser restarts — Nova's own auto-login takes over from there, identical to a remembered password login.

Also on the **Mobile Site** login: remember-me checkboxes for both the Discord button *and* the email + password form (which previously had no way to be remembered — rough on a phone).

One caveat for sims running **Lock sign-in to Discord**: a session revived by Nova's auto-login doesn't count as a fresh Discord authentication, so locked sims still bounce remembered users through the (instant, already-authorized) Discord redirect. That keeps the lock meaningful.

### Account page tidy-up

The *Linked Discord account* section on the user account page now shows the linked Discord ID directly. Usernames change on Discord's side, so the stable public ID is the honest thing to display.

## Upgrade

Use the **Update Now** button on the dashboard, or `POST /Api/suite`. Then, if the dashboard prompts for it, click **Set Up Database** on the Discord Sign-In feature.

For the minimal-scope behaviour, the canonical broker at `auth.simcentral.host` is already on v1.2.0+. Self-hosted brokers should update: `git pull && npx wrangler deploy` in the broker repo.

## Credits

MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
