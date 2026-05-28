# Sim Central Suite v1.11.3 &mdash; Webhook edit hotfix

Bug-fix release. The **Edit** button on the Event Webhooks manage page didn't load the existing webhook into the form &mdash; the URL changed to `.../webhooks/edit/{id}` but the form still showed the empty "Create webhook" state.

## What's fixed

### Edit button now populates the form

The edit link is `/Manage/webhooks/edit/{id}`. Under Nova's extension router the URI segments are `extensions / <name> / Manage / webhooks / edit / {id}` &mdash; so the id lives in **segment 6**, with segment 5 being the literal string `edit`.

The controller was reading the id from segment 5, getting `"edit"`, casting it to `(int)` (which yields `0`), and therefore never loading the row. The form fell back to create mode.

Fixed to read segment 6 when segment 5 is `edit`. The form now pre-fills with the webhook's label, URL, format, event subscriptions, enabled state, and Discord templates, and submits as an update.

## Upgrade

Use the **Update Now** button on the dashboard. Code-only change &mdash; no DB updates. After reload, *Event Webhooks &rarr; Configure &rarr; Edit* on any webhook will load its values for editing.

## Credits

Same as v1.11.0. MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
