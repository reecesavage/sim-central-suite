# Sim Central Suite v1.26.0 — The applicant's Discord, right in the GM join email

Small feature release for **Discord Sign-In**: the join-application notification GMs receive now carries the applicant's linked Discord, restoring (and improving on) what v1.23.0 briefly offered — this time with **no third-party parser dependency**.

## What's new

### Discord row in the GM join-application email

With the new optional **Join application email code** shim installed, the Basic Info block of the "new join application" email gains a **Discord** row showing the applicant's linked public Discord ID — so a GM can check the applicant against their Discord server straight from the inbox, before approving.

On sims that **require Discord linking to join**, an application that somehow arrives without a link is flagged **NOT LINKED** instead, so a bypassed gate is visible immediately.

### How it works (and why it's safe)

- The shim is a thin `_email()` wrapper in `application/controllers/Main.php` — the same managed-block pattern as every other suite shim, installed/removed from the dashboard.
- The email itself is built by a suite library that faithfully reproduces Nova's stock `join_gm` email with the one extra row. If anything at all goes wrong building it, the suite steps aside and **Nova's stock join email sends as normal** — this shim can never cost a sim its GM notifications.
- No `MY_Parser` / `parser_events` requirement. The v1.23.0 version of this feature relied on the third-party parser mod firing events, which not every sim has; v1.24.0 removed that dependency and this feature with it. This implementation depends on nothing outside the suite.
- Shows the **public Discord ID** only — consistent with the suite storing nothing else.

## Upgrade

1. Use the **Update Now** button on the dashboard, or `POST /Api/suite`.
2. On the dashboard, the Discord Sign-In feature will show **Install Shim** — click it to enable the email row. Skipping this is fine: without the shim, join emails are untouched.

No database changes. No configuration changes.

## Credits

MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
