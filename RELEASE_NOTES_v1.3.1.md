# Sim Central Suite v1.3.1 — Discord Auth fixes

Two real bugs from v1.3.0, plus a redesigned sign-up flow that respects Nova's character approval process.

## Bug fixes

- **Link / Unlink Discord buttons now appear.** v1.3.0 wired the event listener to `site_usersettings` (the admin's "manage user-created settings" page) instead of `user_account` (the per-user "My Account" page). The buttons existed but on a page nobody ever visited. Now they show up where they should &mdash; on the **User &rarr; My Account** page.
- **Redirects after link/unlink now go to the right page.** Both redirects were pointing at `/admin/usersettings`; they now go to `/user/account`.

## Sign-up flow redesigned

The v1.3.0 **auto-create** mode has been removed.

**Why:** Nova's join flow includes character-approval, default-rank, language/timezone defaults, and other steps that aren't a 1:1 mapping from a Discord identity. Auto-creating a user row outside that flow produced accounts with no character attached &mdash; technically valid but useless on the sim. Sims that gate joins behind GM approval also lost that gate completely with auto-create on.

**New flow &mdash; "Link Discord during join":**

- On the join form, the suite injects a **Link Discord** card at the top.
- The user can either:
  - Fill out the join form normally (Discord stays unlinked; they can link later from User &rarr; My Account).
  - Click **Link Discord** &rarr; bounce through the broker &rarr; come back to the join form with a green "&check; Discord linked: @username" banner and the email field pre-filled from Discord.
- When they submit the form, the suite's `db.insert.prepare.users` listener stamps the Discord columns onto the new user row.
- The character still goes through GM approval like any other join. Discord is just an attached identity, not a bypass.

The existing "Sign in with Discord" button on the login form works as before for users who already have a sim account linked.

### New config option: **Require linking Discord to join**

On the *Discord Sign-In &rarr; Configure* page. When on:

- The join form refuses to submit (client-side) unless the user has clicked **Link Discord** first.
- A red "Linking a Discord account is required to join this sim" notice appears on the form.

Server-side enforcement isn't done (it would require modifying Nova's join controller); the admin's character-approval step is the strict gate if you want one. The client-side guard is a UX nudge, not a hard wall.

## Internal cleanup

- Dropped `DiscordAuth::createUserFromClaims()` &mdash; no longer needed.
- New `DiscordAuth::requiredOnJoin()` accessor, replacing the now-gone `mode()`.
- New `DiscordAuth::columnsForClaims()` exposes the same column map the linking flow uses, so the db.insert.prepare listener can merge them in.
- New event file `events/discord_auth_db.php` hooks `db.insert.prepare.users` to stamp Discord cols at join time, then clears the session.
- Auto-create-mode error codes (`email_already_in_use`, `no_email`) removed from the friendly-error mapper.
- Migration: the obsolete `setting.discord_auth_mode` key is dropped from the settings row on first save after upgrade. No data loss.

## Upgrade

Use the **Update Now** button on the dashboard. After reload:

- **Existing users** who had a Discord account linked &mdash; nothing to do; their link still works.
- **Existing sims that had auto-create on** &mdash; the setting silently becomes "off" (since auto-create no longer exists). You can opt in to the new **Require linking Discord to join** checkbox if you want similar strictness for new sign-ups.
- **First-time setup** &mdash; same as v1.3.0: enable the feature, set up the DB, paste / fetch the public key, save.

## Test plan

1. Update via dashboard, confirm v1.3.1.
2. Sign out, click **Sign in with Discord** &rarr; lands you on the friendly "not linked, here are your options" error page.
3. Sign in normally, go to **User &rarr; My Account** &rarr; *Linked Discord account* section appears with a **Link Discord** button.
4. Click it &rarr; broker bounce &rarr; back at the account page with a green "Discord account linked" flash and the linked identity shown. (This was the broken case in v1.3.0.)
5. Click **Unlink Discord** &rarr; confirm prompt &rarr; section reverts to "Link Discord."
6. Sign out, go to `/main/join` &rarr; the **Link Discord** card appears at the top of the form.
7. Click it &rarr; broker bounce &rarr; back at the join form with the green linked banner + email pre-filled.
8. Fill in character details, submit &rarr; new user gets created, character queued for approval, Discord columns stamped.
9. Toggle **Require linking Discord to join** on, sign out, try to submit the join form without clicking Link first &rarr; client-side alert blocks the submit.

## Credits

Same as v1.3.0. MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
