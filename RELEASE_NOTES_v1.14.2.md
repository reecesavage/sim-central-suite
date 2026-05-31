# Sim Central Suite v1.14.2 &mdash; Content Filter confirm hotfix

Bug-fix for v1.14.1. The age-gate submit-confirmation popup still fired on **Save** and **Delete**, despite the v1.14.1 change that was meant to limit it to **Post**.

## Root cause

v1.14.1 detected which button submitted the form by reading `SubmitEvent.submitter` with a DOM-walk click-tracking fallback (matching on `type === 'submit'`). That detection didn't hold up against how Nova drives the write-post form: Nova binds its **own** jQuery click handlers to `#submitPost` and `#submitDelete`:

```js
$('#submitDelete').click(function(){ return confirm('...'); });
$('#submitPost').click(function(){ return confirm('...'); });
```

Between those handlers and the event flow on the page, the suite's detection came back empty &mdash; and an empty/unknown action falls through to prompting (the safe default). So every submit prompted, save and delete included.

## What's fixed

- The confirm script now **binds directly to each button** (Post via `id="submitPost"`, Delete via `id="submitDelete"`, Save by its `name="submit"` value) on `mousedown` / `click` / `keydown`, recording which one is in use, and still reads `SubmitEvent.submitter` when the browser provides it. Direct binding means detection no longer depends on the button's `type` or on `submitter` support, so it survives Nova's own click handlers.
- Result, as originally intended:
  - **Post** &rarr; confirms (when the age-gate toggle is off)
  - **Save** &rarr; no confirm, unless *Also confirm when saving a draft* is enabled
  - **Delete** &rarr; never confirms

## Upgrade

Use the **Update Now** button on the dashboard. **No database changes.** After updating, hard-refresh the write-post page (Cmd/Ctrl+Shift+R) so the browser re-fetches the page with the corrected inline script.

No settings changed; the *Also confirm when saving a draft* option (default off) introduced in v1.14.1 behaves the same &mdash; it just works correctly now.

## Credits

Same as v1.14.1. MIT licensed. Thanks to the admin who reported the popup still firing on save/delete.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
