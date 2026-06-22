# Sim Central Suite v1.16.2 — Email lang + webhook "Saved by" fixes

Two bug fixes for the post-authoring + webhook paths.

## 1. API post emails showed raw language keys

API-sent post emails came through with untranslated placeholders, e.g.:

```
Subject: ... Aperture Science - Reece and Brad Test  email_subject_saved_post
Body:    email_content_mission_post_saved
```

**Cause:** the email wording lives in Nova's **core-module** `email_lang.php`, but a plain `lang('email')` load from an extension controller resolves to CodeIgniter's *own* `email_lang.php` (which doesn't have those keys), so the lookups returned the key name itself.

**Fix:** the email helpers now load Nova's email language from the core module explicitly (and via the return-array path, so a previously-cached CI email lang can't shadow it). Both the saved-post and posted-post emails now render their real subject and body.

## 2. Webhook "Saved by" showed a non-author on Nova saves

On the `post.saved` webhook, a save made **in Nova** showed the user's *main* character as "Saved by" even when that character wasn't one of the post's authors — while a save from the **API** correctly showed the author that's actually on the post. (Nova's web save records the main character as the saver regardless.)

**Fix:** the webhook now normalises the actor to a character that's genuinely on the post — if the recorded saver isn't an author, it uses that user's highest-ranked character that *is* on the post (main character if it's on the post, otherwise by rank). API saves already recorded an on-post character, so they're unchanged; Nova saves now match. If the saver truly has no character on the post (e.g. a GM saved someone else's draft), the recorded character is kept.

This makes the "Saved by" name (and the actor excluded from the co-author ping) consistent regardless of whether the save came from Nova or the API.

## Implementation notes

- `libraries/PostWrite.php` — `emailLang()` loads `email_lang` with the core-module `alt_path` and `return=TRUE`; the email builders read lines from that array instead of `lang()`.
- `libraries/Webhooks.php` — new `resolveActorChar()`, applied in `loadPost()` so the webhook actor is always an on-post character when one exists.

## Upgrade

Use the **Update Now** button on the dashboard. No database or config changes.

## Credits

Same as v1.16.1. MIT licensed. Thanks to the admin who reported the raw email keys and the "Saved by" mismatch.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
