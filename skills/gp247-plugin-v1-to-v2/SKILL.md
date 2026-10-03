---
name: gp247-plugin-v1-to-v2
description: Upgrades an existing GP247 plugin written for gp247/core 1.x (AdminLTE + jQuery) to the v2 plugin format (TailAdmin + Livewire) so it installs and runs on whatever newer gp247/core the site has — it probes the installation for the actual core version and features instead of assuming one — editing the plugin's gp247.json, admin view, route, and AppConfig files in place, making its settings and data update-safe, and optionally adding the Livewire, SEO sitemap, LayoutBlock page-type, storefront block, and checkout total-method scaffolding. Always use this skill when the user asks to upgrade, convert, migrate, or port a GP247 plugin to v2 / core 2.x / core 3.x / TailAdmin, or reports that an old plugin breaks or is refused on a newer core (e.g. "View [gp247-core::layout] not found", "not compatible"). Trigger on Vietnamese, Japanese, or English phrasing of this intent (e.g. "upgrade plugin to v2", "convert plugin gp247 lên core mới", "migrate plugin v1 to v2") — requests often arrive in Vietnamese or Japanese, so do not wait for an exact English keyword match. Do not use this skill to scaffold a brand-new plugin from scratch (use gp247-plugin-create) or to upgrade gp247/core, front, or shop themselves.
---

# gp247-plugin-v1-to-v2 — Upgrade a GP247 plugin from Core 1.x to the v2 plugin format

## Purpose

gp247/core 2.0 replaced the entire admin UI layer: 1.x used AdminLTE (Bootstrap + jQuery + pjax); from 2.0
on the admin shell is TailAdmin (Tailwind + Alpine + Livewire). The old `gp247-core::layout` was removed
and jQuery is no longer loaded, so a 1.x plugin **breaks as-is**. Core also refuses to install a plugin
whose `requireCore` does not cover the running version.

This skill upgrades an existing plugin folder: it rewrites the UI layer and the manifest, makes the
plugin's settings and data survive updates, and leaves the business logic alone. It replaces the manual,
error-prone edits described in `gp247-docs/extension/convert-plugin-v1-to-v2.md`.

"1.x" (the source) is a fixed historical format. The **target** is whatever core the site runs — the
skill never writes a target version from memory: step 0 reads it from the site.

## Output language

**English throughout** — code edits, progress updates, and the final summary are all in English, per
the GP247 English-only rule. The user may write in Vietnamese, Japanese, or English (see the trigger
note above), but you respond in English. The deliverable is modified plugin source files, not a
natural-language document — never translate code identifiers, blade directives, or
`gp247_language_render` keys.

## When NOT to use

- Creating a brand-new plugin from nothing — use `gp247-plugin-create` (it scaffolds the full current
  structure with `gp247:make-plugin`).
- Upgrading gp247/core, gp247/front, or gp247/shop themselves — this skill only touches a **plugin**
  folder under `app/GP247/Plugins/<Extension_Key>/` (or an equivalent standalone plugin package).
- A plugin already in the v2 format: admin view on `gp247-admin::layouts.admin`, dependency keys already
  `requireComposerPackages` / `requireGp247Extensions`, and `requireCore` already covering the probe's
  `core` (see step 2). Report that no conversion is needed; if only `requireCore` is stale, fix just that
  and say so.

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

Do the steps in order. Steps 0–4, 8 and 13 are the **required minimum**; the rest apply only when the
plugin has what they handle. Decide which optional steps apply *before* editing, then tell the user.

0. **Probe the installation.** From the website root, run the bundled read-only probe and keep its JSON:

   ```bash
   php <this-skill-dir>/scripts/gp247-probe.php
   ```

   Use `core` / `require_core` for step 2, `packages.<front|shop>.db` to know whether storefront/checkout
   steps can be tested, and `capabilities` to decide which features you may use. `bootstrapped: false`
   with `core` still set → continue, treating capabilities as unknown; `core: null` → stop, not a GP247
   site root. Do not take the version from composer metadata — it can disagree with the version the
   compatibility check uses.

1. **Safety copy — ask first.** If the plugin folder is under Git, propose a working branch
   (`git checkout -b upgrade-to-v2`) and **wait for the user's OK** before creating it; otherwise ask the
   user to back up the folder. This is the rollback path — do not skip it, and do not create branches on
   your own.

2. **`gp247.json` (required).**
   - **`requireCore` = the probe's `require_core`** (the running `major.minor`). Each entry is a range —
     `"X.Y"` means `>= X.Y.0` and `< (X+1).0.0` — so one entry per supported major. A leftover 1.x value
     (e.g. `["1.2"]`) or any value from another major makes core refuse the install as "not compatible".
   - Rename the dependency keys: `requirePackages` → `requireComposerPackages`, `requireExtensions` →
     `requireGp247Extensions` (keep their values). Core still reads the old keys but logs a deprecation.
   - Add `"requireUpdateFrom": "1.0"`. Leave `version` as is for now (bump it when you release the
     converted plugin — every release must be greater than the installed one).

3. **Admin view layout (required — this is what breaks the plugin).** In the admin blade view, change the
   master layout on the first `@extends(...)` line:

   ```blade
   {{-- before --}}  @extends('gp247-core::layout')
   {{-- after  --}}  @extends('gp247-admin::layouts.admin')
   ```

   Keep the `@section('main') ... @endsection` structure; do not restructure content yet.

4. **`AppConfig.php` messages (required).** In `enable()` **and** `disable()`, replace hardcoded strings
   such as `'Error disable'` with
   `gp247_language_render('admin.extension.action_error', ['action' => 'Enable'|'Disable'])`, and make
   sure the error result is returned — a common pattern in scaffolded `enable()` methods assigns the
   error and then overwrites it with the success result on the next line.

5. **Remove jQuery / AdminLTE widgets (only if present).** The admin shell does not load jQuery, so scan
   the plugin's views and assets:
   - `$(...)`, `$.ajax`, `$.pjax`, any `x-pjax` header/script → Livewire/Alpine (step 6).
   - `select2`, `daterangepicker`, `datetimepicker`, Bootstrap modal → the matching `<x-gp247::*>`
     component, or **flatpickr** (bundled with the admin shell) for date fields.
   - Hardcoded display text → `gp247_language_render('...')`.

   If the plugin only shows static data with no jQuery, it already works after step 3 — skip steps 5–6.

6. **(Recommended for dynamic screens) Livewire admin screen + route.** Create
   `Livewire/AdminLivewire.php` and `Views/livewire.blade.php`, register the Livewire namespace in
   `Provider.php`, and add the `class_exists`-guarded route — templates in `references/file-templates.md`.
   Add it beside the legacy controller route first; once the Livewire screen covers everything, point the
   `/` route (`admin_<ExtensionUrlKey>.index`) at it and remove the legacy controller.

7. **Settings that survive updates (required when the plugin has site-owner settings).** A 1-click update
   overwrites every plugin file. If the 1.x plugin keeps site-owner choices in `config.php`, or edits that
   file from admin, they are wiped on every update. Move every editable value into `admin_config` — one
   row per setting keyed `<Extension_Key>_<setting>` (seed in `install()`, top up in `update()`), or one
   JSON row for structured settings; `config.php` keeps defaults only. A **credential** (API key, token,
   webhook secret) must be a `password` field with `security = 1` so core encrypts it at rest (if
   `capabilities.secret_cast`). Templates in `references/file-templates.md` §"Settings".

8. **Data hook and lifecycle (required).** Make the lifecycle re-runnable:
   - `uninstall()` removes what `install()` created (config rows, menu, tables the plugin owns); data the
     store keeps for its own records (orders, payments) stays.
   - Add `update(?string $fromVersion = null)`: it adds whatever a later version introduced (setting
     rows, columns, menu) by **checking the current state**, never skips on `null`, and is harmless to run
     twice. Core runs it after a library update and — if `capabilities.extension_data_updater` — after
     files changed by `git pull` / composer / copy, once with `null` on a site that has no recorded
     version. A converted plugin lands on sites that ran the 1.x version, so this call is expected.
   - If the plugin creates tables with **Laravel migration files**, read the ledger caveat in
     `references/file-templates.md` §"Data hook" — a reinstall can otherwise leave a table missing.

9. **(Optional) SEO sitemap.** Only when the plugin has a public page for `sitemap.xml`: create `Seo.php`
   and add the `class_exists`-guarded registration block to `Provider.php`. Each returned entry needs
   `loc` and should carry `alias`.

10. **(Optional) LayoutBlock page-type.** Only when the plugin has its **own public storefront page**
    and admins should be able to attach LayoutBlock blocks to it. Confirm the controller passes
    `'layout_page' => '<token>'` to `view()`, register the token into
    `config('gp247-config.front.layout_page')` from `Provider.php` (storing the **i18n key**, not a
    rendered string), and add the `layout_block_page` line to both lang files. The `News` plugin is the
    reference.

11. **(Optional) Storefront content without template files.** Some 1.x plugins showed storefront content
    by copying a view into a template folder (`app/GP247/Templates/<Template>/...`) or asking site owners
    to edit their template. That ties the plugin to one template and is left behind on removal. Replace it
    with a **layout block** registered in `gp247-config.front.layout_block_views` (if
    `capabilities.front_layout_block_views`) or a renderer on a shop page's **plugin hook** in
    `gp247-config.front.plugin_hooks` (if `capabilities.front_plugin_hooks`). Snippets in
    `references/file-templates.md`.

12. **(Optional) Total-method plugin (coupon/point) at checkout.** Only when the plugin is a total-method
    (`configCode: "Promotion"`; legacy `"Total"` still accepted) and `capabilities.shop_checkout_total_method`.
    The v1 jQuery `render`/`script` include is gone; make `AppConfig` implement
    `GP247\Shop\Front\Contracts\CheckoutTotalMethod` — `checkoutApply(array $payload): array`,
    `checkoutRemove(): void`, `checkoutView(): ?string` — and add the fragment view, using only CSS classes
    the storefront's compiled stylesheet already contains (a new Tailwind class has no style). The
    `ShopDiscount` plugin is the reference. Templates in `references/file-templates.md`.

13. **Verify.** Run `php artisan optimize:clear`, then — with the user's OK, because these write the DB —
    exercise the lifecycle in place:

    ```bash
    php artisan gp247:ext-install   --type=plugin --key=<Extension_Key>     # or ext-update --local if already installed
    php artisan gp247:ext-disable   --type=plugin --key=<Extension_Key>
    php artisan gp247:ext-enable    --type=plugin --key=<Extension_Key>
    php artisan gp247:ext-uninstall --type=plugin --key=<Extension_Key> --only-data   # KEEP the source files
    php artisan gp247:ext-install   --type=plugin --key=<Extension_Key>
    ```

    Only run commands the probe lists. **Always `--only-data`** — a plain uninstall deletes the plugin
    folder. On a site that already had the 1.x plugin installed, run
    `php artisan gp247:ext-update --type=plugin --key=<Extension_Key> --local --dry-run` (if
    `capabilities.ext_update_local`) to see the data hook would apply, then without `--dry-run`. If you
    changed the plugin's `public/` and `capabilities.ext_publish`, run
    `php artisan gp247:ext-publish --type=plugin --key=<Extension_Key>`. Open the admin screen (and the
    `/livewire` path if added) in light and dark mode. If the user hits an error, consult the
    troubleshooting table in `references/file-templates.md`.

## Output format

Do not print a document. Apply the edits, then give the user a short English summary in this shape:

```
Upgraded plugin <name> to the v2 format on gp247/core <probe.core>:
- [x] gp247.json → requireCore <probe.require_core>, requireUpdateFrom "1.0", keys renamed to requireComposerPackages/requireGp247Extensions
- [x] Views/Admin.blade.php → layout gp247-admin::layouts.admin
- [x] AppConfig.php → enable()/disable() use gp247_language_render and return the error
- [ ] Livewire: <added / skipped because the plugin only shows static data>
- [ ] Settings: <moved to admin_config (secrets encrypted) / skipped — no site-owner settings>
- [x] Lifecycle: uninstall mirrors install; update() idempotent and null-safe
- [ ] Seo.php: <added / skipped because the plugin has no public page>
- [ ] LayoutBlock page-type: <added / skipped because the plugin has no public storefront page>
- [ ] Storefront content: <layout block / plugin hook / skipped — none>
- [ ] Total-method checkout: <added / skipped because the plugin is not a total-method>
- [ ] Lifecycle tested: <install → disable → enable → uninstall --only-data → reinstall OK / skipped — user will test>
Next step: run `php artisan optimize:clear`, then open the admin screen to verify.
```

Mark each line `[x]` done or `[ ]` skipped, and state *why* an optional step was skipped.

## Examples

The user may phrase the request in Vietnamese, Japanese, or English; you always respond in English.

**Example 1 — static admin-only plugin**
Input: "Upgrade the Blog plugin at app/GP247/Plugins/Blog to v2. It only shows a static list." → Probe;
ask about a branch; edit `gp247.json` (`requireCore` from the probe), `Views/Admin.blade.php`,
`AppConfig.php` (`enable`/`disable`); confirm `uninstall()` mirrors `install()` and add a null-safe
`update()`; skip steps 5–6 (no jQuery), 7 (no settings), 9–12. Summary marks them skipped with reasons.

**Example 2 — plugin with jQuery datepicker, settings in config.php, public page**
Input: "Convert the Booking plugin; it has a datepicker, the admin edits config.php, and there is a public
booking page." → All required steps; flatpickr instead of the jQuery datepicker (step 5); Livewire screen
(step 6); move the settings from `config.php` into `admin_config` rows, the payment API key as an
encrypted `password` field (step 7); `update()` seeds the new rows on sites that ran 1.x (step 8);
`Seo.php` (step 9); page-type registration (step 10).

**Example 3 — plugin refused on the new core**
Input: "Plugin Banner cài lên báo not compatible." → Probe says `core` 3.1; `gp247.json` has
`"requireCore": ["2.1"]` — a 2.x range. Its layout and keys are already v2, so only set `requireCore` to
the probe's `require_core`, explain the range rule, and check that nothing else needs the full conversion.

## Common mistakes

| Mistake | Why it hurts / how to avoid |
| --- | --- |
| Writing a `requireCore` value from memory or an example | Each entry is a range ending before the next major; a value from another major is refused as "not compatible". Use the probe's `require_core`. |
| Skipping step 3 | Plugin throws `View [gp247-core::layout] not found`; that layout no longer exists. |
| Creating a git branch without asking | Branch operations need the user's explicit OK. Propose it and wait. |
| Fixing only `disable()` | `enable()` has the same hardcoded message and often loses its error result. Fix both. |
| Leaving site-owner settings in `config.php` | The next 1-click update wipes them. Move them to `admin_config` (step 7). |
| A credential stored as a plain setting | Encrypt it at rest: `password` field + `security = 1` (step 7). |
| No `update()`, or one that returns early on `null` | Sites that ran 1.x never get the rows/columns the conversion added. Guard on the current state (step 8). |
| Deleting the legacy controller route before the Livewire screen covers everything | The admin screen disappears. Add Livewire beside it first (step 6). |
| Adding the Livewire route without the `class_exists` guard | Errors when the Livewire file is absent. |
| Testing uninstall with a plain `ext-uninstall` | It deletes the plugin folder. Use `--only-data`. |
| Forgetting `php artisan optimize:clear` | Admin still shows the old cached view/route — the #1 support issue. |
| Translating code identifiers or `gp247_language_render` keys | Breaks the plugin — keep all identifiers and lang keys verbatim. |
| Registering a page-type whose token ≠ the controller's `$layout_page` | The block is selected in admin but never renders (step 10). |
| Storing a pre-translated string in the page-type registry | The admin dropdown won't follow the viewer's locale — store the language key. |
| Keeping storefront content as files copied into a template folder | Works on one template only and is orphaned on removal. Use a layout block or plugin hook (step 11). |
| Reusing the v1 jQuery `render`/`script` at checkout for a total-method | The checkout is Livewire with no jQuery; implement `CheckoutTotalMethod` (step 12). |
| Using a brand-new Tailwind class in a storefront fragment | The storefront CSS is precompiled; unknown classes have no style. Reuse classes the template already ships. |
| Editing gp247/core, front, or shop, or every plugin at once | Out of scope — confirm the single plugin path first. |
| Raising `requireUpdateFrom` above `"1.0"` without reason | Blocks 1-click updates; keep `"1.0"` unless a release cannot migrate from older versions. |

## Bundled resources

- `scripts/gp247-probe.php` — step 0. Read-only; prints one JSON object (core version, `require_core`,
  usable packages, `gp247:*` commands, capabilities). Identical copies ship with every gp247-* skill.
- `references/file-templates.md` — read at steps 2 and 6–12, or when verifying: before/after
  `gp247.json`, the Livewire screen and route, settings in `admin_config`, the data hook, `Seo.php` and
  the sitemap block, the page-type block, storefront registries, the total-method contract, and the
  troubleshooting Q&A.

---

## Skill info

| Field | Value |
| --- | --- |
| Lần cuối cập nhật / Last updated | `2026-10-03` |
| Skill repo | https://github.com/gp247net/gp247-skills |
| GP247 core repo | https://github.com/gp247net/core |
| source | https://github.com/gp247net/gp247-docs/blob/main/extension/convert-plugin-v1-to-v2.md |
