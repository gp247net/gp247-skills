# update-troubleshooting — refusals, failures and recovery of a template update

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
| `<Key> is not installed (no gp247.json on disk)` (`not_installed`) | No `app/GP247/Templates/<Key>/gp247.json` | Wrong key (case-sensitive) or the template is not on this site — install it instead. |
| `Cannot update directly from version <local>. The new version requires an installed version of <from> or higher…` (admin badge `Requires <from>+`) | The release's `minUpdateFrom` is above the installed version | Get an intermediate release from the author (its files → case C), apply it, then update again. |
| `A license is required to update <Key> (paid item)` | Paid template with no license stored | The user enters the license from the seller: `gp247:ext-license --type=template --key=<Key> --license=<L>`. |
| `The license for <Key> has expired (<date>)` | Update entitlement ended | Renew with the seller, or download the version the license still covers / the new version from the product page (case C). |
| `Invalid license for <Key> (no matching order)` | The stored value is not a license for this product | The user checks the license they bought; never try other values. |
| `The license for <Key> is not valid for this domain` | License issued for another domain (often `APP_URL` is wrong) | Fix `APP_URL`; otherwise the seller moves the license to this domain. |
| `License is not valid for version <v> of <Key>` | The license covers older versions only | Upgrade the license, or stay on the covered version. |
| `Could not verify the license for <Key> (server unreachable)` | The library could not be reached during the license check | Retry later; same connectivity checks as `check_failed`. |
| `<Key> is a paid product — please download the new version from its product page` | A paid template delivered **manually only** | The user downloads the `.zip` from the seller → case C. |
| `No write permission <path>` | `app/GP247/Templates`, `public/GP247/Templates` or `storage/tmp` is not writable by PHP | Fix ownership/permissions for the web-server user; core stopped before touching files. |
| `Downloaded file failed checksum verification, update aborted` | The download was corrupted or altered | Retry; if it repeats, report it to the template's author — do not install the file by hand. |
| `Config key mismatch: …` / `Cannot found file gp247.json` | The package is broken or for another template | Report it to the author. |
| `Downloaded version (<v>) is not newer than the installed one (<local>)` | The library's package is not newer than the files on disk (often the files were already updated by hand) | Check for case C: `ext-update --local --dry-run`. |
| `Incompatibility error. Please check again {…}` | The package's `requireCore`, `requireComposerPackages` or `requireGp247Extensions` is not met | `requireCore` or `gp247/front` / `gp247/shop` → update GP247 first; a GP247 extension → install/update it first. |
| Backup snapshot refused (admin: "Update anyway without a snapshot?"; CLI: the item fails naming the pre-change check) | The site's backup feature could not take the snapshot before the update | Fix the backup problem, or — only when the user confirms they have their own database backup — rerun with `--skip-pre-change`. |

---

## 2. Online update — failed after the files were replaced

**`Update failed, files restored from backup. Error: <msg>`** — the template's `update()` (or a file step)
failed; core put both folders back from the backup and the stores run the old version.

- The database is **not** rolled back: whatever `update()` wrote before failing stays. If the site behaves
  oddly, restore the database backup taken before the update.
- `<msg>` is the template's own error — typically a database step of its `update()` (new settings rows, a
  table for its blocks). Report it to the template's author with the message.

**`data_update_pending`** (CLI) / `<Key> files replaced with version <v>, applying the data update…` (admin)
— core 3.14.3+: the files are the new version, the data update must run in a fresh process.

- CLI could not start the child process (hosting blocks it): run the command in the message, usually
  `php artisan gp247:ext-update --local --type=template --key=<Key>`, or click **Apply data update**.
- If that run fails, core restores the old files from the backup, as above.
- The template shows **Data update pending** until it is done.

**`ext-update --all` exited 0** — it fails only when **every** item failed; read `data.failed` for each key.

---

## 3. Files replaced by hand (case C)

| Symptom / message | Meaning | Next step |
| --- | --- | --- |
| `Data update of <Key> failed: <msg>` / `local_update_failed` | The template's `update()` failed. No file was changed, the template stays pending | Fix the cause named in `<msg>`, run `--local` again — re-running is safe by design. To go back, copy back the folders saved before replacing. |
| Dry run shows nothing pending although new files were copied | `gp247.json` version is not higher than the recorded version, the template is not installed, or the files went to the wrong folder | Check `app/GP247/Templates/<Key>/gp247.json` `version` and the folder name (= `configKey`). |
| Log: `Extension data update skipped … (downgrade)` | The files are **older** than the recorded version | Core never runs a data update downwards. Put the newer files back, or restore a full backup of files **and** database from that time. |
| Core 3.14.3+: apply refused because `public/` cannot be written | Static files must be copied before the data update | Fix write permission on `public/GP247/Templates`, run again. |
| Pages look old or broken after apply (404 on `.css`/`.js`, missing styles) | `public/GP247/Templates/<Key>/` (compiled CSS/JS, images) is missing new files or holds old ones (always on core < 3.14.3 unless published) | `php artisan gp247:ext-publish --type=template --key=<Key>`; reload with Ctrl+F5; purge the CDN. `gp247:doctor` row `extension_assets` lists them (outdated files only from core 3.14.3). |
| The stores still show the old views | OPcache keeps compiled PHP | Restart PHP-FPM or the web server (or the hosting's "clear PHP cache"); `php artisan gp247:cache-rebuild`; apply again if still pending. |
| First apply shows `from` as `unknown`/`?` | The site was installed before core recorded extension versions | Normal; the template's `update()` runs once with `null` and records the version. |
| The admin Import refuses the new `.zip` | Import is for new installs only | Extract the `.zip` and replace the folder (case C). |

---

## 4. Restore a template from core's file backup

Each online update keeps the old folders in
`storage/backups/extensions/Templates/<Key>/<old-version>_<time>/` (the newest 3 per template). **Tell the user
before restoring** — it overwrites the current folders.

1. Pick the `<version>_<time>` folder to go back to.
2. Replace `app/GP247/Templates/<Key>/` with that folder's `app/` contents (the whole folder).
3. Replace `public/GP247/Templates/<Key>/` with its `public/` contents.
4. If the newer version's `update()` already changed the database, restore the database backup taken before
   the update too — old code on new data can fail, and core does not run data updates downwards.

For a template updated by hand (case C), the backup is the copy made before replacing the folder.

---

## 5. When nothing is offered although a new version exists

Work through, in order:

1. `ext-search --keyword=<Key> --json` has a `warnings` entry → the library is unreachable (§1 `check_failed`).
2. `ext-check-update` was run without `--force` → the cached answer (several hours) predates the release.
3. The new version's `requireCore` is above the site's core (the probe's `core`) → update GP247 first.
4. Paid template with no stored license → the admin shows the version without an Update button; enter the license.
5. The template is not in the library at all (custom or bought elsewhere) → get the files from its author, case C.

---

## 6. Re-applying local edits after an update

When the user kept a copy of the edited template (step 4 of `SKILL.md`), or from core's backup:

```bash
OLD=storage/backups/manual/<Key>_<old-version>      # core's backup: storage/backups/extensions/Templates/<Key>/<old-version>_<time>/app
git diff --no-index --stat "$OLD" app/GP247/Templates/<Key>
git diff --no-index "$OLD/<file>" app/GP247/Templates/<Key>/<file>      # one file at a time
```

- The diff mixes the user's edits with the author's changes between the two versions. Show it file by file
  and let the user pick what to carry over; never copy the old file over the new one wholesale — it would undo
  the release's fixes in that file.
- Edited compiled CSS cannot be merged: the change belongs in the template's source and a rebuild, or in a
  template of the site's own name.
- If the same edits will be needed after every release, recommend a separate template with its own key
  (`gp247-template-create`) instead of editing this one.

## 7. `GP247Front` (the default template)

| Symptom / message | Meaning | Next step |
| --- | --- | --- |
| `gp247:doctor` row `template_source` lists frozen files | Files published into `app/GP247/Templates/GP247Front/` hide the package version and miss its fixes | `php artisan gp247:template-prune GP247Front --dry-run`, then without `--dry-run` after the user agrees. |
| `template_not_published` from prune | Nothing was ever published — the template already reads everything from the package | Nothing to do. |
| `template_source_missing` from prune | No package provides this template (a site's own theme) | Prune applies only to package templates. |
| After `composer update` the storefront still shows an old screen | That screen is a published (frozen) copy, edited or not | Prune it if unedited; otherwise port the package change into it by hand, or delete it to take the package version. |
| Storefront CSS/JS old after `composer update` | The asset copy refreshes itself on a later request; a CDN or the browser may hold the old file | `php artisan vendor:publish --tag=gp247:front-public --force` (safe: a regenerated copy), Ctrl+F5, purge the CDN. |
