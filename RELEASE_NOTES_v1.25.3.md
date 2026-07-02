# Sim Central Suite v1.25.3 — Anti-spam question can't silently kill submits; Discord ID on user pages

Two fixes following the LCARS shakedown in v1.25.2.

## Fixes

### The security question no longer blocks submits invisibly (tabbed skins)

The Anti Spam Questions field carried an HTML `required` attribute. On skins that render the join form inside jQuery UI tabs (LCARS et al.), the question can sit in a tab that's hidden when the writer clicks Submit — and browsers refuse to submit a form with an empty `required` control they can't focus, reporting nothing except a console warning (`An invalid form control ... is not focusable`). The page just sat there.

Now:

- The `required` attribute is gone (an `aria-required` hint remains for screen readers).
- A submit guard shows a clear **"Please answer the security question"** alert, reveals the tab holding the question when it's hidden, scrolls to it, and focuses the field.
- The server-side answer check is unchanged and still enforces regardless of JS.

Same treatment on the contact form's question.

### Linked Discord shows on the public user page

`/personnel/user/{id}` now shows a **Discord** row (the linked public Discord ID) alongside Name / Email / Timezone, for users who have Discord linked. Nothing renders for unlinked users. This is the same public ID shown on the account page — the one piece of Discord identity the suite stores.

## Upgrade

Use the **Update Now** button on the dashboard, or `POST /Api/suite`. No database changes. No configuration changes.

## Credits

MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
