# Sim Central Suite v1.23.0 — Discord-on-join reliability

## Fixes

- **Discord identity is now reliably saved when someone joins with it linked.** The link was
  stashed only in the session across the Discord OAuth round-trip, and if that session didn't
  survive to form submit (a known cross-site-cookie failure mode) the new user row was created with
  no Discord data. The join form now also carries the broker's **signed JWT** as a hidden field;
  the stamp step re-verifies it from the POST (tamper-proof, same request) and falls back to the
  session claims only if the JWT has since expired. Result: the Discord columns are populated
  whether or not the session persisted.

## New

- **"Discord linking required to join" is now enforced server-side.** Previously the requirement was
  only a client-side JavaScript guard, which a determined user could bypass. A hard gate now blocks
  the join submit when linking is required and no valid Discord link is present. No Nova core files
  are changed — the gate short-circuits in the extension before the join controller runs.

- **The new-applicant email to GMs now shows the linked Discord account.** When an applicant joins
  with Discord linked, the notification email that goes to staff includes a **Discord: @username
  (ID …)** row alongside their name, email, and character details. The applicant's Discord also
  already appears on their account page when a sysadmin views the pending user (now that it's
  saved).

## Upgrade

Use the **Update Now** button on the dashboard, or `POST /Api/suite`. No database changes — the
Discord columns already exist from the Discord Sign-In feature.

## Credits

MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
