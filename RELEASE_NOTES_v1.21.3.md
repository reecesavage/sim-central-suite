# Sim Central Suite v1.21.3 — API spec catch-up (post locking + /suite)

Documentation/spec fix. No behaviour change — these endpoints already worked; they just weren't
described in the machine-readable catalog, so they were missing from the **API Explorer** and the
**OpenAPI 3.0 spec** (`GET /Api/openapi`).

## What's now documented

Both the Explorer and the OpenAPI spec are generated from one catalog (`ApiEndpoints`). These had
been added to the controller but never to the catalog:

- **Post edit locking** *(shipped in v1.19.0)* — `GET / POST / PUT / DELETE /posts/{id}/lock`, plus
  the `423 Locked` behaviour and auto-release on `PATCH /posts/{id}`.
- **Suite self-management** *(shipped in v1.21.0–1.21.2)* — `GET /suite` (version status) and
  `POST /suite` (run the updater).

New response schemas (`LockState`, `SuiteStatus`, `SuiteUpdateResult`) back them, and the
`REST_API.md` reference gained a **Post edit locking** section.

## Upgrade

Use the **Update Now** button on the dashboard (or `POST /Api/suite`). No database changes.

## Credits

MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
