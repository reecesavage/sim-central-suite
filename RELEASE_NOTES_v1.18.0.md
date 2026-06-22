# Sim Central Suite v1.18.0 — Mobile Site: rich-text editor, read-only posted posts, post locking, and accurate recent posts

Four improvements to the mobile site (`/mobile`).

## What's new

### Rich-text editor (Bold / Italic / Underline)
The plain textarea in the post editor is replaced with a `contenteditable` div and a
B / I / U toolbar. Formatting is stored as `<strong>`, `<em>`, and `<u>` tags — the
same inline tags Nova's display pipeline renders on the public site. Content is
sanitized server-side on every save regardless of what the browser submits.

Line-break handling is correct: each visual line break is stored as a single `\n` so
Nova's `nl2br()` display pass produces one `<br>` per break with no extra blank lines.
Existing posts are normalized to this format when loaded into the mobile editor.

### Read-only view for posted posts
Activated and pending posts now open in a read-only view instead of dropping directly
into the edit form. The view renders the post body through Nova's own display pipeline
(matching the public site) and shows the post date and status.

Two actions are available from the read-only view:
- **Edit post** — enters edit mode explicitly.
- **Return to draft** — sets the post back to `saved` status (no content change) so an
  accidentally-posted entry can be corrected and re-posted.

When in edit mode on an already-posted post the button labels change to **Save changes**
(keeps the post active) and **Return to draft** (demotes to draft and saves edits).

### Post locking
The mobile editor now participates in Nova's existing post-lock mechanism
(`post_lock_user` / `post_lock_date`). Entering edit mode acquires the lock. If another
user holds a fresh lock (under 10 minutes old) the read-only view is shown with a
"Locked by {character}" notice and no edit form. The lock is automatically released on
save, delete, unpost, or cancel. A **Cancel editing** button is available to explicitly
release the lock and return to the read-only view.

No JS heartbeat is used — the mobile site relies on the same time-based auto-expiry
(10 minutes) as Nova's desktop write CP.

### Accurate recent posts list
The **Recent posts** list on the post index now matches posts by character (checking
`post_authors`) rather than by user account (checking `post_authors_users`). This
fixes two issues: posts where the user's character appears in the middle of a
co-authored CSV were silently omitted, and posts activated by another author on the
same post were not shown. The recent list now uses the same OR-LIKE matching strategy
as the drafts list.

## Implementation notes

- `controllers/Mobile.php` — read-only/edit-mode branching in `post()`; new `unpost()`
  and `cancel()` actions; updated `_editor()` with contenteditable, toolbar, and
  adapted button labels; new `_readonlyPost()` view; updated `_valuesFromInput()` to
  normalize incoming editor HTML.
- `libraries/PostWrite.php` — new helpers: `postsByChars()`, `lockState()`,
  `acquireLock()`, `releaseLock()`, `editorHtmlToStored()`, `storedToEditorHtml()`.
  All helpers are reusable by the REST API in a future phase.

## Upgrade

Use the **Update Now** button on the dashboard. No database or config changes required.

## Credits

Same as v1.17.4. MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
