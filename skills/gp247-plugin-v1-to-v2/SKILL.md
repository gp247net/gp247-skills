---
name: gp247-plugin-v1-to-v2
description: Upgrades an existing GP247 plugin written for gp247/core 1.x so it runs on gp247/core 2.0, editing the plugin's config, admin view, route, and AppConfig files in place and optionally adding the Livewire, SEO sitemap, and LayoutBlock page-type scaffolding. Always use this skill when the user asks to upgrade, convert, migrate, or port a GP247 plugin to v2 / core 2.0 / TailAdmin, or reports that an old plugin breaks on 2.0 (e.g. "View [gp247-core::layout] not found"). Trigger on Vietnamese, Japanese, or English phrasing of this intent (e.g. "upgrade plugin to v2", "convert plugin gp247 to core 2.0", "migrate plugin v1 to v2") — requests often arrive in Vietnamese or Japanese, so do not wait for an exact English keyword match. Do not use this skill to scaffold a brand-new v2 plugin from scratch (use `php artisan gp247:make-plugin`) or to upgrade gp247/core, front, or shop themselves.
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

**English throughout** — code edits, progress updates, and the final summary are all in English, per
the GP247 English-only rule. The user may write in Vietnamese, Japanese, or English (see the trigger
note above), but you respond in English. The deliverable is modified plugin source files, not a
natural-language document — never translate code identifiers, blade directives, or
`gp247_language_render` keys.

## When NOT to use

- Creating a brand-new v2 plugin from nothing — run `php artisan gp247:make-plugin --name=X --download=0`
  instead; it scaffolds the full v2 structure (Livewire, Seo, new layout) already.
- Upgrading gp247/core, gp247/front, or gp247/shop themselves — this skill only touches a **plugin**
  folder under `app/GP247/Plugins/<Extension_Key>/` (or an equivalent standalone plugin package).
- A plugin already on the v2 template (`requireCore` already `["2.1"]`, dependency keys already
  `requireComposerPackages`/`requireGp247Extensions`, and layout already
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
recommended (only when the plugin has dynamic/jQuery interaction); steps 8 and 8b are optional (step 8
only when the plugin serves public pages for the sitemap; step 8b only when the plugin has its own
public storefront page that admins should be able to attach LayoutBlock blocks to). Decide which
optional steps apply *before* editing, then tell the user.

1. **Safety branch.** If the plugin is under Git, create a working branch (`git checkout -b upgrade-to-v2`).
   Otherwise ask the user to back up the folder first. This is the rollback path — do not skip it.

2. **`gp247.json` (required).** Set `requireCore` to `["2.1"]`, add `"requireUpdateFrom": "1.0"`, and
   rename the dependency keys to the core 2.1 names: `requirePackages` → `requireComposerPackages`,
   `requireExtensions` → `requireGp247Extensions` (keep their values). Leave `version` unchanged.

   ```json
   "requireCore": ["2.1"],
   "requireUpdateFrom": "1.0",
   "requireComposerPackages": [],
   "requireGp247Extensions": [],
   ```

   (Core 2.1 still reads the old keys for backward compatibility, but they are deprecated — emit the new ones.)

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

8b. **(Optional) LayoutBlock page-type.** Only when the plugin has its **own public storefront page**
   (e.g. a list/detail page) and admins should be able to attach LayoutBlock blocks (banner, HTML,
   view…) to it. The admin "Layout block" screen only lists page-types **registered** into
   `config('gp247-config.front.layout_page')`, so the plugin must register its own. Confirm the
   controller passes `'layout_page' => '<token>'` to `view()`, then add the `class_exists`-guarded
   registration block to `Provider.php` (storing the **i18n key**, not a pre-rendered string) and add
   the matching `layout_block_page` line to the plugin's `Lang/en/lang.php` and `Lang/vi/lang.php`. The
   `<token>` must match the `$layout_page` value the controller emits. The `News` plugin
   (`app/GP247/Plugins/News/Provider.php`) is the reference example. Templates are in
   `references/file-templates.md`. (Note: a *template*/theme does **not** register page-types — only a
   plugin with its own page does.)

8c. **(Optional) Total-method plugin (coupon/point) at checkout.** Only when the plugin is a
   total-method (`configCode: "Total"` — coupon, loyalty point…) that must show an input at checkout.
   GP247 2.0 replaced the v1 jQuery `render`/`script` include with the **`CheckoutTotalMethod` contract**
   (ADR-storefront-checkout-total-method-contract). Make `AppConfig` implement
   `GP247\Shop\Front\Contracts\CheckoutTotalMethod` — `checkoutApply(array $payload): array` (validate +
   set `session('totalMethod')[<key>]`, reusing the plugin's existing logic), `checkoutRemove(): void`,
   and `checkoutView(): ?string` (fragment view name) — then add that fragment (e.g.
   `Views/checkout.blade.php`) using `wire:model="totalPayload.<key>.code"` /
   `wire:click="applyTotal('<key>')"` and **only storefront UI tokens the active template already ships**
   (a brand-new Tailwind class won't exist in the pre-built CSS and silently has no style). The checkout
   auto-discovers the plugin (`code='total'` + implements the interface) and renders the fragment; a total
   plugin that does not implement the interface is hidden + logged. The data layer
   (`session('totalMethod')`, `getInfo()`, `addOrder()`) is unchanged. The `ShopDiscount` plugin is the
   reference example. Templates are in `references/file-templates.md`.

9. **Verify.** Run `php artisan optimize:clear` to reload routes/views/config, then confirm the admin
   screen opens (and the `/livewire` path if you added it), and that enable/disable still works. If the
   user hits an error, consult the troubleshooting table in `references/file-templates.md`.

## Output format

Do not print a document. Apply the edits, then give the user a short English summary in this shape:

```
Upgraded plugin <name> to v2:
- [x] gp247.json → requireCore ["2.1"], requireUpdateFrom "1.0", keys renamed to requireComposerPackages/requireGp247Extensions
- [x] Views/Admin.blade.php → layout gp247-admin::layouts.admin
- [x] AppConfig.php → disable() uses gp247_language_render
- [ ] Livewire: <added / skipped because the plugin only shows static data>
- [ ] Seo.php: <added / skipped because the plugin has no public page>
- [ ] LayoutBlock page-type: <added / skipped because the plugin has no public storefront page>
- [ ] Total-method checkout: <added / skipped because the plugin is not a total-method (coupon/point)>
Next step: run `php artisan optimize:clear`, then open the admin screen to verify.
```

Mark each line `[x]` done or `[ ]` skipped, and state *why* an optional step was skipped.

## Examples

The user may phrase the request in Vietnamese, Japanese, or English; you always respond in English.

**Example 1 — static admin-only plugin**
Input: "Upgrade the Blog plugin at app/GP247/Plugins/Blog to v2. It only shows a static list."
Output: Edit `gp247.json` (step 2), `Views/Admin.blade.php` (step 3), `AppConfig.php` (step 7); skip
steps 4–6 (no jQuery), step 8 (admin-only), and step 8b (no public storefront page); the summary marks
Livewire, Seo, and LayoutBlock page-type skipped with reasons and reminds the user to run
`php artisan optimize:clear`.

**Example 2 — plugin with jQuery datepicker + public storefront page**
Input: "Convert the Booking plugin to core 2.0; it has a datepicker and a public booking page."
Output: All required steps; replace the jQuery datepicker with flatpickr (step 4); add the Livewire
screen + route (steps 5–6); add `Seo.php` + the `Provider.php` sitemap block (step 8); register the
page-type so admins can attach LayoutBlock blocks to the booking page (step 8b); summary marks all boxes done.

## Common mistakes

| Mistake | Why it hurts / how to avoid |
| --- | --- |
| Skipping step 3 | Plugin throws `View [gp247-core::layout] not found`; that layout was removed in 2.0. |
| Deleting the legacy controller route when adding Livewire | Breaks backward compatibility — keep both routes. |
| Adding the Livewire route without the `class_exists` guard | Errors when the Livewire file is absent. |
| Forgetting `php artisan optimize:clear` | Admin still shows the old cached view/route — the #1 support issue. |
| Translating code identifiers or `gp247_language_render` keys | Breaks the plugin — keep all identifiers and lang keys verbatim; everything you write stays in English. |
| Registering a page-type whose token ≠ the controller's `$layout_page` | The block is selected in admin but never renders — the token in `Provider.php` must match the value `view()` emits (step 8b). |
| Storing a pre-translated string instead of the i18n key in the page-type registry | The admin dropdown won't follow the viewer's locale — store the `::lang.layout_block_page.<token>` key, not a rendered string. |
| For a total-method plugin, reusing the v1 jQuery `render`/`script` at checkout | 2.0 loads no jQuery and the checkout is Livewire; implement `CheckoutTotalMethod` + a `wire:` fragment instead (step 8c). |
| Using a brand-new Tailwind class in a checkout/storefront fragment | The template's CSS is pre-compiled; unknown classes have no style — reuse tokens the template already ships (step 8c / gp247.md §3b). |
| Editing gp247/core, front, or shop, or every plugin at once | Out of scope — confirm the single plugin path first. |
| Raising `requireUpdateFrom` above `"1.0"` without reason | Blocks 1-click updates; keep `"1.0"` unless a major release cannot auto-migrate. |
| Rewriting Models / install logic | Unnecessary — 2.0 keeps the 1.x schema and logic layer; only UI + config change. |

## Bundled resources

- `references/file-templates.md` — read at step 5, 6, 8, 8b, or 8c for the full `AdminLivewire.php`,
  `livewire.blade.php`, `Seo.php`, the `Provider.php` sitemap block, and the `Provider.php` LayoutBlock
  page-type block, plus the before/after `gp247.json` and the verification / troubleshooting Q&A. Load
  it only when you reach those steps so SKILL.md stays lean.

---

## Skill info

| Field | Value |
| --- | --- |
| Last updated | `2026-07-31` |
| Skill repo | https://github.com/gp247net/gp247-skills |
| GP247 core repo | https://github.com/gp247net/core |
| source | https://github.com/gp247net/gp247-docs/blob/master/extension/convert-plugin-v1-to-v2.md |
