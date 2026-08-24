---
name: gp247-extension-lifecycle
description: Installs, enables, disables, upgrades, and uninstalls existing GP247 / S-Cart extensions — both plugins and templates — from the command line using the standardized `gp247:ext-*` command family, and reports each result. Always use this skill when the user wants to manage the lifecycle of an already-built extension: install a plugin from a `.zip`, an extracted folder, or a marketplace key; enable or disable it; update one or all to a new release; uninstall or purge it; set a paid license; or list / check what is installed and what has updates — for example "cài plugin News", "gỡ plugin X", "nâng cấp tất cả extension", "bật/tắt template", "cập nhật license plugin". Trigger on Vietnamese, Japanese, or English phrasing of this intent — the team usually writes in Vietnamese, so do not wait for an exact English keyword match. Do not use this skill to CREATE or scaffold a NEW plugin (use gp247-plugin-create) or a new template (use gp247-template-create), to convert a 1.x plugin to v2 (use gp247-plugin-v1-to-v2), or to update the GP247 platform packages (core/front/shop) themselves after `composer update` — that is `gp247:update`, documented in gp247-docs `system/update-gp247`.
---

# gp247-extension-lifecycle — Install, upgrade & remove GP247 extensions from the CLI

## Purpose

Drive the full lifecycle of an **already-built** GP247 extension (a plugin or a template) using the
standardized `gp247:ext-*` command family added in core 2.1 — install, enable, disable, update,
uninstall/purge, license, and inspect. The CLI runs the **same** underlying engine
(`ExtensionInstaller` / `LibraryClient`) as the admin UI, so behavior is identical to clicking the
admin buttons, but scriptable and usable on servers with no admin access.

This skill replaces guessing at raw artisan flags: it picks the correct command, checks the current
state first, guards the destructive operations, and reports a clear per-item result. It is the
operational counterpart to the reference doc `gp247-docs/system/command-line-reference.md`.

## Output language

**English throughout** — the commands you run, the questions you ask, progress notes, and the final
status report are all in English, per the GP247 English-only rule. The user may write in Vietnamese,
Japanese, or English (see the trigger note in the description); you respond in English. The deliverable
is executed lifecycle operations plus a status summary, not a natural-language document for end users.
Never translate command names, option flags, extension keys, or JSON envelope fields.

## When NOT to use

- **Creating / scaffolding a NEW plugin** — use `gp247-plugin-create` (it runs `gp247:make-plugin` and
  writes source files). This skill only manages extensions that already exist as a `.zip`, an on-disk
  folder, or a marketplace item.
- **Creating a NEW storefront template** — use `gp247-template-create`.
- **Converting a 1.x plugin to v2** — use `gp247-plugin-v1-to-v2`.
- **Updating the GP247 platform itself** (core/front/shop) after `composer update` — that is
  `gp247:update`, not an extension operation; see `gp247-docs/system/update-gp247.md`.
- **Only explaining** how the lifecycle works with no intent to run it — answer directly or point at
  `gp247-docs/system/command-line-reference.md` (the `gp247:ext-*` section).

## Input

What operation the user wants, on which extension. Determine four things before running anything —
ask only for the ones that are genuinely missing, do not re-ask what the user already stated:

1. **Operation** — install · enable · disable · update · uninstall · license · list/check.
2. **Type** — `plugin` (default) or `template`. Every `gp247:ext-*` command takes `--type=plugin|template`.
3. **Target** — the extension **key** (e.g. `News`), or, for install, a **source**: a `.zip`
   (`--file=`), an extracted **folder** (`--dir=`), or a marketplace **key** (`--key=`).
4. **Paid?** — for a paid marketplace install, you also need `--paid` and a `--license=<key>`. Treat the
   license value as a **secret**: never echo it back, never write it to a doc or commit. It is stored in
   `admin_config`, never in `.env`.

Run every command from the **website root** (where `artisan` lives). If the user did not say `plugin`
vs `template`, and the key is ambiguous, ask; otherwise default to `plugin`.

## Workflow

Do the steps in order. Prefer `--json` on read/inspect commands so you parse the envelope
(`{"ok":..., "command":..., "data":..., "warnings":[], "error":{"code",...}}`) instead of scraping text;
check the **exit code** (0 = success) on every run.

1. **Confirm the plan in one line.** Restate operation + type + target key(s). For a **destructive**
   operation (`ext-uninstall`, `ext-uninstall --purge`, or a paid `ext-install`), state the impact and
   get an explicit "yes" before running — deleting files / spending a paid license is not reversible.

2. **Check current state first** with a read-only command (no marketplace call, safe to run anytime):

   ```bash
   php artisan gp247:ext-list --type=plugin --json
   ```

   This shows, per local extension, whether it is **installed** (has an `admin_config` row — *not*
   merely present on disk), **active**, its version, and whether an update is cached as available. Use
   it to avoid the predictable refusals in **Common mistakes** (installing something already installed,
   enabling something not installed, etc.). For updates, also run
   `php artisan gp247:ext-check-update --type=plugin` (add `--force` to bypass the cache and re-query).

3. **Run the operation.** Pick the command from the matrix below; full options and every failure code
   are in `references/command-matrix.md` — open it when you need a flag you don't have memorized.

   | Operation | Command | Notes |
   | --- | --- | --- |
   | List local | `gp247:ext-list --type=<t>` | Cache-only, no API call. |
   | Search marketplace | `gp247:ext-search --type=<t> --keyword=<kw> [--free] [--page=N]` | Browse the catalog. |
   | Install from zip | `gp247:ext-install --type=<t> --file=<path.zip>` | Offline, no marketplace. |
   | Install from folder | `gp247:ext-install --type=<t> --dir=<folder>` | Already-extracted source. |
   | Install by key | `gp247:ext-install --type=<t> --key=<K>` | Bundled-on-disk → local install; else fetch from marketplace. |
   | Install paid by key | `gp247:ext-install --type=<t> --key=<K> --paid --license=<L>` | **One key at a time** (see rule below). |
   | Enable | `gp247:ext-enable --type=<t> --key=<K>` | Refused if not installed. |
   | Disable | `gp247:ext-disable --type=<t> --key=<K>` | Refused if not installed, or a template still in use. |
   | Update one / all | `gp247:ext-update --type=<t> --key=<K>` / `--all` | Marketplace update, backup + rollback. |
   | Uninstall | `gp247:ext-uninstall --type=<t> --key=<K>` | Removes DB config **and** files. `--only-data` keeps files. |
   | Purge on-disk-only | `gp247:ext-uninstall --type=<t> --key=<K> --purge` | For a not-installed-but-on-disk item; deletes files only. |
   | License | `gp247:ext-license --type=<t> --key=<K> [--license=<L>] [--delete]` | Set / show / remove a paid license. |

4. **Batch, when the user names several items.** `ext-install`, `ext-enable`, `ext-disable`, and
   `ext-uninstall` accept **multiple keys** — repeat the flag (`--key=A --key=B`) or comma-separate
   (`--key=A,B`); `ext-install` likewise takes multiple `--file`/`--dir`. Items run **one at a time and
   independently** (no atomic transaction across different extensions), results are reported per item,
   the route/config cache is rebuilt **once at the end**, and the command exits **non-zero if any item
   failed**. A paid remote install must be **one `--key` at a time** — `--paid` with more than one key is
   refused up front (`error.code: paid_multi_not_allowed`), because one `--license` would be applied to
   the wrong plugins.

5. **Verify and, if needed, rebuild cache.** A batch rebuilds the route/config cache once at the end;
   after a single enable/disable/update, if the admin still shows stale routes/menus, run
   `php artisan gp247:cache-rebuild`. Re-run `gp247:ext-list --type=<t>` to confirm the new
   installed/active/version state matches what the user asked for.

6. **Report** per the Output format. State exactly what ran, what changed, and — for any refusal —
   the `error.code` and the concrete next step (e.g. "already installed → use `ext-update`").

**Lifecycle invariants** (why the refusals happen — details in `references/command-matrix.md`):
- **"Installed" means a DB `admin_config` row exists**, not that files are on disk. `ext-install` on an
  already-installed extension is refused (remote is checked **before** downloading — no wasted
  bandwidth) and never creates a duplicate row; `ext-enable`/`ext-disable`/`ext-uninstall` treat a
  not-installed extension as an error, so a bundled on-disk plugin is never enabled as a no-op or
  deleted by surprise.
- **Install vs update vs reinstall:** to refresh an installed extension use `ext-update`; to reinstall,
  `ext-uninstall` first, then `ext-install`.
- **Guards apply from CLI exactly as in admin:** `extension_protected` items, and a template that is
  in use or is the default, are refused from both surfaces.
- **`--only-data` and `--purge` are mutually exclusive** on `ext-uninstall`.

## Output format

Do not print a document. Run the operations, then give a short English status report in this shape
(one line per item; mark `[x]` done, `[!]` refused/failed, and give the reason + next step on failure):

```
Extension lifecycle — <operation> (<type>):
- [x] <Key>: <what happened> (version <v>, active=<yes/no>)
- [!] <Key>: refused — <error.code>: <one-line reason> → <next step>
State now (gp247:ext-list): <installed/active/version per affected key>
Cache: <rebuilt automatically at end of batch | ran gp247:cache-rebuild | not needed>
```

## Examples

The user may phrase the request in Vietnamese, Japanese, or English; you always respond in English.

**Example 1 — install a bundled plugin by key**
Input: "Cài plugin News." → Operation=install, type=plugin, key=`News`, not paid. Run
`gp247:ext-list --type=plugin --json` to confirm `News` is on disk but not installed, then
`php artisan gp247:ext-install --type=plugin --key=News`. Because its files are already bundled on disk,
this is a **local** install (same as the admin "Install" button), no marketplace call. Report installed
version + active state; suggest `gp247:ext-enable --type=plugin --key=News` if it is not auto-enabled.

**Example 2 — update everything, then remove one plugin**
Input: "Nâng cấp tất cả plugin rồi gỡ hẳn plugin OldBanner." → First
`php artisan gp247:ext-check-update --type=plugin --force` to see what has updates, then
`php artisan gp247:ext-update --type=plugin --all` (backup + rollback per item). For the removal, confirm
the destructive intent, then `php artisan gp247:ext-uninstall --type=plugin --key=OldBanner` (removes DB
config **and** files). If `OldBanner` turns out to be on disk but not installed, plain uninstall is
refused — use `--purge` to delete the leftover files. Report per item; note the cache was rebuilt at the
end of the batch.

## Common mistakes

| Mistake | Why it hurts / how to avoid |
| --- | --- |
| Treating "files on disk" as "installed" | "Installed" = an `admin_config` row exists. Check with `ext-list` first; a bundled on-disk plugin still needs `ext-install`. |
| `ext-install` on an already-installed key | Refused ("already exists"). To refresh use `ext-update`; to reinstall, `ext-uninstall` first. |
| `ext-enable` / `ext-disable` on a not-installed key | Refused. Install it first; enable/disable only act on installed extensions. |
| Plain `ext-uninstall` on an on-disk-but-not-installed item | Refused. Use `--purge` to delete leftover files only (no DB row to remove). |
| Combining `--only-data` and `--purge` | Mutually exclusive — the command refuses. Pick one: keep files (`--only-data`) or delete files-only (`--purge`). |
| `--paid` with several `--key` values | Refused up front (`paid_multi_not_allowed`) — one `--license` would hit the wrong plugin. Install paid items one key at a time. |
| Forgetting `--type=template` for templates | Defaults to `plugin`; the command then can't find the template key. Always pass `--type` for templates. |
| Echoing / committing a paid `--license` | It is a secret (stored in `admin_config`, never `.env`). Never print it back or write it into a doc/commit. |
| Expecting stale admin menus to refresh by themselves after a single op | A batch rebuilds cache once at the end; after a single enable/disable/update run `gp247:cache-rebuild`. |
| Using this skill to run `gp247:update` (platform) | That updates core/front/shop, not an extension. Different command, different skill/doc. |

## Bundled resources

- `references/command-matrix.md` — read at Workflow step 3 for the full per-command option list, the
  install-source decision (`--file` vs `--dir` vs `--key`, bundled-on-disk vs marketplace), the exact
  uninstall matrix (installed vs on-disk-only × `--only-data`/`--purge`), the batch semantics, and the
  standardized `error.code` values with their fix. Load it only when you need a flag or a failure code so
  SKILL.md stays lean.

---

## Skill info

| Field | Value |
| --- | --- |
| Lần cuối cập nhật / Last updated | `2026-08-24` |
| Skill repo | https://github.com/gp247net/gp247-skills |
| GP247 core repo | https://github.com/gp247net/core |
| source | https://github.com/gp247net/gp247-docs/blob/main/system/command-line-reference.md |
