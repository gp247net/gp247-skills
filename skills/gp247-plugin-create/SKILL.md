---
name: gp247-plugin-create
description: Scaffolds a brand-new GP247 plugin in the v2 plugin format (Livewire + TailAdmin admin shell) for whatever gp247/core version the site runs — it probes the installation first instead of assuming a version — and edits the generated files in place (gp247.json, AppConfig.php, config.php, function.php, Models/ExtensionModel.php, Livewire, Route, Lang) to implement the plugin the user describes, built the update-safe way so a 1-click version update never wipes the site owner's settings or data. Always use this skill when the user asks to create, build, scaffold, generate, or start a NEW GP247 / S-Cart plugin (an admin feature package plugged into gp247/core, including payment, shipping and checkout-discount plugins), for example "make a GP247 plugin that…", "create a new plugin", "scaffold a plugin". Trigger on Vietnamese, Japanese, or English phrasing of this intent — the team usually writes in Vietnamese (e.g. "tạo plugin GP247", "viết plugin mới", "làm plugin cho GP247"), so do not wait for an exact English keyword match. Do not use this skill to build a storefront theme/template (use gp247-template-create), or to modify gp247/core, front, or shop themselves.
---

# gp247-plugin-create — Scaffold & build a new GP247 plugin (update-safe)

## Purpose

Create a new GP247 plugin the correct way. GP247 has a **1-click update** mechanism: on update it
**overwrites every file** of the plugin with the new release but **keeps the database** (`admin_config`
and the plugin's own tables). A plugin written the wrong way — for example letting the site owner edit
`config.php` — loses all of their choices on every update. This skill probes the installation, scaffolds
the plugin with `php artisan gp247:make-plugin`, then edits the generated files to implement the user's
feature **and** enforce the update-safety rules, so the plugin ships correctly the first time.

It replaces the manual, easy-to-get-wrong process described in
`gp247-docs/extension/create-plugin.md`, turning it into applied source edits.

**Never assume a core version.** gp247/core keeps moving; this skill names no version number to copy.
Step 0 reads the running version and the available features from the site itself, and every later step
branches on those facts.

## Output language

**English throughout** — code edits, questions to the user, progress notes, and the final summary are
all in English, per the GP247 English-only rule. The user may write in Vietnamese, Japanese, or English
(see the trigger note above); you respond in English. The deliverable is plugin source files, not a
natural-language document. Never translate code identifiers, blade directives, or the display-text keys
passed to `trans(...)` / `gp247_language_render(...)` (those keys stay verbatim; only the *values* in
`Lang/vi/lang.php` and `Lang/en/lang.php` are localized).

## When NOT to use

- Building a **storefront theme/template** (the shop's look) — use `gp247-template-create`; a template is
  not a plugin and does not use `gp247:make-plugin`.
- Editing `gp247/core`, `gp247/front`, or `gp247/shop` themselves — this skill only creates a folder
  under `app/GP247/Plugins/<Extension_Key>/`.
- Only *explaining* how plugins work with no intent to build one — answer directly or point at
  `gp247-docs/extension/create-plugin.md` instead of scaffolding files.

## Input

The plugin's specification. The user supplies it in the prompt, or you ask for the missing pieces
before scaffolding — **never guess the business logic**. You need four answers:

1. **Name** — PascalCase, no spaces/accents (e.g. `MyBanner`, `ProductFeed`). Becomes both the folder
   name and the `configKey`; they must match and must not change after release.
2. **Surface** — where the plugin shows up:
   - **admin-only**;
   - a **public (storefront) page** of its own;
   - **content on existing storefront pages** — a block the site owner places, or output at a fixed
     spot of a shop page (see step 7);
   - a **checkout role**: payment method, shipping method, or checkout total-method (coupon/points).
     These have extra contracts — see `references/checkout-plugins.md`.
3. **Own data table?** — does it store records of its own (needs a table created on install), or does it
   only hold a handful of settings?
4. **Site-owner-editable settings?** — any value the site owner may change from admin (toggles,
   items-per-page, their own API key…). These decide how you wire settings in step 5.

If the user described the feature but left one of these ambiguous, ask a short, specific question for
that one point only. Do not re-ask what they already stated.

## Workflow

Do the steps in order. Steps 0–3 and 9 always run. Steps 4–8 depend on the answers from **Input** and on
what step 0 found.

0. **Probe the installation.** From the website root (where `artisan` lives), run the bundled read-only
   probe and keep its JSON for the rest of the task:

   ```bash
   php <this-skill-dir>/scripts/gp247-probe.php
   ```

   It prints the core version **exactly as the extension compatibility check reads it** (`core`), a
   ready `require_core` value, which packages are usable (`packages.<core|front|shop>.code` = code
   present, `.db` = installed in the database), the `gp247:*` commands, and a `capabilities` map. Use it
   like this:
   - `bootstrapped: false` → read `bootstrap_error`. If `core` is still set (read from the core config
     file), you may continue but treat every capability as unknown and say so; if `core` is `null`, stop —
     this is not a GP247 site root.
   - `packages.front.db` / `packages.shop.db` not `true` → do not build a storefront or checkout surface
     the site cannot run; tell the user what must be installed first. A composer package alone is **not**
     enough — the `.db` flag must be true.
   - Every feature below that says "if `capabilities.X`" is used only when the probe says it exists. If
     a needed capability is missing, tell the user which feature their core lacks instead of writing code
     against it.
   - Do not take the version from composer (`composer show`, `installed.json`, or the `versions` block
     of `gp247:info`): it can disagree with the version the compatibility check uses.

1. **Confirm the spec.** Restate the four Input answers and the probe facts that matter (core version,
   front/shop usable, missing capabilities) in a few lines, and note which optional steps apply. This is
   the plan — get it straight before editing.

2. **Scaffold.**

   ```bash
   php artisan gp247:make-plugin --name=<Name> --download=0
   ```

   `--download=0` writes the plugin directly to `app/GP247/Plugins/<Name>` (usable immediately);
   `--download=1` instead packages a `.zip` in `storage/tmp` for distribution. On success the terminal
   prints a human line (e.g. `Success`); if `capabilities.json_output`, add `--json` for the envelope
   `{"ok":true,"command":"gp247:make-plugin","data":{"key":"<Name>","path":"...","msg":"Success"},"warnings":[],"error":null}`.
   Check the exit code (0 = success). If it errors, stop and surface the message — do not hand-create the
   folder.

3. **Fill `gp247.json`.** Set `name`, author fields, and the compatibility fields:
   - `configKey` must equal the folder name; `configGroup` is `"Plugins"`.
   - `configCode` follows the surface: `"Payment"` for a payment method, `"Shipping"` for a shipping
     method, `"Promotion"` for a checkout total-method, otherwise the same as `configKey`.
   - **`requireCore` = the probe's `require_core`** (the running `major.minor`). Each entry is a range —
     `"X.Y"` means `>= X.Y.0` and `< (X+1).0.0` — so write **one entry per major** you support, never a
     list of every minor. Lower the floor only when the user wants older sites **and** you have
     confirmed every capability the plugin uses exists there. Replace whatever value the scaffolder
     wrote; it is a static default, not your site's version.
   - Start `version` at `"1.0"` and `requireUpdateFrom` at `"1.0"`. List real dependencies in
     `requireComposerPackages` (e.g. `gp247/shop` for a checkout plugin) and `requireGp247Extensions`
     only if truly needed. Keep `requireLivewire` `false` unless the plugin itself requires Livewire.
   - `storeScope`: `"global"` unless the settings must differ per store (details in
     `references/file-templates.md` §1).

4. **(If it has its own table) `Models/ExtensionModel.php`.** Put the `Schema::create(...)` in
   `installExtension()` (guarded by `Schema::hasTable`) and the `Schema::dropIfExists(...)` in
   `uninstallExtension()`. **Prefer this direct-`Schema` pattern — it is re-entrant** (install recreates
   the table every time). Laravel migration files carry a ledger trap on reinstall; read the callout in
   `references/file-templates.md` §2 before choosing them.

5. **(If it has site-owner-editable settings) wire update-safe settings — the core rule.** A release
   overwrites `config.php`, so that file holds **defaults only**; every editable value lives in
   `admin_config` (DB), which the update preserves. Pick **one** model (both in
   `references/file-templates.md` §3):
   - **A — settings screen, one row per key** (recommended when `capabilities.config_form`). The admin
     component extends `ConfigForm`; each setting is its own `admin_config` row keyed
     `<Name>_<setting>`, seeded in `install()` and topped up in `update()`. This is the only model that
     can hold a **secret** (per-field `password` type, encrypted at rest).
   - **B — one JSON row** (`<Name>_effective_config()` / `<Name>_save_config()` helpers that ship
     commented out in `function.php`) for structured settings a flat form cannot express. It cannot hold
     a secret.

   If the plugin has **no** editable settings, delete the sample helpers in `function.php` and skip this
   step.

6. **Admin screen — Livewire + TailAdmin.** Implement the UI in `Livewire/AdminLivewire.php` (logic) and
   `Views/livewire.blade.php` (UI). `AdminLivewire` extends `GP247AdminComponent` (or `ConfigForm` for
   model A), so it already has RBAC, toasts, and the shared admin layout. Keep the scaffold's guarded
   `Livewire::addNamespace(...)` in `Provider.php`. Then:
   - **Route:** the scaffold's `Route.php` serves `/` with the legacy `Admin/AdminController` and the
     Livewire screen at `/livewire`. Point `/` (route name `admin_<url_key>.index`) at `AdminLivewire` and
     delete the legacy controller route, `Admin/` and `Views/Admin.blade.php` once nothing uses them.
   - **Menu:** if the screen needs a sidebar entry, add an `admin_menu` row in `install()` (skip if it
     already exists, so it is safe to repeat), call the same helper from `update()`, and delete it in
     `uninstall()` — template in `references/file-templates.md` §7.
   - Prefer the shared `<x-gp247::*>` components over raw HTML. **No jQuery / AdminLTE / select2** —
     the admin shell does not load jQuery; use Livewire/Alpine (and flatpickr for dates). Render every
     string via `trans('Plugins/<Name>::lang.key')` or `gp247_language_render(...)`; add the values to
     both `Lang/vi/lang.php` and `Lang/en/lang.php` — never hardcode display text.

7. **Storefront surface** (only if `packages.front.db`; templates are owned by other people, so a plugin
   never edits or ships template files):
   - **Own public page:** implement `Controllers/FrontController.php` + `Views/Front.blade.php` and its
     front route; keep `Seo.php` (and its registration in `Provider.php`) only if the page should add
     URLs to `sitemap.xml` — each entry needs `loc` (entries without it are dropped) and should carry
     `alias` so the site owner's sitemap exclusions apply to it.
   - **A block the site owner places** (if `capabilities.front_layout_block_views`): register
     `'<block_name>' => '<Name view key>'` into `gp247-config.front.layout_block_views` from `Provider.php`;
     the block then appears in the admin Layout Block screen for every template.
   - **Output at a fixed spot of a shop page** (if `capabilities.front_plugin_hooks`): append a renderer
     to `gp247-config.front.plugin_hooks.<hook>` (e.g. `shop_product_detail_bottom`); the page calls
     `gp247_render_plugin_hook()` there.
   - **Admin-only plugin:** delete `Seo.php` **and** its sitemap registration in `Provider.php`, plus
     `Controllers/FrontController.php` and any front route — removing only the files leaves a dead SEO
     toggle and a route to a missing controller.

   Snippets for both registries: `references/file-templates.md` §8.

8. **(If a checkout role) payment / shipping / total-method.** Follow `references/checkout-plugins.md`:
   discovery by `configCode`, the `CheckoutTotalMethod` contract, the order-money rules for a payment
   method, and its webhook route. Use it only when `packages.shop.db` is true.

9. **Verify.** Run `php artisan optimize:clear`, then — with the user's OK, because these commands write
   the DB — install and exercise the lifecycle from the command line. The folder is already on disk, so
   the install is **in place**: nothing is downloaded and no API License is needed. The scaffold's
   `install()` writes the ON value, so the plugin is **enabled** right after install:

   ```bash
   php artisan gp247:ext-install   --type=plugin --key=<Name>
   php artisan gp247:ext-list      --type=plugin --json            # installed + active
   php artisan gp247:ext-disable   --type=plugin --key=<Name>
   php artisan gp247:ext-enable    --type=plugin --key=<Name>
   php artisan gp247:ext-uninstall --type=plugin --key=<Name> --only-data   # KEEP the source files
   php artisan gp247:ext-install   --type=plugin --key=<Name>      # reinstall: install() must rebuild everything
   ```

   **Always pass `--only-data` when uninstalling the plugin you are building** — a plain `ext-uninstall`
   of an installed extension also **deletes its source folder** (`app/…` and `public/…`), i.e. the code
   you just wrote. After the uninstall, confirm no leftover table/config/menu; after the reinstall,
   confirm tables, settings rows, menu and language rows are all back. Open the admin screen and check
   light and dark mode. If you edit files under the plugin's `public/` after install and
   `capabilities.ext_publish`, re-copy them with `php artisan gp247:ext-publish --type=plugin --key=<Name>`.
   If the plugin has an `update()` hook and `capabilities.ext_update_local`, also run
   `php artisan gp247:ext-update --type=plugin --key=<Name> --local --dry-run` to see it would run. To
   ship the plugin to another site, zip the folder and install it there with
   `gp247:ext-install --type=plugin --file=<Name>.zip`.

**Update-safety invariants** (hold across every step; details in `references/file-templates.md`):
`version` only ever increases and `configKey` never changes; editable settings live in the DB, not in
files; no user-uploaded files are stored inside the plugin folder (both `app/…` and `public/…` copies are
deleted and replaced on update); and `AppConfig::update(?string $fromVersion)` is **idempotent and handles
`null`**. Besides the 1-click update, when `capabilities.extension_data_updater` core also runs `update()`
after files changed by `git pull` / composer / manual copy (`gp247:update`, `gp247:ext-update --local`, the
admin "Apply" button) — once with `$fromVersion = null` on a site that has no recorded version. So
`update()` must add any setting row, menu entry or column a release introduced, checking the current state
rather than trusting the version number.

**Secret settings must be encrypted at rest** (if `capabilities.secret_cast`; details in
`references/file-templates.md` §6). Whenever a setting holds a **credential** — API key, access token,
password, OAuth client secret, webhook signing secret — design it as a secret, never plaintext:
- **On the settings screen (model A)**, declare the field as `password` in `fieldTypes()` and seed its
  `admin_config` row with `security = 1`. Core masks it, encrypts it at rest and (if
  `capabilities.config_form_write_only_secrets`) never sends the saved value back to the browser. Read it
  with `gp247_config('<Name>_<setting>')`, which returns plaintext transparently.
- **For a secret column in the plugin's OWN table**, cast it with `\GP247\Core\Casts\Secret::class`, make
  the column **TEXT**, and register `table => [columns]` into
  `config('gp247-config.security.encrypted_columns')` in `Provider.php` so `gp247:doctor` and
  `gp247:encryption-key-rotate` cover it.
- An encrypted value **cannot be searched/filtered** — add a separate blind-index column if lookup is
  needed. Do not print secrets to logs/CLI.
- If the probe reports no `secret_cast`, the site's core cannot encrypt: tell the user, and either raise
  `requireCore` to a core that has it or leave the credential out.

## Output format

Do not print a document. Apply the edits, then give a short English summary in this shape (mark each
line `[x]` done or `[ ]` skipped, and say *why* an optional step was skipped):

```
Created plugin <Name> (update-safe) on gp247/core <probe.core>:
- [x] Probed: core <core>, front <usable/not>, shop <usable/not>; missing capabilities: <none / list>
- [x] Scaffolded: php artisan gp247:make-plugin --name=<Name> --download=0
- [x] gp247.json → configKey <Name>, configCode <code>, requireCore <probe.require_core>, version 1.0
- [ ] ExtensionModel table: <created `<table>` / skipped — no own table>
- [ ] Settings: <model A ConfigForm rows / model B JSON row / skipped — no editable settings>
- [x] Admin screen: Livewire AdminLivewire at admin_<url_key>.index (<what it does>); menu <added / none>
- [ ] Storefront: <own page + Seo / layout block / plugin hook / skipped — admin-only>
- [ ] Checkout role: <payment / shipping / total-method / none>
- [x] Lang: vi + en strings added
- [ ] Installed / lifecycle tested: <ext-install → disable → enable → uninstall --only-data → reinstall OK / skipped — user will test>
Next step: open the plugin's admin screen and test it; ship to other sites with `gp247:ext-install --type=plugin --file=<Name>.zip`.
```

## Examples

The user may phrase the request in Vietnamese, Japanese, or English; you always respond in English.

**Example 1 — settings plugin with a storefront strip**
Input: "Tạo plugin GP247 tên PromoBar hiển thị 1 dòng khuyến mãi trên đầu web, admin bật/tắt và sửa nội
dung." → Spec is complete (no own table, editable settings, content on existing pages). Probe: front
usable, `front_layout_block_views` true. Scaffold `PromoBar`; `requireCore` = probe value; settings model A
(`PromoBar_enabled` bool, `PromoBar_text` text) seeded in `install()` and topped up in `update()`; Livewire
`ConfigForm` screen; register the strip as a layout block so the site owner places it in the top area of
any template; add vi/en strings. Summary marks the table step skipped.

**Example 2 — plugin with its own table**
Input: "Create a plugin ProductFeed that stores feed URLs in its own table and lists them in admin." →
Confirm: admin-only, own table `product_feed`, feed rows are data (not "settings"). Scaffold;
`installExtension()` creates `product_feed`, `uninstallExtension()` drops it; Livewire CRUD screen as the
admin `/` route; delete `Seo.php`, its registration and `FrontController.php`; if a later v1.1 adds a
column, add it in `update()` guarded by `Schema::hasColumn`.

**Example 3 — payment method on a site whose core lacks a capability**
Input: "Viết plugin thanh toán MoMo." → Probe: shop usable; `secret_cast` false. Tell the user the site's
core cannot encrypt the MoMo secret key, and ask whether to raise `requireCore` to a core that can or to
stop. Only then follow `references/checkout-plugins.md`.

## Common mistakes

| Mistake | Why it hurts / how to avoid |
| --- | --- |
| Copying a `requireCore` value from an example or old plugin | Each entry is a range ending before the next major; a value from another major makes core refuse to install ("not compatible"). Use the probe's `require_core`. |
| Listing every minor in `requireCore` (`["3.0","3.1"]`) | Redundant — `"3.0"` already covers all of 3.x. One entry per supported major. |
| Taking the core version from composer | Composer metadata can disagree with `config('gp247.core')`, which is what the compatibility check uses. Use the probe. |
| Letting the site owner edit `config.php` | 1-click update overwrites the file → their choices vanish. Editable values must live in `admin_config` (step 5). |
| Seeding a setting only in `config.php` and expecting it on the settings screen | `ConfigForm` shows `admin_config` rows only. Insert the row in `install()` and top it up in `update()`. |
| Unprefixed setting keys (`api_secret`) | `gp247_config()` looks keys up across plugins; another plugin's key can collide. Always `<Name>_<setting>`. |
| A `ConfigForm` without `keys()` | It would list every row of the `Plugins` group. Return this plugin's keys. |
| Hand-creating the plugin folder instead of scaffolding | Misses required files (Livewire, Provider, Route guards). Always run `gp247:make-plugin` first. |
| Changing `configKey` after release, or not bumping `version` | Update won't match releases / is refused as "not newer". |
| jQuery / AdminLTE / select2 on the admin screen | The admin shell does not load jQuery. Use Livewire/Alpine + `<x-gp247::*>` + flatpickr. |
| Hardcoding display text | Breaks i18n. Use `trans('Plugins/<Name>::lang.key')` and fill both vi + en lang files. |
| `install()` creates data/menu but `uninstall()` leaves it | Orphan tables/config/menu after removal. Make the two symmetric. |
| `update()` that returns early on `$fromVersion === null` | Core calls it with `null` after git/composer updates on sites with no recorded version; the new rows/columns never arrive. Guard on the current state instead. |
| Provisioning tables with Laravel **migration files** without cleaning the ledger on uninstall | Reinstall runs nothing and the table stays missing → `42S02` fatal. Prefer `Schema` in `ExtensionModel`; see §2 of the references. |
| Shipping a file under `app/GP247/Templates/...` to show a storefront block | Ties the plugin to one template and is left behind on removal. Register a layout block or a plugin hook (step 7). |
| Storing uploads inside the plugin folder | The folder is deleted and replaced on update. Store user files under a shared `public/GP247/…` area or `storage/`. |
| Guessing the business logic | Out of scope and likely wrong. Ask the four Input questions when the spec is unclear. |
| Forgetting `php artisan optimize:clear` | Admin shows stale routes/views/config — the #1 support issue after building. |
| Testing uninstall with a plain `gp247:ext-uninstall` on the plugin you are building | It **deletes the source folder** — the code you just wrote is gone. Always use `--only-data` while developing (admin: "Only remove data", not "Remove"). |
| Running `gp247:ext-enable` right after `ext-install` | Redundant: the scaffold's `install()` already writes the ON value. |

## Bundled resources

- `scripts/gp247-probe.php` — step 0. Read-only; prints one JSON object (core version, `require_core`,
  usable packages, `gp247:*` commands, capabilities). Identical copies ship with every gp247-* skill.
- `references/file-templates.md` — read at steps 3–7 and for the `update()` hook: `gp247.json` field
  reference, `ExtensionModel` table template and the migration-ledger trap, the two settings models,
  `update()`, the overwrite/preserve map, secrets, admin menu, storefront registries.
- `references/checkout-plugins.md` — read at step 8 only: payment, shipping and total-method plugins.

---

## Skill info

| Field | Value |
| --- | --- |
| Lần cuối cập nhật / Last updated | `2026-10-03` |
| Skill repo | https://github.com/gp247net/gp247-skills |
| GP247 core repo | https://github.com/gp247net/core |
| source | https://github.com/gp247net/gp247-docs/blob/main/extension/create-plugin.md |
