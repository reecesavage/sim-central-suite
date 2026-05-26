# Sim Central Suite v1.5.1 — Slider toggle + default-aware copy

UX polish on the per-post age-gate control. No DB changes, no new settings, no behavioural changes for posts already in the database.

## What changed

### Modern slider toggle on the write-post form
The plain checkbox on the post form is now a CSS slider toggle (iOS / Android settings style) &mdash; same kind of "feels right" affordance every modern app uses for binary on/off. Green when on (age-gated), grey when off.

Pure CSS, no JS library: the underlying `<input type="checkbox">` is still there for accessibility, keyboard navigation, and form submission. The slider is just the visual.

The config-page checkboxes (where the admin sets which dimensions are permitted and the per-post default) are unchanged &mdash; checkboxes feel right in a settings list.

### Default-aware helper copy
The helper line under the toggle now reads differently based on the admin's *New posts are age-gated by default* setting:

- **Default ON** &mdash; *"Unselect only if this post does NOT contain any of:"* (cautionary; writers are opting out of the safer default)
- **Default OFF** &mdash; *"Select if this post contains any of:"* (encouraging-action; writers are opting in to gate explicit posts)

Single-dimension sims get singular phrasing (*"contain"* / *"contains"*) instead of *"contain any of"*.

### Confirm-time message updated for clarity
The submit-time confirmation now reads:

> Age-gating is OFF for this post &mdash; it will be visible to all visitors.
> Confirm the post does NOT contain:
>  - &lt;definition 1&gt;
>  - &lt;definition 2&gt;
>  - ...
> Continue submitting?

The previous wording (*"You've marked this post as safe for public viewing..."*) only made sense when the default was ON; the new wording works regardless of which default the admin chose. The confirm continues to fire only when the toggle is OFF at submit time.

## Upgrade

Use the **Update Now** button on the dashboard. Visit any write-post page; the slider should render in place of the old checkbox.

## Credits

Same as v1.5.0. MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
