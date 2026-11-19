# update-troubleshooting — refusals, failures and recovery of a plugin update

Read this when a step of `SKILL.md` is refused or fails. Messages are quoted in English as core prints
them; the admin shows the same text in the site's language, and wording can vary slightly by core
version. Branch on `error.code` / the case, show the message to the user, give the next step.

---

## 1. Online update (cases A and B) — refused before any file changes

Nothing on disk or in the database has changed; fix the cause and run the update again.

| Message / `error.code` | Meaning | Next step |
| --- | --- | --- |
| `<Key> is already up to date` (`up_to_date`) | The library has nothing newer **for this site** | If `ext-search` shows a newer version, it needs a newer core — update GP247 first. |
| `Could not check the marketplace for updates to <Key>: …` (`check_failed`) | Network, SSL certificate, API License or domain problem; the library's reason follows the colon | Check `GP247_API_LICENSE` and `APP_URL` (real domain), the server's outbound HTTPS; register the API License again with `gp247-extension-lifecycle` if the message points to it. |
| `<Key> is not installed (no gp247.json on disk)` (`not_installed`) | No `app/GP247/Plugins/<Key>/gp247.json` | Wrong key (case-sensitive) or the plugin is not on this site — install it instead. |
| `Cannot update directly from version <local>. The new version requires an installed version of <from> or higher…` (admin badge `Requires <from>+`) | The release's `minUpdateFrom` is above the installed version | Get an intermediate release from the author (its files → case C), apply it, then update again. |
| `A license is required to update <Key> (paid item)` | Paid plugin with no license stored | The user enters the license from the seller: `gp247:ext-license --type=plugin --key=<Key> --license=<L>`. |
| `The license for <Key> has expired (<date>)` | Update entitlement ended | Renew with the seller, or download the version the license still covers / the new version from the product page (case C). |
| `Invalid license for <Key> (no matching order)` | The stored value is not a license for this product | The user checks the license they bought; never try other values. |
| `The license for <Key> is not valid for this domain` | License issued for another domain (often `APP_URL` is wrong) | Fix `APP_URL`; otherwise the seller moves the license to this domain. |
| `License is not valid for version <v> of <Key>` | The license covers older versions only | Upgrade the license, or stay on the covered version. |
| `Could not verify the license for <Key> (server unreachable)` | The library could not be reached during the license check | Retry later; same connectivity checks as `check_failed`. |
| `<Key> is a paid product — please download the new version from its product page` | A paid plugin delivered **manually only** | The user downloads the `.zip` from the seller → case C. |
| `No write permission <path>` | `app/GP247/Plugins`, `public/GP247/Plugins` or `storage/tmp` is not writable by PHP | Fix ownership/permissions for the web-server user; core stopped before touching files. |
| `Downloaded file failed checksum verification, update aborted` | The download was corrupted or altered | Retry; if it repeats, report it to the plugin's author — do not install the file by hand. |
| `Config key mismatch: …` / `Cannot found file gp247.json` | The package is broken or for another plugin | Report it to the author. |
| `Downloaded version (<v>) is not newer than the installed one (<local>)` | The library's package is not newer than the files on disk (often the files were already updated by hand) | Check for case C: `ext-update --local --dry-run`. |
| `Incompatibility error. Please check again {…}` | The package's `requireCore`, `requireComposerPackages` or `requireGp247Extensions` is not met | `requireCore` → update GP247 first; a Composer package → install it (on a dev checkout follow the site's own Composer rules); a GP247 extension → install/update it first. |
| Backup snapshot refused (admin: "Update anyway without a snapshot?"; CLI: the item fails naming the pre-change check) | The site's backup feature could not take the snapshot before the update | Fix the backup problem, or — only when the user confirms they have their own database backup — rerun with `--skip-pre-change`. |

---

## 2. Online update — failed after the files were replaced

**`Update failed, files restored from backup. Error: <msg>`** — the plugin's `update()` (or a file step)
failed; core put both folders back from the backup and the site runs the old version.

- The database is **not** rolled back: whatever `update()` wrote before failing stays. If the site behaves
  oddly, restore the database backup taken before the update.
- `<msg>` is the plugin's own error. Typical causes: a database error in the plugin's migration (report to the
  author with the message), or — on core older than 3.14.3 — **another plugin that extends this one** had
  already loaded the old code. Then update the extending plugin first if it has a new version, or disable it,
  update this plugin, enable it again.

**`data_update_pending`** (CLI) / `<Key> files replaced with version <v>, applying the data update…` (admin)
— core 3.14.3+: the files are the new version, the data update must run in a fresh process.

- CLI could not start the child process (hosting blocks it): run the command in the message, usually
  `php artisan gp247:ext-update --local --type=plugin --key=<Key>`, or click **Apply data update**.
- If that run fails, core restores the old files from the backup, as above.
- The plugin shows **Data update pending** until it is done.

**`ext-update --all` exited 0** — it fails only when **every** item failed; read `data.failed` for each key.

---

## 3. Files replaced by hand (case C)

| Symptom / message | Meaning | Next step |
| --- | --- | --- |
| `Data update of <Key> failed: <msg>` / `local_update_failed` | The plugin's `update()` failed. No file was changed, the plugin stays pending | Fix the cause named in `<msg>`, run `--local` again — re-running is safe by design. To go back, copy back the folders saved before replacing. |
| Dry run shows nothing pending although new files were copied | `gp247.json` version is not higher than the recorded version, the plugin is not installed, or the files went to the wrong folder | Check `app/GP247/Plugins/<Key>/gp247.json` `version` and the folder name (= `configKey`). |
| Log: `Extension data update skipped … (downgrade)` | The files are **older** than the recorded version | Core never runs a data update downwards. Put the newer files back, or restore a full backup of files **and** database from that time. |
| Core 3.14.3+: apply refused because `public/` cannot be written | Static files must be copied before the data update | Fix write permission on `public/GP247/Plugins`, run again. |
| Pages look old or broken after apply (404 on `.css`/`.js`, missing styles) | `public/GP247/Plugins/<Key>/` is missing new files or holds old ones (always on core < 3.14.3 unless published) | `php artisan gp247:ext-publish --type=plugin --key=<Key>`; reload with Ctrl+F5; purge the CDN. `gp247:doctor` row `extension_assets` lists them (outdated files only from core 3.14.3). |
| The site still runs the old code | OPcache keeps compiled PHP | Restart PHP-FPM or the web server (or the hosting's "clear PHP cache"), then apply again if still pending. |
| First apply shows `from` as `unknown`/`?` | The site was installed before core recorded plugin versions | Normal; the plugin's `update()` runs once with `null` and records the version. |
| The admin Import refuses the new `.zip` | Import is for new installs only | Extract the `.zip` and replace the folder (case C). |

---

## 4. Restore a plugin from core's file backup

Each online update keeps the old folders in
`storage/backups/extensions/Plugins/<Key>/<old-version>_<time>/` (the newest 3 per plugin). **Tell the user
before restoring** — it overwrites the current folders.

1. Pick the `<version>_<time>` folder to go back to.
2. Replace `app/GP247/Plugins/<Key>/` with that folder's `app/` contents (the whole folder).
3. Replace `public/GP247/Plugins/<Key>/` with its `public/` contents.
4. If the newer version's `update()` already changed the database, restore the database backup taken before
   the update too — old code on new data can fail, and core does not run data updates downwards.

For a plugin updated by hand (case C), the backup is the copy made before replacing the folder.

---

## 5. When nothing is offered although a new version exists

Work through, in order:

1. `ext-search --keyword=<Key> --json` has a `warnings` entry → the library is unreachable (§1 `check_failed`).
2. `ext-check-update` was run without `--force` → the cached answer (several hours) predates the release.
3. The new version's `requireCore` is above the site's core (the probe's `core`) → update GP247 first.
4. Paid plugin with no stored license → the admin shows the version without an Update button; enter the license.
5. The plugin is not in the library at all (custom or bought elsewhere) → get the files from its author, case C.
