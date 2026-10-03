# `gp247:ext-*` command matrix — options, exit codes, decisions & failure codes

Read this at Workflow step 3 when you need a flag, an exit-code rule or a failure code. The **site's own
`php artisan help gp247:<command>` is the source of truth** for options; this file explains what they do.
Check the probe's `commands` list before using a command — older cores lack some of them.

Commands that act on extensions take `--type=plugin|template` (default `plugin`). With `--json` (if
`capabilities.json_output`) every command prints the envelope
`{"ok": bool, "command": str, "data": {...}, "warnings": [...], "error": {"code","message"} | null}`.

## Command reference

| Command | Options | What it does | Exit code |
| --- | --- | --- | --- |
| `gp247:ext-list` | `--type` | Local extensions with installed / active / version (from `gp247.json`) / cached update flag. **Cache-only, no API call.** Does not show pending data updates. | 0 |
| `gp247:ext-install` | `--type`, `--file=*`, `--dir=*`, `--key=*`, `--paid`, `--license=` | Install from `.zip`, extracted folder, or key. See the source decision below. | ≠ 0 if **any** item failed (`install_failed`) |
| `gp247:ext-enable` | `--type`, `--key=*` | Enable **installed** extensions. | ≠ 0 if any item failed (`enable_failed`) |
| `gp247:ext-disable` | `--type`, `--key=*` | Disable installed extensions. Refused for a template in use / the default template. | ≠ 0 if any item failed (`disable_failed`) |
| `gp247:ext-uninstall` | `--type`, `--key=*`, `--only-data`, `--purge` | See the uninstall matrix. | ≠ 0 if any item failed (`uninstall_failed`) |
| `gp247:ext-update` | `--type`, `--key=` (one), `--all` | **Library update**: download the newer release, back up, replace the folders, run the data hook; restore the backup if it fails. | ≠ 0 **only if every item failed** (`update_failed`); partial failures are in `data.failed` with exit 0 |
| `gp247:ext-update --local` | `--type`, `--key=` (one) or `--all`, `--dry-run` | If `capabilities.ext_update_local`. **No download.** For installed extensions whose `gp247.json` version is newer than the version core recorded, run `AppConfig::update($recordedVersion)` and record the new version on success. `data`: `applied`, `pending` (dry run), `skipped`, `failed`. | ≠ 0 if **any** hook failed (`local_update_failed`) — the site runs new files on old data |
| `gp247:ext-check-update` | `--type`, `--force` | Report available library updates. Cached unless `--force`. | 0 |
| `gp247:ext-search` | `--type`, `--keyword=`, `--free`, `--page=` | Browse the library catalog. | 0 — an unreachable library gives an empty list plus a `warnings` entry; check it |
| `gp247:ext-register-license` | none | Register the domain in `APP_URL` for the site's free **API License** and write `GP247_API_LICENSE` to `.env` (same as the admin "Click here"). | ≠ 0 on `register_failed` / `env_write_failed` |
| `gp247:ext-license` | `--type`, `--key=` (one), `--license=`, `--delete` | Set / show / remove the license of a paid extension. Stored in `admin_config`, never `.env`. Secret. | 0; ≠ 0 only without `--key` |
| `gp247:ext-publish` | `--type`, `--key=*`, `--all` | Copy an **installed** extension's `public/` to `public/GP247/<Plugins\|Templates>/<Key>/`, overwriting. `--all` = every installed extension that has a `public/`. | ≠ 0 on `publish_failed` |

Related, not extension-scoped: `gp247:update` (platform update; also runs `ext-update --local --all` for
plugins then templates — a failed hook is a **warning** with exit 0, details in `data.extension_data`),
`gp247:doctor` (checks include `extension_assets` and `extension_data_pending`), `gp247:info`,
`gp247:cache-rebuild`.

## Install source decision (`ext-install`)

Pick one source per item:

- `--file=<path.zip>` — offline `.zip`. No library call.
- `--dir=<parent>` — an already-extracted source: `<parent>/<Key>/gp247.json` must exist. No library call.
- Both are **refused when the key is already on disk** — use `--key` to install that folder in place.
- `--key=<K>` — resolved in this order:
  1. **Already installed** (has an `admin_config` row) → **refused** before anything is downloaded. To
     refresh use `ext-update`; to reinstall, `ext-uninstall --only-data` first.
  2. **Files on disk but not installed** (a bundled plugin like `News`, or one you copied/cloned) →
     installed **in place**, like the admin "Install" button: its `public/` is copied to
     `public/GP247/...`, then its `install()` runs. No library call, no API License.
  3. **Neither** → **fetched from the library**. For a paid item add `--paid --license=<L>`. Requires the
     site's API License.

Every source checks the `gp247.json` prerequisites — `requireCore` (the site's core must fall in one of
its ranges), `requireComposerPackages`, `requireGp247Extensions` — and never installs them. Install
dependencies first (a paid edition after its free edition, in its own command). An installed plugin is
enabled straight away; a template still has to be activated for a store in admin → **System management →
Website information** (the **Template** field, with a confirmation, because switching runs the outgoing
template's `removeStore()`). There is **no** CLI command for that step: `ext-enable --type=template` only
enables the config row, and `gp247:template-setup` only applies the default template to the root store.

## Uninstall matrix (`ext-uninstall`)

| State of the extension | Plain (no flag) | `--only-data` | `--purge` |
| --- | --- | --- | --- |
| **Installed** (has `admin_config` row) | Runs its `uninstall()`, removes its config, **deletes** `app/…/<Key>` and `public/…/<Key>` | Runs its `uninstall()`, removes its config, **keeps** the folders | Same as plain (deletes the folders) |
| **On disk but not installed** | **Refused** (nothing in DB to remove) | **Refused** | Deletes the folders only |

- The extension's own `uninstall()` decides what happens to its data tables — even with `--only-data`.
- `--only-data` and `--purge` together → refused (`conflicting_options`).
- Guards apply exactly as in admin: an extension listed in `GP247_PROTECTED_PLUGINS` /
  `GP247_PROTECTED_TEMPLATES` cannot be uninstalled (it can still be disabled); a template in use or the
  default template cannot be disabled or uninstalled.
- **A folder under development** (git working copy) is deleted with everything in it — use `--only-data`.

## Library update and its backup (`ext-update` without `--local`)

Before replacing an extension, the update backs up its two folders to
`storage/backups/extensions/<type>/<Key>/` and keeps only the newest few backups per extension. If the
data hook fails, the backup is restored. The backup is not a version-control substitute: never run the
library update on a working copy — `git pull` + `ext-update --local` instead. If it happened by mistake,
stop and tell the user where the backup is before touching anything else.

## Batch semantics (multiple items)

`ext-install`, `ext-enable`, `ext-disable`, `ext-uninstall` and `ext-publish` accept multiple keys —
repeat the flag (`--key=A --key=B`) or comma-separate (`--key=A,B`); `ext-install` likewise takes multiple
`--file`/`--dir`. `ext-update --key` and `ext-license --key` take one key.

- Items are processed **one at a time and independently** — no transaction across extensions.
- Results are reported **per item** in `data.succeeded` / `data.failed` (`ext-update`: `updated` /
  `failed`); `data.failed` maps each item to its message.
- The route/config cache is rebuilt **once at the end** of a batch (a single operation rebuilds it too).
- **Paid remote extensions one key at a time** — `--paid` with more than one `--key` is refused up front
  (`paid_multi_not_allowed`), because one `--license` would be applied to the wrong items.

## Failure codes

`error.code` names the **command-level** outcome; the reason for each item is the message in
`data.failed`. Branch on the code, show the message.

| `error.code` | Meaning | Next step |
| --- | --- | --- |
| `invalid_type` | `--type` is not `plugin` / `template` | Fix the flag. |
| `missing_key` / `missing_source` | No target: `missing_key` when `--key` is required; `missing_source` for `ext-install` without `--file`/`--dir`/`--key` and `ext-publish` without `--key`/`--all` | Add the target. |
| `conflicting_options` | `--only-data` with `--purge` | Pick one. |
| `paid_multi_not_allowed` | `--paid` with several keys | One paid key per command, each with its own `--license`. |
| `not_installed` | `ext-update --local --key` on a key that is not installed / not on disk | Install it first. |
| `install_failed` / `enable_failed` / `disable_failed` / `uninstall_failed` / `publish_failed` | At least one item failed | Read `data.failed`; see the message table below. |
| `update_failed` | Every library update failed | Read `data.failed`. |
| `local_update_failed` | At least one data hook failed; the update stays pending | Fix the cause the message names, re-run `ext-update --local --key=<K>`. |
| `register_failed` / `env_write_failed` | API License registration failed / `.env` not writable | For `env_write_failed`, paste the printed `GP247_API_LICENSE=…` into `.env` yourself; keep it secret. |

Common **per-item messages** (wording varies by version and language):

| Message says | Meaning | Next step |
| --- | --- | --- |
| already exists | The key is installed, or already on disk for `--file`/`--dir` | `ext-update`, `ext-install --key`, or `ext-uninstall --only-data` then install. |
| not installed | enable/disable/uninstall on a key with no `admin_config` row | Install first; for on-disk leftovers `ext-uninstall --purge`. |
| library / marketplace error (+ register-license hint) | No API License, or one bound to another domain | Set the real `APP_URL`, run `gp247:ext-register-license`, retry. |
| is paid — pass `--paid --license` | Paid item | Retry that key alone with `--paid --license=<L>`. |
| not found | The key is not in the library for this type | Check the key (= `configKey`, case-sensitive) and `--type`; or install from `--file`/`--dir`. |
| not compatible | `requireCore` / `requireComposerPackages` / `requireGp247Extensions` not met | Compare `requireCore` with the probe's `core`; `composer require` the package or install the extension first. |
| protected / in use / default template | Guard blocked the operation | Change the active/default template first, or the item is intentionally protected. |

## Shared engine

The CLI and the admin UI run the **same** engine, so anything refused in admin is refused from the CLI,
and vice versa.
