---
name: gp247-plugin-v1-to-v2
description: Upgrades an existing GP247 plugin written for gp247/core 1.x so it runs on gp247/core 2.0, editing the plugin's config, admin view, route, and AppConfig files in place and optionally adding the Livewire/SEO scaffolding. Always use this skill when the user asks to upgrade, convert, migrate, or port a GP247 plugin to v2 / core 2.0 / TailAdmin, or reports that an old plugin breaks on 2.0 (e.g. "View [gp247-core::layout] not found"). Trigger on Vietnamese, Japanese, or English phrasing of this intent — the team usually writes in Vietnamese (e.g. "nâng cấp plugin lên v2", "chuyển plugin gp247 sang core 2.0", "convert plugin v1 sang v2"), so do not wait for an exact English keyword match. Do not use this skill to scaffold a brand-new v2 plugin from scratch (use `php artisan gp247:make-plugin`) or to upgrade gp247/core, front, or shop themselves.
---

# gp247-plugin-v1-to-v2 — Upgrade a GP247 plugin from Core 1.x to Core 2.0

## Purpose

GP247 2.0 replaced the entire admin UI layer: 1.x used AdminLTE (Bootstrap + jQuery + pjax),
2.0 uses TailAdmin (Tailwind + Alpine + Livewire). The old `gp247-core::layout` was removed and
jQuery is no longer loaded, so a 1.x plugin **breaks as-is** on 2.0.

This skill performs that upgrade on an existing plugin folder: it rewrites the UI layer and a few
config files while leaving the plugin's business logic (Models, install/uninstall, data processing)
untouched. It replaces the manual, error-prone edits described in
`gp247-docs/extension/convert-plugin-v1-to-v2.md`.

## Output language

**Code edits: English** — every code comment follows the GP247 English-only rule. **Progress and the
final summary spoken to the user: Vietnamese** (the team works in Vietnamese). The deliverable is
modified plugin source files, not a natural-language document — never translate code identifiers,
blade directives, or `gp247_language_render` keys.

## When NOT to use

- Creating a brand-new v2 plugin from nothing — run `php artisan gp247:make-plugin --name=X --download=0`
  instead; it scaffolds the full v2 structure (Livewire, Seo, new layout) already.
- Upgrading gp247/core, gp247/front, or gp247/shop themselves — this skill only touches a **plugin**
  folder under `app/GP247/Plugins/<Extension_Key>/` (or an equivalent standalone plugin package).
- A plugin already on the v2 template (`requireCore` already `["2.0"]` and layout already
  `gp247-admin::layouts.admin`) — report that no upgrade is needed instead of editing.

## Input

A path to the plugin folder to upgrade. Before editing, confirm these files exist inside it:
`gp247.json`, `AppConfig.php`, `Route.php`, `Provider.php`, and the admin view (usually
`Views/Admin.blade.php`). If the user did not name a plugin path, ask which plugin to upgrade — never
guess and never edit every plugin under `Plugins/` at once.

Read `gp247.json` first to capture two identifiers reused across the steps:
- **`Extension_Key`** — the plugin's `configKey` / PHP namespace segment (e.g. the `Blog` in
  `App\GP247\Plugins\Blog`).
- **`ExtensionUrlKey`** — the route-name prefix already used in `Route.php` (e.g. `admin_blog` in
  `admin_blog.index`).

## Workflow

Do the steps in order. Steps 2, 3, 7 are the **required minimum** for the plugin to run; steps 5–6 are
recommended (only when the plugin has dynamic/jQuery interaction); step 8 is optional (only when the
plugin serves public pages). Decide which optional steps apply *before* editing, then tell the user.

1. **Safety branch.** If the plugin is under Git, create a working branch (`git checkout -b upgrade-to-v2`).
   Otherwise ask the user to back up the folder first. This is the rollback path — do not skip it.

2. **`gp247.json` (required).** Set `requireCore` to `["2.0"]` and add `"requireUpdateFrom": "1.0"`.
   Leave `version`, `requirePackages`, `requireExtensions` unchanged.

   ```json
   "requireCore": ["2.0"],
   "requireUpdateFrom": "1.0",
   ```

3. **Admin view layout (required — this is what breaks the plugin).** In the admin blade view, change
   the master layout on the first `@extends(...)` line:

   ```blade
   {{-- before --}}  @extends('gp247-core::layout')
   {{-- after  --}}  @extends('gp247-admin::layouts.admin')
   ```

   Keep the `@section('main') ... @endsection` structure; do not restructure content yet.

4. **Remove jQuery / AdminLTE widgets (only if present).** GP247 2.0 does not load jQuery, so scan the
   plugin's views and assets and replace anything depending on it:
   - `$(...)`, `$.ajax`, `$.pjax`, any `x-pjax` header/script → Livewire/Alpine (step 5).
   - `select2`, `daterangepicker`, `datetimepicker`, Bootstrap modal → the matching `<x-gp247::*>`
     component, or **flatpickr** (already bundled in the TailAdmin stack) for date fields.
   - Hardcoded display text → `gp247_language_render('...')`.

   If the plugin only shows static data with no jQuery, it already works after step 3 — skip steps 4–6.

5. **(Recommended) Livewire admin screen.** When the plugin has dynamic interaction, create
   `Livewire/AdminLivewire.php` and `Views/livewire.blade.php`. Use the exact templates in
   `references/file-templates.md`, substituting `Extension_Key` with the plugin's `configKey`.

6. **(Recommended) Livewire route.** In `Route.php`, inside the existing admin `Route::group([...],
   function () { ... })`, **add** the Livewire route without removing the legacy controller route.
   Guard it with `class_exists` so the plugin is safe even before the Livewire file exists:

   ```php
   if (class_exists(\App\GP247\Plugins\Extension_Key\Livewire\AdminLivewire::class)) {
       Route::get('/livewire', \App\GP247\Plugins\Extension_Key\Livewire\AdminLivewire::class)
       ->name('admin_ExtensionUrlKey.livewire');
   }
   ```

7. **`AppConfig.php` (required).** In the `disable()` method, replace the hardcoded `'Error disable'`
   string with the multilingual helper. Leave `install`/`uninstall`/`enable`/`getInfo` unchanged.

   ```php
   $return = ['error' => 1, 'msg' => gp247_language_render('admin.extension.action_error', ['action' => 'Disable'])];
   ```

8. **(Optional) SEO sitemap.** Only when the plugin has a public page for `sitemap.xml`: create
   `Seo.php` and add the `class_exists`-guarded registration block to `Provider.php`. Templates are in
   `references/file-templates.md`.

9. **Verify.** Run `php artisan optimize:clear` to reload routes/views/config, then confirm the admin
   screen opens (and the `/livewire` path if you added it), and that enable/disable still works. If the
   user hits an error, consult the troubleshooting table in `references/file-templates.md`.

## Output format

Do not print a document. Apply the edits, then give the user a short Vietnamese summary in this shape:

```
Đã nâng cấp plugin <name> lên v2:
- [x] gp247.json → requireCore ["2.0"], requireUpdateFrom "1.0"
- [x] Views/Admin.blade.php → layout gp247-admin::layouts.admin
- [x] AppConfig.php → disable() dùng gp247_language_render
- [ ] Livewire: <đã thêm / bỏ qua vì plugin chỉ hiển thị tĩnh>
- [ ] Seo.php: <đã thêm / bỏ qua vì plugin không có trang public>
Bước tiếp theo: chạy `php artisan optimize:clear` rồi mở màn admin để kiểm tra.
```

Mark each line `[x]` done or `[ ]` skipped, and state *why* an optional step was skipped.

## Examples

**Example 1 — static admin-only plugin**
Input: "Nâng cấp plugin Blog ở app/GP247/Plugins/Blog lên v2. Nó chỉ hiển thị danh sách tĩnh."
Output: Edit `gp247.json` (step 2), `Views/Admin.blade.php` (step 3), `AppConfig.php` (step 7); skip
steps 4–6 (no jQuery) and step 8 (admin-only); the summary marks Livewire and Seo skipped with reasons
and reminds the user to run `php artisan optimize:clear`.

**Example 2 — plugin with jQuery datepicker + public page**
Input: "Convert plugin Booking sang core 2.0, nó có datepicker và trang đặt lịch public."
Output: All required steps; replace the jQuery datepicker with flatpickr (step 4); add the Livewire
screen + route (steps 5–6); add `Seo.php` + the `Provider.php` block (step 8); summary marks all boxes done.

## Common mistakes

| Mistake | Why it hurts / how to avoid |
| --- | --- |
| Skipping step 3 | Plugin throws `View [gp247-core::layout] not found`; that layout was removed in 2.0. |
| Deleting the legacy controller route when adding Livewire | Breaks backward compatibility — keep both routes. |
| Adding the Livewire route without the `class_exists` guard | Errors when the Livewire file is absent. |
| Forgetting `php artisan optimize:clear` | Admin still shows the old cached view/route — the #1 support issue. |
| Translating code identifiers or `gp247_language_render` keys | Breaks the plugin; only the user-facing summary is Vietnamese. |
| Editing gp247/core, front, or shop, or every plugin at once | Out of scope — confirm the single plugin path first. |
| Raising `requireUpdateFrom` above `"1.0"` without reason | Blocks 1-click updates; keep `"1.0"` unless a major release cannot auto-migrate. |
| Rewriting Models / install logic | Unnecessary — 2.0 keeps the 1.x schema and logic layer; only UI + config change. |

## Bundled resources

- `references/file-templates.md` — read at step 5, 6, or 8 for the full `AdminLivewire.php`,
  `livewire.blade.php`, `Seo.php`, and `Provider.php` sitemap-block templates, the before/after
  `gp247.json`, and the verification / troubleshooting Q&A. Load it only when you reach those steps so
  SKILL.md stays lean.

---

## Skill info

| Field | Value |
| --- | --- |
| `last_updated` | `2026-07-28T04:56:41Z` (UTC) |
| Skill repo | https://github.com/gp247net/gp247-skills |
| GP247 core repo | https://github.com/gp247net/core |
| source | https://github.com/gp247net/gp247-docs/blob/master/extension/convert-plugin-v1-to-v2.md |
