# Sim Central Suite v1.17.3 — Mobile Site UX polish

Round of fixes from first real use of the Mobile Site.

## Discord sign-in button

The mobile login's "Sign in with Discord" used Nova's `brandedButtonHtml`, whose SVG has no dimensions and relies on desktop-skin CSS — so in the minimal mobile layout it rendered as a screen-filling logo. Replaced with a proper, compact branded button (small icon + label) that carries `intent=mobile`, so Discord sign-ins return to **/mobile** rather than the main site.

## Timeline shows only what the mission uses

The editor previously showed every Ordered-Mission-Posts timeline field (Day, Date, Stardate) at once. Now it shows **only the field the selected mission's scheme uses** — `day_time` → Mission day, `date_time` → Date, `stardate` → Stardate — and updates live when you change the mission (Time always shows). Each mission option carries its scheme; a tiny inline script toggles the right field.

## Co-author picker for large casts

The author list was plain checkboxes — unworkable on sims with dozens or hundreds of NPCs/characters. Now:

- **Your characters** are pinned at the top as modern toggle switches (pre-selected on a new post).
- **Co-authors** are a scrollable list with a **search box** that filters as you type.
- All entries are touch-friendly toggle switches instead of bare checkboxes.

## Implementation notes

- `controllers/Mobile.php` — own Discord button markup; mission `<option>`s carry `data-config`; timeline fields wrapped with `data-tl` and toggled by an inline script; author rows are toggle switches; co-author search input filters the list; CSS for switches, the Discord button, and the search/list.

## Upgrade

Use the **Update Now** button on the dashboard. No database or config changes.

## Credits

Same as v1.17.2. MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
