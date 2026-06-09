# Sim Central Suite v1.14.4 &mdash; Anti-Spam question delete fix

Bug-fix. Deleting an anti-spam question did nothing &mdash; you'd get the delete confirmation, submit it, and the question would still be there. (Editing and adding worked fine.)

## Root cause

The delete confirmation form posts to `Manage/anti_spam/delete/{id}`, which puts the `delete` action at URI **segment 5** &mdash; segment 4 is the controller method name (`anti_spam`). The handler was checking `segment(4) === 'delete'`, which is never true (it's always `"anti_spam"`), so the delete branch never ran. The form just round-tripped back to the list and the row remained.

Add and edit were unaffected because they use their own dedicated controller methods (`anti_spam_create` / `anti_spam_edit`), not the segment-gated branch.

## What's fixed

- `controllers/Manage.php` &mdash; the delete dispatch in `anti_spam()` now checks `segment(5)` instead of `segment(4)`. The existing `_antiSpamDelete()` logic (validate id, `delete_setting()` by `setting_id`) was already correct and is unchanged; it just never got called before.

Deleting a question now removes it and shows the *"Question deleted."* confirmation.

## Upgrade

Use the **Update Now** button on the dashboard. No database changes.

## Credits

Same as v1.14.3. MIT licensed. Thanks to the admin who reported the delete not taking.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
