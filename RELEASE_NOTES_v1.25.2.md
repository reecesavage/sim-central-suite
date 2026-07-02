# Sim Central Suite v1.25.2 — Join & login blocks land on the right form (LCARS and friends)

Follow-up to v1.25.1's injection fix. That release made the placement script dependency-free, but on LCARS the join card was *still* invisible — for a different reason this time, and one that predates v1.25.1 entirely.

## The bug

Skins in the LCARS family render a **hidden sign-in form in the page header** (the slide-down "Log in" panel) on every page. The suite placed its join card and login button with a document-wide "first `<form>` on the page" rule — and on those skins the first form is that invisible header panel. The card was being injected faithfully… inside a `display:none` container. Verified against the live LCARS join page.

Worse, on required-link sims the join page's submit guard keyed on "the form has an email field", which *also* matched the header sign-in panel — so trying to log in from the join page could get blocked with the "Discord linking is required to join" alert.

## The fix

- **Placement now targets the nearest form.** Nova appends the suite's output right after the view that produced it, so the emitted script now selects the matching element **nearest before itself** in the page — the view's own form — instead of the first one in the document. Join card, hidden link token, and the login page's Discord button + Remember me checkbox all use this.
- **The join submit guard is scoped** to forms posting to `main/join` (and carrying an email field). Header sign-in panels are never blocked.
- **Discord-only login mode hides every password form** on the login page, not just the first one it finds — previously an LCARS-style header panel could keep a visible password form in Discord-only mode.

Verified against the live LCARS join page HTML in a real browser: card visible above the form, link token riding the join form (not the header panel), header login unaffected by the guard.

## Upgrade

Use the **Update Now** button on the dashboard, or `POST /Api/suite`. No database changes. No configuration changes.

## Credits

MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
