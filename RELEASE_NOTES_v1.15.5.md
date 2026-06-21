# Sim Central Suite v1.15.5 &mdash; Saved-post emails for the API

Adds the "post saved" email to the REST API write path. Activating a post over the API already sent the sim's new-post email; **saving a draft** fired the `post.saved` webhook but didn't send Nova's saved-post email the way the website's Save button does. Now it does.

## What's new

When a post is created or updated to **saved** status through `POST`/`PATCH /posts`, the suite sends Nova's saved-post notification &mdash; the same `write_missionpost_saved` email the web Save button sends, to the post's authors who have the *email on save* preference (`email_mission_posts_save`) enabled. Activated posts continue to send the new-post email as before.

This mirrors how the save/post **webhooks** already behave: save fires the saved-post notification, activate fires the post notification.

## Design notes

- Faithful reuse of Nova's own `nova_write::_email('post_save')` logic and the `write_missionpost_saved` template &mdash; same recipients, same content, same preference gate &mdash; so API-saved drafts notify co-authors identically to a save from the site.
- Gated on the `system_email` setting, and best-effort: a mail failure never blocks the API write.
- Fires on each save (create-as-draft and edits that stay saved), matching the website's behaviour and the save webhook. Authors who haven't opted into save emails don't receive them.

## Implementation notes

- `libraries/PostWrite.php` &mdash; new `afterSave()` / `sendSaveEmail()` alongside the existing activation email helper.
- `controllers/Api.php` &mdash; `_postCreate()` and `_postUpdate()` call `afterSave()` when the resulting status is `saved` (parallel to `afterActivate()` on activation).

## Upgrade

Use the **Update Now** button on the dashboard. No database or config changes. (No effect unless the sim has `system_email` on and authors have the save-email preference enabled.)

## Credits

Same as v1.15.4. MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
