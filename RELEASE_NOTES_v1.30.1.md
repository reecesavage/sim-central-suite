# Sim Central Suite v1.30.1 — Fix Astrolabe snapshot mission post counts

Bug-fix for the v1.30.0 Astrolabe snapshot endpoint.

## Fix

- **`GET /snapshot` no longer errors on `stories`.** The mission `posts_count` called `count_mission_posts()` on the missions model, but that method lives on the **posts** model — so the snapshot threw `Call to undefined method Missions_model::count_mission_posts()` and returned a 500. Now it calls the posts model, and passes the `single` count preference so it actually returns the post count (the default preference returned 0).

## Also

- **The snapshot degrades gracefully.** Each section (game, stats, manifest, stories, recent_posts) is now built defensively: if one ever fails on a particular sim's data, that section comes back empty/null and the error is logged, instead of 500ing the whole endpoint. Astrolabe always gets a usable, cacheable payload.

## Upgrade

Use the **Update Now** button on the dashboard, or `POST /Api/suite`. No database changes.

## Credits

MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
