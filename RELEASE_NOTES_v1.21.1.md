# Sim Central Suite v1.21.1 — Sim Central access UX fixes

A small follow-up to v1.21.0 polishing the **Sim Central access** page.

## Fixes

- **Buttons render correctly.** The *Grant Sim Central access*, *Revoke access*, and
  *Save broker configuration* controls used button classes the admin skin doesn't define, so
  they showed up unstyled (and easy to mistake for plain links). They now use the suite's
  standard button styling, like every other form in the ACP.

- **Broker configuration is now optional.** The **Broker URL** defaults to the hosted Sim
  Central broker (`registry.simcentral.host`), so the only thing you need to set before clicking
  **Grant Sim Central access** is the broker **secret**. The settings panel says as much.

## Upgrade

Use the **Update Now** button on the dashboard. No database changes.

## Credits

MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
