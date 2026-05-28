# Sim Central Suite v1.10.0 &mdash; REST API: interactive explorer + OpenAPI spec

Adds two helper surfaces on top of the existing REST API: an admin-side **API Explorer** for hands-on debugging, and a publicly-served **OpenAPI 3.0** spec for importing into external tooling.

## What's new

### API Explorer (admin page)

*Sim Central Suite &rarr; REST API &rarr; API Explorer*. Lists every endpoint with:

- Method + path + scope chip at the top of each card
- Summary + full description
- Parameters table (name, in, type, default, description)
- Toggleable response shape preview
- Inline inputs for path and query parameters
- **Try it** button &mdash; fires a live request to the real endpoint and renders the JSON response inline (with status code + response time)
- **Copy curl** button &mdash; puts a ready-to-paste curl command on the clipboard with the current parameter values and your token

The page has one token input at the top. It's sent as `X-API-Key` on every Try It request, never stored anywhere &mdash; not in the session, not in localStorage. The list of active tokens (label + prefix + scopes) is shown below the input as a hint so you know which raw value to paste.

### OpenAPI 3.0 spec endpoint

`GET /extensions/nova_ext_sim_central/Api/openapi`. Returns the OpenAPI 3.0 document describing every endpoint, parameter, and response schema. **No authentication required** &mdash; OpenAPI specs are public documents by convention (this is how Postman, Insomnia, Stoplight, and n8n's OpenAPI import expect to fetch them). The endpoint still 404s when the REST API feature is off, matching the existing "no surface leak" rule for the rest of the API.

The spec includes:

- An `apiKey` security scheme pointing at the `X-API-Key` header
- Per-endpoint `security` declarations referencing the relevant scope
- Component schemas for `Post`, `Character`, `Mission`, and their paginated envelopes
- All four error responses (`401`, `403`, `404`, `429`, `503`) attached to each endpoint where they can fire
- Suite-feature-conditional fields tagged with an `x-suite-feature` extension so tooling can surface "this field only appears when feature X is enabled" without breaking the standard shape

### Single source of truth

Both surfaces read from a new `libraries/ApiEndpoints.php` registry. Adding an endpoint is one place: add the entry to `endpoints()`, optionally add a schema to `schemas()`, and the explorer page + the OpenAPI spec pick it up automatically.

## Why an admin-only explorer (rather than an unauthenticated playground)?

The same reason token issuance is admin-only: the API exposes sim data (mission posts, character details, mission roster) that's available on the public site anyway, but bundled into a fast queryable form that makes scraping trivial. Putting the explorer behind `site/settings` access doesn't make the data more secret &mdash; it does make the *discovery* of the API surface less casual. The OpenAPI spec is public by design (any consumer needs it to wire up a client), but the live "click to fire a real request from your browser" UX stays in the ACP.

## Implementation notes

- New library `libraries/ApiEndpoints.php` &mdash; `endpoints()`, `schemas()`, `toOpenApi($baseUrl)`. Loaded from `init.php` alongside `ApiAuth` when the feature is on.
- New controller method `Api::openapi()` &mdash; gated by `_gate()` like every other endpoint, but skips `_authenticate()` because the spec is meant to be fetchable for bootstrapping integrations.
- New controller method `Manage::api_explorer()` &mdash; gated by `Auth::check_access('site/settings')`. Loads the explorer view and passes it the endpoint catalog + the list of active tokens (for the prefix-hint display).
- New view `views/admin/pages/api_explorer.php` &mdash; per-endpoint cards rendered server-side, Try It / Copy curl behaviour driven by inline JS (no jQuery dependency &mdash; vanilla `fetch()` + `navigator.clipboard`).
- The explorer's Try It calls go to the same-origin URL (`/extensions/.../Api/...`), so no CORS configuration is needed.

## Upgrade

Use the **Update Now** button on the dashboard. Code-only change &mdash; no DB updates. Once on 1.10.0:

1. *Sim Central Suite &rarr; REST API &rarr;* you'll see a new **Open the API Explorer** link below the base-URL hint.
2. Click it &rarr; paste any active `scapi_...` token at the top &rarr; click **Try it** on `GET /ping`. Expect a `200` with `{ok: true, token_label: "...", now: "..."}`.
3. To grab the OpenAPI spec for an external tool: `curl https://yoursim.example/extensions/nova_ext_sim_central/Api/openapi > sim-central.openapi.json`, then import that file into your tool of choice.

## Credits

Same as v1.9.3. MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
