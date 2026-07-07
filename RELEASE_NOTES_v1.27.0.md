# Sim Central Suite v1.27.0 — Updates finish themselves: automatic post-update housekeeping

Until now, updating the suite was one click — but when a release changed the database or added a shim (like v1.25.0's Discord storage slim-down or v1.26.0's join-email shim), an admin still had to visit the dashboard and press **Set Up Database** / **Install Shim** afterwards. For remotely-updated sims, nobody was there to press them. As of this release, the update finishes itself.

## What's new

### Automatic post-update housekeeping

On the **first request after an update** — a dashboard reload, or simply the next page view or API call after a remote `POST /suite` upgrade — the suite automatically runs whatever database setup and shim installs the new version needs, **for enabled features only**.

The rules:

- **Disabled features are never touched.** Enable a feature later and you'll get its setup steps from the dashboard as always.
- **Only clear-cut shim work is automated**: installing a shim that isn't there yet, or refreshing the suite's own managed block to a newer version. Take-over situations — a standalone extension's old shim, or an unmarked hand-written method in the target file — are always left for a human on the dashboard.
- **It's the same code as the buttons.** The dashboard's Set Up Database / Install Shim actions and the auto-runner now share one implementation, so behaviour is identical either way.
- **Once per version, race-safe.** A marker in your settings row plus a database lock guarantee the run happens exactly once, even under concurrent traffic. The steady-state cost on every other request is a single array lookup.
- **You see what happened.** The next dashboard visit shows a one-time summary of everything the auto-runner did. Anything it couldn't complete (say, a controller file the web user can't write) stays flagged on the feature cards exactly as before.

Concretely: a sim on v1.24.x that updates straight to v1.27.0 with Discord Sign-In enabled will, on the next request, get its database aligned and the new join-email shim installed — no dashboard visit required.

### Fixes

- **Shim installs now report unwritable files.** Previously a failed write could report success; now you get a clear "not writable" error (and the auto-runner surfaces it in its summary).

## Upgrade

Use the **Update Now** button on the dashboard, or `POST /Api/suite`. This is the last release where you'll need to think about the follow-up buttons — v1.27.0 itself has no database changes, and from here on, future releases' housekeeping runs automatically.

## Credits

MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
