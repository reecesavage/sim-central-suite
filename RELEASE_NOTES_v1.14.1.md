# Sim Central Suite v1.14.1 &mdash; Content Filter: confirm on Post, not Save

Small Content Filter quality-of-life change. The age-gate **submit-confirmation popup** &mdash; the one that asks a writer to attest a post has no explicit content when they leave the age-gate toggle off &mdash; now fires **only when they Post (publish)**, not on every form submit.

## What's new

- **Confirm on Post by default.** Previously the popup fired on *any* submit of the write-post form &mdash; including **Save** (draft) and even **Delete**. Drafts aren't public, so there's nothing to leak at save time; the attestation only matters at the moment content goes live. The popup now fires on **Post** only, and **never on Delete**.
- **Optional "Also confirm when saving a draft."** New checkbox on the *Content Filter &rarr; Configure* page for sims that want the attestation on every save. **Off by default.**

## Design notes

- The write-post form's three buttons are all `name="submit"`, distinguished by value (`post` / `save` / `delete`). The injected confirm script reads `SubmitEvent.submitter`, with a click-tracking fallback for older browsers. If the triggering button genuinely can't be determined (e.g. an Enter-key submit with no context), it falls through to prompting &mdash; the safe default.
- The checkbox itself is gated as `active`↔`inactive` style behaviour only: it changes *when* the existing popup fires, not *what* it says or when the per-post toggle defaults on/off. All other Content Filter behaviour (age-gating bodies for guests, the RSS notice, the per-post default) is unchanged.

## Implementation notes

- `libraries/ContentFilter.php` &mdash; new `confirmOnSave()` (defaults `false`).
- `config.json` &mdash; new `content_filter_confirm_on_save` setting (default `0`).
- `controllers/Manage.php` &mdash; persists the new setting from the config form.
- `views/admin/pages/content_filter.php` &mdash; new "Submit confirmation" section with the checkbox + help text.
- `events/content_filter_location_admin_write_missionpost.php` &mdash; passes the flag to the injected form.
- `views/admin/pages/content_filter_form.php` &mdash; per-button confirm logic in the submit handler.

## Upgrade

Use the **Update Now** button on the dashboard. **No database changes** &mdash; this is a config-level setting. It defaults to off even on installs that haven't re-saved the Content Filter config page, so the new "Post only" behaviour takes effect as soon as the update lands. To opt back into confirming on save, tick *Also confirm when saving a draft* on the Content Filter config page.

## Credits

Same as v1.14.0. MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
