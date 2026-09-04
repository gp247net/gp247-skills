---
name: gp247-plugin-create
description: Scaffolds a brand-new GP247 v2 plugin (Core 2.0 / TailAdmin) and edits the generated files in place — gp247.json, AppConfig.php, config.php, function.php, Models/ExtensionModel.php, Livewire, Route, Lang — to implement the plugin the user describes, built the update-safe way so a 1-click version update never wipes the site owner's settings or data. Always use this skill when the user asks to create, build, scaffold, generate, or start a NEW GP247 / S-Cart plugin (an admin feature package plugged into gp247/core), for example "make a GP247 plugin that…", "create a new plugin", "scaffold a plugin". Trigger on Vietnamese, Japanese, or English phrasing of this intent — the team usually writes in Vietnamese (e.g. "tạo plugin GP247", "viết plugin mới", "làm plugin cho GP247"), so do not wait for an exact English keyword match. Do not use this skill to upgrade/convert an EXISTING 1.x plugin to v2 (use gp247-plugin-v1-to-v2), to build a storefront theme/template (use create-template), or to modify gp247/core, front, or shop themselves.
---

# gp247-plugin-create — Scaffold & build a new GP247 v2 plugin (update-safe)

## Purpose

Create a new GP247 plugin the correct v2 way. GP247 has a **1-click update** mechanism: on update it
**overwrites every file** of the plugin with the new release but **keeps the database** (`admin_config`
and the plugin's own tables). A plugin written the wrong way — for example letting the site owner edit
`config.php` — loses all of their choices on every update. This skill scaffolds the plugin with
`php artisan gp247:make-plugin`, then edits the generated files to implement the user's feature **and**
enforce the five update-safety rules, so the plugin ships correctly the first time.

It replaces the manual, easy-to-get-wrong process described in
`gp247-docs/extension/create-plugin.md`, turning it into applied source edits.

## Output language

**English throughout** — code edits, questions to the user, progress notes, and the final summary are
all in English, per the GP247 English-only rule. The user may write in Vietnamese, Japanese, or English
(see the trigger note above); you respond in English. The deliverable is plugin source files, not a
natural-language document. Never translate code identifiers, blade directives, or the display-text keys
passed to `trans(...)` / `gp247_language_render(...)` (those keys stay verbatim; only the *values* in
`Lang/vi/lang.php` and `Lang/en/lang.php` are localized).

## When NOT to use

- Upgrading or converting an **existing** 1.x plugin to run on 2.0 — use `gp247-plugin-v1-to-v2`; that
  edits an already-built plugin's UI/config layer rather than scaffolding a new one.
- Building a **storefront theme/template** (the shop's look) — use `create-template`; a template is not
  a plugin and does not use `gp247:make-plugin`.
- Editing `gp247/core`, `gp247/front`, or `gp247/shop` themselves — this skill only creates a folder
  under `app/GP247/Plugins/<Extension_Key>/`.
- Only *explaining* how plugins work with no intent to build one — answer directly or point at
  `gp247-docs/extension/create-plugin.md` instead of scaffolding files.

## Input

The plugin's specification. The user supplies it in the prompt, or you ask for the missing pieces
before scaffolding — **never guess the business logic**. You need four answers:

1. **Name** — PascalCase, no spaces/accents (e.g. `MyBanner`, `ProductFeed`). Becomes both the folder
   name and the `configKey`; they must match and must not change after release.
2. **Surface** — admin-only, or also a **public (storefront) page** for visitors. This decides whether
   `Controllers/FrontController.php` and `Seo.php` are kept. (If `gp247/front` is not installed, the
   scaffolder skips `FrontController.php` automatically — that is normal, not an error.) A special
   surface is a **checkout total-method** (coupon/point, `configCode: "Promotion"` — legacy `"Total"` still accepted): besides `getInfo()`, its
   `AppConfig` must implement `GP247\Shop\Front\Contracts\CheckoutTotalMethod` so the Livewire checkout
   shows its input — see the convert guide step 8c (`gp247-docs/extension/convert-plugin-v1-to-v2.md`)
   and the `ShopDiscount` plugin as the reference.
3. **Own data table?** — does it store records of its own (needs a table created on install), or does it
   only hold a handful of settings?
4. **Site-owner-editable settings?** — any value the site owner may change from admin (toggles,
   items-per-page, their own API key…). These decide whether you wire the update-safe config helpers.

If the user described the feature but left one of these ambiguous, ask a short, specific question for
that one point only. Do not re-ask what they already stated.

## Workflow

Do the steps in order. Steps 1–3 and 8 always run. Steps 4–7 depend on the answers from **Input**.

1. **Confirm the spec.** Restate the four Input answers in one line and note which optional files/steps
   apply (public page? own table? editable settings?). This is the plan — get it straight before editing.

2. **Scaffold.** From the website root (where `artisan` lives), run:

   ```bash
   php artisan gp247:make-plugin --name=<Name> --download=0
   ```

   `--download=0` writes the plugin directly to `app/GP247/Plugins/<Name>` (usable immediately);
   `--download=1` instead packages a `.zip` in `storage/tmp` for distribution. On success the terminal
   prints a human line (e.g. `Success`); add `--json` for the standardized envelope
   `{"ok":true,"command":"gp247:make-plugin","data":{"key":"<Name>","path":"...","msg":"Success"},"warnings":[],"error":null}`
   (zip path at `data.path`), and check the exit code (0 = success). If it errors, stop and surface the
   message — do not hand-create the folder.

3. **Fill `gp247.json`.** Set `name`, author fields, and confirm the version/compatibility fields.
   `configKey` must equal the folder name; `configGroup` is `"Plugins"`; `requireCore` is `["2.1"]`;
   start `version` at `"1.0"` and `requireUpdateFrom` at `"1.0"`. List real dependencies in
   `requireComposerPackages` (composer) and `requireGp247Extensions` (e.g. `Shop`, `Front`, `News`) only if truly
   needed. Keep `requireLivewire` (`false` by default; set `true` only if the plugin genuinely requires
   Livewire). Field reference in `references/file-templates.md`.

4. **(If it has its own table) `Models/ExtensionModel.php`.** Put the `Schema::create(...)` in
   `installExtension()` (guarded by `Schema::hasTable`) and the `Schema::dropIfExists(...)` in
   `uninstallExtension()`, so install/uninstall are symmetric and leave no orphan table. Template in
   `references/file-templates.md`.

5. **(If it has site-owner-editable settings) wire the update-safe config — the core rule.** Because
   step 2's release overwrite wipes `config.php`, that file holds **defaults only**; every editable
   value lives in `admin_config` (DB), which the update preserves. Uncomment/rename the sample
   `<Name>_effective_config()` and `<Name>_save_config()` helpers in `function.php`, keep only defaults
   in `config.php`, and have the admin screen read via `<Name>_effective_config()` and write via
   `<Name>_save_config()`. Exact helper bodies are in `references/file-templates.md`. If the plugin has
   **no** editable settings, delete the two sample helpers to keep it tidy and skip this step.

6. **Admin screen — Livewire + TailAdmin (v2 standard).** Implement the user's admin UI in
   `Livewire/AdminLivewire.php` (logic) and `Views/livewire.blade.php` (UI). `AdminLivewire` extends
   `GP247AdminComponent`, so it already has RBAC, toasts, and the shared admin layout. The scaffold
   `Provider.php` already registers the Livewire namespace
   (`Livewire::addNamespace('<Name>', classNamespace: 'App\GP247\Plugins\<Name>\Livewire')`, guarded by
   `class_exists(\Livewire\Livewire::class)`) — keep it so the component resolves. Prefer the shared
   `<x-gp247::*>` components over raw HTML. **No jQuery / AdminLTE / select2** — 2.0 does not load
   jQuery; use Livewire/Alpine (and flatpickr for dates). Render every string via
   `trans('Plugins/<Name>::lang.key')` or `gp247_language_render(...)`; add the values to both
   `Lang/vi/lang.php` and `Lang/en/lang.php` — never hardcode display text.

7. **(If a public page was requested) front surface.** Implement `Controllers/FrontController.php` +
   `Views/Front.blade.php`; keep `Seo.php` and register it only if the page should contribute URLs to
   `sitemap.xml`. For an admin-only plugin, remove `Seo.php` and `FrontController.php`.

8. **Verify.** Run `php artisan optimize:clear` to load the new routes/views/config, then tell the user
   to install the plugin from admin → **Plugins**, and to test install → enable/disable → open the admin
   screen → uninstall (confirming no leftover table/config). Check light and dark mode.

**Update-safety invariants** (hold across every step; details in `references/file-templates.md`):
`version` only ever increases and `configKey` never changes; editable settings live in the DB, not in
files; DB-schema changes between releases are migrated in the `AppConfig::update($fromVersion)` hook
(idempotent, guarded by `version_compare`); and no user-uploaded files are stored inside the plugin
folder (both `app/…` and `public/…` copies are deleted and replaced on update).

**Secret settings must be encrypted at rest** (gp247/core ≥ 3.0.3; details in
`references/file-templates.md`). Whenever a config field holds a **credential** — API key, access token,
password, OAuth client secret, webhook signing secret — design it as a secret, never plaintext:
- **In the admin config screen (`ConfigForm`)**, declare the field as `password` in `fieldTypes()`. Core
  then masks it, flags the `admin_config` row as secret (`security = 1`) and **encrypts it at rest**
  automatically — no crypto code in the plugin. Read it back with `gp247_config(...)`, which returns
  plaintext transparently.
- **For a secret column in the plugin's OWN table** (not `admin_config`), cast it with
  `protected $casts = ['api_token' => \GP247\Core\Casts\Secret::class]`, make the column **TEXT**, and
  register `table => [columns]` into `config('gp247-config.security.encrypted_columns')` in `Provider.php`
  so `gp247:doctor` and `gp247:encryption-key-rotate` cover it.
- An encrypted value **cannot be searched/filtered** (`WHERE col = ?` is meaningless) — add a separate
  blind-index column if lookup is needed. Do not print secrets to logs/CLI. On a core older than 3.0.3
  these casts/flags do not exist; keep such a plugin's `requireCore` at `["3.0"]` and note the version.

## Output format

Do not print a document. Apply the edits, then give a short English summary in this shape (mark each
line `[x]` done or `[ ]` skipped, and say *why* an optional step was skipped):

```
Created plugin <Name> (v2, update-safe):
- [x] Scaffolded: php artisan gp247:make-plugin --name=<Name> --download=0
- [x] gp247.json → configKey <Name>, requireCore ["2.1"], version 1.0
- [ ] ExtensionModel table: <created `<table>` / skipped — no own table>
- [ ] Update-safe config: <effective/save helpers wired / skipped — no editable settings>
- [x] Admin screen: Livewire AdminLivewire + livewire.blade.php (<what it does>)
- [ ] Front page: <FrontController + Seo / skipped — admin-only>
- [x] Lang: vi + en strings added
Next step: run `php artisan optimize:clear`, then install from admin → Plugins and test install/uninstall.
```

## Examples

The user may phrase the request in Vietnamese, Japanese, or English; you always respond in English.

**Example 1 — admin-only settings plugin**
Input: "Tạo plugin GP247 tên PromoBar hiển thị 1 dòng khuyến mãi trên đầu web, admin bật/tắt và sửa nội
dung." → Spec is complete (admin-configurable, no own table, editable settings, has a front strip), so
ask nothing more. Scaffold `PromoBar`; set `gp247.json`; **no** ExtensionModel table; wire
`PromoBar_effective_config()` / `PromoBar_save_config()` with defaults `{enabled:0, text:''}` in
`config.php`; build the Livewire admin form (toggle + text) reading/writing via those helpers; render the
front strip from the effective config; add vi/en strings. Summary marks the table step skipped. Update-safe
because the text/toggle live in `admin_config`, not in `config.php`.

**Example 2 — plugin with its own table**
Input: "Create a plugin ProductFeed that stores feed URLs in its own table and lists them in admin." →
Confirm: admin-only, own table `product_feed`, feed rows are data (not "settings"). Scaffold;
`ExtensionModel::installExtension()` creates `product_feed`, `uninstallExtension()` drops it; Livewire
CRUD screen over the table; if a later v1.1 adds a column, migrate it idempotently in
`AppConfig::update()`. Summary marks the front-page step skipped (admin-only).

## Common mistakes

| Mistake | Why it hurts / how to avoid |
| --- | --- |
| Letting the site owner edit `config.php` | 1-click update overwrites the file → their choices vanish. Editable values must live in `admin_config` via the config helpers (step 5). |
| Hand-creating the plugin folder instead of scaffolding | Misses required v2 files (Livewire, Provider, Route guards). Always run `gp247:make-plugin` first. |
| Changing `configKey` after release, or not bumping `version` | Update won't match releases / is refused as "not newer". `configKey` is fixed; `version` only increases. |
| jQuery / AdminLTE / select2 on the admin screen | 2.0 does not load jQuery. Use Livewire/Alpine + `<x-gp247::*>` + flatpickr. |
| Hardcoding display text | Breaks i18n. Use `trans('Plugins/<Name>::lang.key')` and fill both vi + en lang files. |
| `install()` creates data but `uninstall()` leaves it | Orphan tables/config after removal. Make the two symmetric. |
| Storing uploads inside the plugin folder | The folder is deleted and replaced on update. Store user files under a shared `public/GP247/…` area or `storage/`. |
| Changing DB schema in a new release with no `update()` hook | Old installs break on update. Migrate idempotently in `AppConfig::update($fromVersion)`, guarded by `version_compare`. |
| Guessing the business logic | Out of scope and likely wrong. Ask the four Input questions when the spec is unclear. |
| Forgetting `php artisan optimize:clear` | Admin shows stale routes/views/config — the #1 support issue after building. |

## Bundled resources

- `references/file-templates.md` — read at steps 3–5 (and for the update() hook) for the full field
  reference of `gp247.json`, the `ExtensionModel` install/uninstall table template, the
  `<Name>_effective_config()` / `<Name>_save_config()` helper pair with the matching `config.php`
  defaults, and the idempotent `AppConfig::update($fromVersion)` migration template. Load it only when
  you reach those steps so SKILL.md stays lean.

---

## Skill info

| Field | Value |
| --- | --- |
| Lần cuối cập nhật / Last updated | `2026-09-04` |
| Skill repo | https://github.com/gp247net/gp247-skills |
| GP247 core repo | https://github.com/gp247net/core |
| source | https://github.com/gp247net/gp247-docs/blob/master/extension/create-plugin.md |
