# `gp247:ext-*` command matrix — full options, decisions & failure codes

Read this at Workflow step 3 when you need a flag or a failure code you don't have memorized.
It mirrors the `gp247:ext-*` section of `gp247-docs/system/command-line-reference.md` — that doc is
the source of truth; keep this file in sync with it, do not invent options.

All commands take `--type=plugin|template` (default `plugin`) and support `--json`. The `--json`
envelope is `{"ok": bool, "command": str, "data": {...}, "warnings": [...], "error": {"code","message"}}`.
Always check the **process exit code** too: `0` = success, non-zero = at least one item failed.

## Command reference

| Command | Key options | What it does |
| --- | --- | --- |
| `gp247:ext-list` | `--type` | List local extensions with installed / active / version and whether an update is available. **Cache-only, no API call** — safe to run anytime. |
| `gp247:ext-install` | `--type`, `--file=<zip>`, `--dir=<folder>`, `--key=<key>`, `--paid`, `--license=` | Install from an offline `.zip` (`--file`), an already-extracted folder (`--dir`), or by key (`--key`). See the source decision below. |
| `gp247:ext-enable` | `--type`, `--key` | Enable an **installed** extension. Refused with a clear error if not installed. |
| `gp247:ext-disable` | `--type`, `--key` | Disable an installed extension. Refused if not installed, or for a template still in use. |
| `gp247:ext-uninstall` | `--type`, `--key`, `--only-data`, `--purge` | Uninstall. See the uninstall matrix below. `--only-data` and `--purge` cannot be combined. |
| `gp247:ext-update` | `--type`, `--key`, `--all` | Apply marketplace updates for one extension (`--key`) or every one with an update (`--all`). Backup + rollback per item. |
| `gp247:ext-check-update` | `--type`, `--force` | Report available updates. Cached unless `--force` re-queries the marketplace. Read-only. |
| `gp247:ext-search` | `--type`, `--keyword=`, `--free`, `--page=` | Browse / search the marketplace catalog. |
| `gp247:ext-register-license` | (none) | (core 2.1.1+) Register the domain in `APP_URL` for the site's free **API License** with the GP247 library and write it to `GP247_API_LICENSE` in `.env` (same as the admin "Click here"). Needed before any library call (`ext-install --key` download, `ext-update`, `ext-search`). `.env` not writable → exits non-zero (`env_write_failed`) and prints the key to paste. |
| `gp247:ext-license` | `--type`, `--key`, `--license=`, `--delete` | Set / show / remove the per-plugin license of a paid extension. Stored in `admin_config`, **never** in `.env`. Treat the license value as a secret. |

## Install source decision (`ext-install`)

Pick exactly one source per key:

- `--file=<path.zip>` — install from an **offline** `.zip`. No marketplace call. Use for a package
  someone handed you.
- `--dir=<folder>` — install from an **already-extracted** source folder. No marketplace call.
- `--key=<K>` — resolve by key, in this order:
  1. **Already installed** (has an `admin_config` row) → **refused** with the "already exists" error.
     Remote is checked *before* downloading, so no bandwidth is wasted. To refresh use `ext-update`;
     to reinstall, `ext-uninstall` first.
  2. **Files on disk but not installed** (e.g. a bundled plugin like `News`) → installed **locally**,
     exactly like the admin "Install" button. No marketplace call.
  3. **Neither** → **fetched from the marketplace**. For a paid item add `--paid --license=<L>`.
     Requires the site's API License (`gp247:ext-register-license`, once, with the real `APP_URL`).

Whatever the source, `ext-install` only **checks** the `gp247.json` prerequisites — it never runs
`composer require` for `requireComposerPackages` and never installs `requireGp247Extensions`. Install
those first (a Pro edition after its Free edition, in its own command). An installed plugin is enabled
straight away; a template still has to be activated.

## Uninstall matrix (`ext-uninstall`)

| State of the extension | Plain (no flag) | `--only-data` | `--purge` |
| --- | --- | --- | --- |
| **Installed** (has `admin_config` row) | Removes DB config **and** deletes files | Removes DB config, **keeps** files | (not the intended combo — `--purge` targets on-disk-only leftovers) |
| **On disk but not installed** | **Refused** (nothing in DB to remove) | **Refused** | Deletes the **files only** |

- `--only-data` and `--purge` are **mutually exclusive** — passing both is refused.
- Guards apply exactly as in admin: `extension_protected` items, and a template that is **in use** or
  is the **default** template, are refused.

## Batch semantics (multiple items)

`ext-install`, `ext-enable`, `ext-disable`, `ext-uninstall` accept multiple keys — repeat the flag
(`--key=A --key=B`) or comma-separate (`--key=A,B`); `ext-install` likewise takes multiple
`--file`/`--dir`. Rules:

- Items are processed **one at a time and independently** — there is **no** atomic transaction across
  different extensions (each is its own files + migrations + config unit).
- Results are reported **per item**; a failing item is listed under `failed` while the others proceed.
- The route/config cache is rebuilt **once at the end** of the batch.
- The command exits **non-zero if any item failed**.
- **Paid remote extensions must be installed one key at a time** — `--paid` with more than one `--key`
  is **refused up front** (`error.code: paid_multi_not_allowed`), because a single `--license` would be
  applied to the wrong plugins.

## Failure codes (`error.code`) → fix

| `error.code` | Meaning | Fix |
| --- | --- | --- |
| already exists | `ext-install` on an already-installed key | Use `ext-update` to refresh, or `ext-uninstall` then `ext-install` to reinstall. |
| not installed | `ext-enable`/`ext-disable`/`ext-uninstall` on a key with no `admin_config` row | Install it first; for on-disk-only leftovers use `ext-uninstall --purge`. |
| `Marketplace error: …` (+ register-license hint) | Library call refused: `api_license_required` / `domain_not_authorized` (no API License, or one bound to another domain) | Set the real `APP_URL`, run `gp247:ext-register-license`, retry. |
| "is paid — pass --paid --license=..." | The key exists in the library but is a paid item | Retry that key alone with `--paid --license=<L>`. |
| "not found in the marketplace" | The key is not in the library listing for this type | Check the key (= `configKey`, case-sensitive) and `--type`; or install from `--file`/`--dir`. |
| `env_write_failed` | `ext-register-license` could not write `.env` | Paste the printed `GP247_API_LICENSE=…` line into `.env` yourself; keep it secret. |
| compatibility error | `requireCore` / `requireComposerPackages` / `requireGp247Extensions` not met | `composer require` the package or install the required extension first, then retry. |
| `paid_multi_not_allowed` | `--paid` with more than one `--key` | Install paid items one key at a time, each with its own `--license`. |
| protected / in-use / default template | Guard blocked the operation | Cannot proceed from CLI or admin; change the active/default template first, or the item is intentionally protected. |
| (mutually exclusive flags) | `--only-data` and `--purge` together | Pick one: keep files (`--only-data`) or delete files-only (`--purge`). |

> Exact message strings may vary by version; branch on `error.code` (stable) rather than the human
> `message`. When a code here is not present in the running build, fall back to the exit code and the
> `message` text, and consult `gp247-docs/system/command-line-reference.md`.

## Shared engine

The CLI and the admin UI run the **same** underlying engine (`ExtensionInstaller` / `LibraryClient`),
so behavior is identical regardless of which surface you use. Anything refused in admin is refused from
the CLI, and vice versa.
