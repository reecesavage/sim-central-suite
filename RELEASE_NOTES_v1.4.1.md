# Sim Central Suite v1.4.1 — Discord login honors user status

## Bug fix

**Discord sign-in let users with `pending` status sign in.** Before this release, a user who'd joined the sim but hadn't been approved by a GM yet could still get a session by signing in with their already-linked Discord account &mdash; bypassing the same status gate that email + password sign-in enforces. Same gap applied to maintenance mode (the Discord path didn't check it).

**Fixed.** `DiscordAuth::loginUserById()` now performs the same two checks Nova's `Auth::login()` does:

- If the user's `status` is `'pending'` &mdash; refuse with the same message email/password login shows (uses Nova's `lang('error_login_7')` so the wording is identical: *"Your account is currently pending game master review..."*).
- If maintenance mode is on AND the user is not a sysadmin &mdash; refuse with `lang('error_login_5')`.

The refusal renders on the standard Discord auth error page (the one users already see for "email not verified" or "token expired"), with a clear flash message. No session gets set in either case.

## Why this was missing

v1.3.0 shipped `loginUserById()` as a thin replicator of Nova's protected `_set_session()`. `_set_session` itself does NOT do the status checks &mdash; those live in the calling `login()` method that wraps it. So copying just the session-setting half let pending users slip through. v1.4.1 moves the check into the suite's wrapper, which is the right layer.

## What else changed

Nothing. This is a pure bug-fix release: no DB changes, no new config options, no behaviour changes for users who already had `active` status.

## Test plan

1. Update via dashboard, confirm v1.4.1.
2. Find a `pending` user (e.g., a new join awaiting GM approval) who has a Discord account linked. If you don't have one handy, temporarily set a test user's status to pending: `UPDATE nova_users SET status = 'pending' WHERE userid = N;`.
3. Sign out, click **Sign in with Discord**, complete the Discord flow.
4. Expect: the Discord auth error page with the message *"Your account is currently pending game master review. You will not be allowed to log in until your application has been accepted..."* &mdash; same wording as the email/password login.
5. Restore the user to `status = 'active'`, try again, expect normal sign-in.
6. (Optional) Toggle maintenance mode on as a non-sysadmin user with linked Discord, confirm sign-in is refused with the maintenance message.

## Credits

Same as v1.4.0. MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
