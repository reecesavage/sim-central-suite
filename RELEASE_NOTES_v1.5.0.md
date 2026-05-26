# Sim Central Suite v1.5.0 — Simpler Content Filter

Replaces the 0-3 rating dropdowns on the Content Filter config page with three plain checkboxes (Adult Language, Violence, Sex) and adds a new "default state of the per-post age-gate" toggle.

## What changed

### Three booleans instead of three 0-3 dropdowns

The Content Filter config page used to ask you to pick a 0-3 rating for each of Language, Sex, and Violence (matching rpgrating.com's scale), with the filter only acting on dimensions at rating 3 (explicit). Sex and Violence at 3 gated content; Language was tracked but never gated.

That's now collapsed to three yes/no toggles:

- **Adult language** — *Frequent strong profanity or hate speech*
- **Violence** — *Explicit violence not depicted*
- **Sex** — *Sexual content expressed in detail*

Each is independent. Tick whichever dimensions your sim permits, leave the others off. The filter activates as soon as ANY of the three is on (so language can now trigger gating too if you opt into it — previously it couldn't).

### New per-post age-gate default toggle

A new **New posts are age-gated by default** checkbox at the bottom of the same page controls the initial state of the age-gate checkbox on the write-post form for *new* posts:

- **On (default)** — every new post starts age-gated; the writer has to deliberately untick the box (which fires the existing submit-time confirmation listing what they're attesting to) to publish without the gate.
- **Off** — every new post starts NOT gated; the writer ticks the box only when their specific post actually contains the explicit content the sim permits.

The submit-time confirmation still fires whenever a writer leaves the box unticked, regardless of the default. So writers always get one last chance to confirm their gating decision when they're publishing something that won't be hidden from guests.

Existing posts are untouched — they use whatever value was stored when they were last saved.

## Why

The 0-3 scale was always overkill for what the suite actually did with the data (gate or don't gate). The new model maps directly to what gets enforced. Plus the default-state toggle lets sims with rare explicit content default to "off" instead of having writers always untick the box on every routine post.

## Migration

**Existing sims with the old 0-3 ratings:** no admin action required. `ContentFilter::allows()` reads the new boolean keys first and falls back to the old rating keys (treating any old rating of 3 as the new "allowed" toggle being on). The config page shows the migrated state — checkboxes reflect what your old ratings would have gated. The first time you save the config page after upgrading, the old numeric keys are pruned from the settings row and replaced with the new booleans.

Same pattern we used for the v1.3.1 `discord_auth_mode` cleanup.

## Implementation notes

- `ContentFilter::ratings()` renamed to `ContentFilter::allows()` (returns booleans now). Internal-only call; no event hooks touched it.
- `ContentFilter::DIMENSIONS` now includes `'language'`.
- `ContentFilter::isActive()` returns true when ANY dimension is on (previously: only when Sex OR Violence was at 3).
- `ContentFilter::gatedDefinitions()` now includes the language definition when language is permitted, so writers attest to that too.
- `ContentFilter::defaultAgeGate()` is the new accessor for the per-post default.
- `nova_ext_content_filter.definition_language` is a new editable label (defaults to *"Frequent strong profanity or hate speech"*).
- Save handler writes `content_filter_allows_*` keys and deletes any leftover `content_filter_*` numeric keys from the settings row.

## Upgrade

Use the **Update Now** button on the dashboard. After reload:

1. Visit *Sim Central Suite &rarr; Content Filter &rarr; Configure*.
2. Confirm the three checkboxes reflect what you actually want gated (they should match your old rating-3 selections by default).
3. Decide on the per-post age-gate default (top of the page: on = the existing behavior).
4. Customize the new "Adult language definition" if needed, plus the existing sex/violence definitions and notice text.
5. Click **Save Configuration**. This is what prunes the old numeric keys from the settings row.

Database columns and the per-post toggle data are unchanged.

## Credits

Same as v1.4.x. MIT licensed. Definitions reference [rpgrating.com](https://rpgrating.com/create).

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
