# Sim Central Suite v1.1.0 — One-click updater

The "Update available" banner now has an **Update Now** button next to it. Click → confirm → done.

## What's new

- **In-app upgrade.** When a newer release is published on GitHub, the dashboard banner gains an **Update Now** button. Clicking it (with a confirm) downloads the release zipball, validates it, atomically swaps the extension directory, and invalidates the PHP opcache so the next request picks up the new code.
- **Automatic backups.** Before the swap, the current version is renamed to `nova_ext_sim_central.backup-YYYYMMDD-HHMMSS/` alongside the live extension dir. Backups are kept indefinitely — the updater never auto-prunes them. Roll back with two shell commands shown on the success page.
- **Settings preserved.** Feature toggles, edited labels, and per-feature settings already live in the `settings` table as of v1.0.0, so the upgrade doesn't touch them.

## Safety rails

- Pre-flight checks: cURL loaded, ZipArchive loaded, extension dir + parent dir writable. Missing any → clear error pointing at the manual update steps. No partial update.
- Concurrency lock: `.sim_central_updating.lock` in the parent dir blocks a second simultaneous update. Stale locks (>5 min) are treated as abandoned.
- Two-step swap with rollback: `current → backup` then `new → current`. If the second rename fails, the backup is renamed back into place and the live dir is restored to the previous version.
- Archive validation before swap: the downloaded archive must contain `init.php` + `config.json`, and the version declared in the new `config.json` must match the requested release. A corrupt or wrong-tag download never reaches the live dir.
- Path-scoped recursive deletes: cleanup operations refuse to walk any path that doesn't carry our staging prefix. We will never accidentally `rm -rf` something unrelated.

## What's NOT in v1.1.0

- Pre-release / branch tracking (only watches the latest published release)
- Downgrades (button only renders when remote > local)
- Auto-pruning old backups (intentional — gives you a manual rollback path forever; clean them up by hand)
- In-app rollback button (planned for a later release; for now use the shell snippet on the success page)

## Requirements (additions for the updater)

- PHP **cURL** extension (already required for the daily update check)
- PHP **ZipArchive** extension (standard in every common PHP build)
- Web user must have write access to `application/extensions/` *and* the existing `application/extensions/nova_ext_sim_central/`

Hosts that deploy via SSH/git under a different user than the web user will see a clear "directory not writable" error on the dashboard if they try. Updating manually still works as before.

## Upgrade from v1.0.x

Drop in over your existing install — no database changes, no shim re-install required. Once you've upgraded to v1.1.0 this way (one last time the hard way), every future update is the **Update Now** button.

## Credits

Same as v1.0.x. MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
