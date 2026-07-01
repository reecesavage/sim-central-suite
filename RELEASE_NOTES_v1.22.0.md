# Sim Central Suite v1.22.0 — Open Positions API

## New

- **`GET /Api/positions` — list a sim's open crew positions.** Returns positions with one or more
  unfilled slots (`pos_open > 0`), ordered by department then roster order. Add `?top=1` to return
  only the **top open positions** (the headline billets flagged in the roster admin, the same set
  shown on the join page). Also supports `?display=y|n`, `?page=`, and `?per_page=`. New scope:
  `positions:read`.

  Each position returns `id`, `name`, `description`, `department_id`, `department` (resolved name),
  `open` (unfilled slots), `type`, `order`, and `top_open`. The endpoint reuses Nova's own
  open-positions query, so results match the site exactly.

  The endpoint, its parameters, and its response schema are registered in the single
  `ApiEndpoints` source, so the interactive **API Explorer** and the **OpenAPI 3.0 spec**
  (`GET /Api/openapi`) pick it up automatically.

  Resolves [#1](https://github.com/reecesavage/sim-central-suite/issues/1).

## Upgrade

Use the **Update Now** button on the dashboard, or `POST /Api/suite`. No database changes. Grant
`positions:read` to any token that should read positions (ACP → *REST API → Configure*).

## Credits

MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
