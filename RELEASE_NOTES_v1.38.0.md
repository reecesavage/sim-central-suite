# Sim Central Suite v1.38.0 — bio fields on the REST API

`GET /characters/{id}` now returns the sim's whole character bio form, with this character's answers.

Until now the API could tell you a character's name, rank, status and owner — everything except the part players actually write. Reading a bio meant scraping `personnel/character/{id}`.

## `bio`

A flat array, one entry per visible bio field, in the order the sim's own bio page renders them: by tab, then by section within the tab, then by field within the section.

```json
{
  "id": 7,
  "first_name": "Kade",
  "last_name": "Rethis",
  "status": "active",
  "bio": [
    {
      "id": 2,
      "name": "species",
      "label": "Species",
      "type": "text",
      "value": "Betazoid",
      "section": { "id": 1, "name": "Character Information", "tab": { "id": 1, "name": "Basic Info" } }
    },
    {
      "id": 8,
      "name": "physical_desc",
      "label": "Physical Description",
      "type": "textarea",
      "value": "<p>Tall.</p>\n<p>Dark hair.</p>",
      "section": { "id": 2, "name": "Physical Appearance", "tab": { "id": 1, "name": "Basic Info" } }
    }
  ]
}
```

| Field | Notes |
|---|---|
| `name` | The machine name (`hair_color`). Stable if the sim renames the label — key off this, not `label` |
| `label` | What the bio page shows, entity-decoded: `Strengths & Weaknesses`, not `&amp;`. Falls back to `name` when the sim left it blank |
| `type` | `text`, `textarea`, or `select` |
| `value` | Raw as stored — a `textarea` field holds the same rich text a post body does. Always a string; `""` when unset |
| `section` | Carries its tab, because section names are optional in Nova and the stock install ships one blank (its "History" heading lives on the tab) |

Group on `section.id` if you want it nested; the ordering already puts each section's fields together.

**Every visible field is listed, answered or not.** A field the character has never filled in comes back with `value: ""` rather than being skipped, so the array always describes the sim's whole form — which is what you need to render one. Fields the sim has switched off in the ACP (`field_display = n`) are omitted entirely.

## Where it is, and isn't

Single-character reads only. On `GET /characters` it would mean a query per row and a payload dominated by bio text, so the list is unchanged.

Scope is unchanged too: `characters:read`, the same scope that already reads the endpoint.

**No new exposure.** Nova's own `personnel/character/{id}` has no login check and no `crew_type` gate, so any bio is already readable by an anonymous visitor at any status. This endpoint is strictly narrower — it needs a token.

## Verified

49 assertions against Nova's stock bio layout plus the cases a real sim accumulates: a hidden field (absent, and its value never appears anywhere in the payload), a section with a blank name, a field with no label, a field whose section was deleted, a character with no stored values at all, a sim with no bio fields configured, and another character's values in the same table. Ordering, the two-query shape, and the `data_char` scoping are asserted directly rather than inferred from the output.

## Upgrade

Use the **Update Now** button on the dashboard, or `POST /Api/suite`. No database changes, no configuration changes.

## Credits

MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
