# Sim Central Suite v1.17.0 — Mobile Site

Nova's front-end views aren't responsive, which makes the sim painful on a phone. This adds a **mobile-first site at `/mobile`** where members log in and manage their **mission posts** — write, save, post, edit, delete — without fighting the desktop layout. It's a separate lightweight UI (its own HTML/CSS), not a restyle of the genesis skin.

New **Mobile Site** feature toggle (off by default), enable under *Sim Central Suite → Mobile Site*.

## What's in it (v1 = posts)

- **Login** via Nova's own auth, **honouring the Discord Sign-In feature**: Discord-only sims show just the "Sign in with Discord" button (no password form); other sims show both. Discord sign-ins reuse the existing broker flow — guild checks and required-link enforcement included — and return to `/mobile`.
- **Your posts**: a dashboard of your drafts + recent posts.
- **Create / edit / save / post / delete** mission posts, limited to posts you author (Nova's normal ownership rules). Pick the mission, your character(s), co-authors, location, tags, and the mission-appropriate timeline; **Save** keeps it a draft, **Post** activates it.
- **Identical behaviour to the desktop + API**: every write goes through the suite's shared `PostWrite` engine, so `post.saved` / `post.posted` webhooks, save/post emails, ordered-mission-post timelines (validated per the mission's scheme), and per-user moderation all work the same.

## How it's built

- `controllers/Mobile.php` extends `Nova_controller_main` (session, `Auth`, options, models) and renders its own minimal responsive HTML. It honours Nova's CSRF protection (every form carries the token) and enforces login + post ownership.
- **Clean `/mobile` URL**: Nova's extension dispatch can't be reached by a normal route alias, so a tiny `pre_system` hook (`hooks/mobile_route_hook.php`) rewrites `/mobile[/…]` to the extension controller before CodeIgniter parses the URL. It's auto-registered in `application/config/hooks.php` when you open the Mobile Site config page (idempotent; a manual snippet is shown if that file isn't writable). The full URL `/extensions/nova_ext_sim_central/Mobile` always works.
- Reuses `PostWrite` (loaded now whenever REST API **or** Mobile is on) and the existing `DiscordAuth` flow (a new `intent=mobile` returns Discord logins to `/mobile`).

## Upgrade

Use the **Update Now** button on the dashboard. No database changes. Then *Sim Central Suite → Mobile Site → Enable*, open its config page once (registers the `/mobile` route hook), and share `/mobile` with your members. Existing sites are unaffected until you enable it.

## Not yet (phase 2)

Personal logs and news authoring on mobile. Posts first.

## Credits

Same as v1.16.2. MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
