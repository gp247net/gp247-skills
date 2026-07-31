---
name: gp247-template-create
description: Scaffolds a brand-new GP247 v2 storefront template (theme) with `php artisan gp247:make-template` and edits the generated files in place — gp247.json, AppConfig.php, config.php, function.php, layout, screen/ pages, Lang — to build the storefront look the user describes, the update-safe way so a 1-click version update never wipes the site owner's settings. It also asks whether to customize the gp247/shop pages and, if so, publishes and overrides only the requested ones via the view-fallback mechanism. Always use this skill when the user asks to create, build, scaffold, or start a NEW GP247 / S-Cart template, theme, or storefront skin (the customer-facing look plugged into gp247/front), for example "make a GP247 template", "create a new storefront theme", "scaffold a template". Trigger on Vietnamese, Japanese, or English phrasing of this intent — the team usually writes in Vietnamese (e.g. "tạo template GP247", "làm giao diện mới cho shop", "tạo theme storefront"), so do not wait for an exact English keyword match. Do not use this skill to build an admin feature plugin (use gp247-plugin-create), to upgrade an existing 1.x plugin to v2 (use gp247-plugin-v1-to-v2), or to modify gp247/core, front, or shop themselves.
---

# gp247-template-create — Scaffold & build a new GP247 v2 storefront template (update-safe)

## Purpose

Create a new GP247 **template** (storefront theme) the correct v2 way. A template is the
customer-facing look of the shop; it only runs when `gp247/front` is installed, so its `gp247.json`
always requires `gp247/front`. GP247 has a **1-click update** mechanism: on update it **overwrites every
file** of the template but **keeps the database** (`admin_config`). A template written the wrong way —
for example letting the site owner edit `config.php` — loses their choices on every update. This skill
scaffolds the template with `php artisan gp247:make-template`, then edits the generated files to build
the user's design **and** enforce the update-safety rules.

Crucially, it applies the **view-fallback mechanism**: a template needs no `gp247/shop` pages of its own
— `gp247/shop` serves its default product/cart/checkout views automatically. The skill asks whether the
user wants to restyle any shop pages and, only if so, publishes and overrides just those.

It turns the manual process in `gp247-docs/extension/create-template.md` into applied source edits.

## Output language

**English throughout** — code edits, questions to the user, progress notes, and the final summary are
all in English, per the GP247 English-only rule. The user may write in Vietnamese, Japanese, or English
(see the trigger note above); you respond in English. The deliverable is template source files, not a
natural-language document. Never translate code identifiers, blade directives, view namespaces
(`GP247TemplatePath::...`), or the display-text keys passed to `trans(...)` /
`gp247_language_render(...)` — only the *values* in `Lang/vi/lang.php` and `Lang/en/lang.php` are
localized.

## When NOT to use

- Building an **admin feature plugin** (a package plugged into the admin under `Plugins/`) — use
  `gp247-plugin-create`. A template is a storefront theme, not a plugin, and uses a different scaffold
  command (`gp247:make-template`, not `gp247:make-plugin`).
- Upgrading or converting an **existing 1.x plugin** to v2 — use `gp247-plugin-v1-to-v2`.
- Editing `gp247/core`, `gp247/front`, or `gp247/shop` themselves — this skill only creates a folder
  under `app/GP247/Templates/<Name>/`.
- Only *explaining* how templates work with no intent to build one — answer directly or point at
  `gp247-docs/extension/create-template.md` instead of scaffolding files.

## Input

The template's specification. The user supplies it in the prompt, or you ask for the missing pieces
before scaffolding — **never guess the design**. You need four answers:

1. **Name** — PascalCase, no spaces/accents (e.g. `MyShopSkin`, `AuroraStore`). Becomes both the folder
   name and the `configKey`; they must match and must not change after release.
2. **`gp247/front` present?** — a template does nothing without it. Confirm the site has `gp247/front`
   (and usually `gp247/shop` for a selling site). If `gp247/front` is absent, stop and say so — the
   template cannot run.
3. **Shop pages — do they want to restyle any?** — ask explicitly. By default the template ships **no**
   shop pages and `gp247/shop` serves its defaults via fallback (that is correct and expected). Only if
   the user wants a custom look for specific pages (product list, cart, checkout…) do you publish and
   override those — and only those. Do **not** copy the whole shop view set "just in case"; that creates
   maintenance debt and freezes those pages against shop-package updates.
4. **Site-owner-editable settings?** — any value the site owner may change from admin (colors, layout
   toggles, a banner text…). These decide whether you wire the update-safe config helpers.

If the user described the design but left one of these ambiguous, ask a short, specific question for
that one point only. Do not re-ask what they already stated.

## Workflow

Do the steps in order. Steps 1–3, 5, and 8 always run. Steps 4, 6, 7 depend on the **Input** answers.

1. **Confirm the spec.** Restate the four Input answers in one line — especially which shop pages (if
   any) will be overridden and whether there are editable settings. This is the plan; get it straight
   before editing.

2. **Scaffold.** From the website root (where `artisan` lives), run:

   ```bash
   php artisan gp247:make-template --name=<Name> --download=0
   ```

   `--download=0` writes the template directly to `app/GP247/Templates/<Name>` (and its assets to
   `public/GP247/Templates/<Name>`), usable immediately; `--download=1` instead packages a `.zip` in
   `storage/tmp` for distribution. On success the terminal returns `{"error":0,...}`. If it errors, stop
   and surface the message — do not hand-create the folder.

3. **Fill `gp247.json`.** Set `name`, author fields, and confirm the compatibility fields. `configKey`
   must equal the folder name; `configGroup` is `"Templates"`; `requireCore` is `["2.0"]`;
   `requirePackages` **must include `"gp247/front"`** (add `"gp247/shop"` if the template is only for
   selling sites); start `version` at `"1.0"` and `requireUpdateFrom` at `"1.0"`. Field reference in
   `references/file-templates.md`.

4. **(If it has site-owner-editable settings) wire the update-safe config.** Because step 2's release
   overwrite wipes `config.php`, that file holds **defaults only**; every editable value lives in
   `admin_config` (DB), which the update preserves. Add the `<Name>_effective_config()` /
   `<Name>_save_config()` helper pair to `function.php`, keep only defaults in `config.php`, and read via
   the effective helper in the layout/pages. Exact bodies in `references/file-templates.md`. If the
   template has **no** editable settings, skip this step.

5. **Build the front look (v2 standard — Tailwind + Alpine + Livewire, no jQuery).** Implement the
   overall shell `layout.blade.php` (or `layout/`) and the front pages under `screen/`
   (`home.blade.php`, `page_detail.blade.php`, `404.blade.php`, `front_search.blade.php`), plus any
   `partials/` / `components/`. Template views resolve under the namespace
   `GP247TemplatePath::<Name>.<path>` — `gp247/front` scans `app/GP247/Templates` automatically, so you
   register nothing. **No jQuery / AdminLTE / Bootstrap widgets** — use Alpine/Livewire (flatpickr for
   dates). Render every string via `gp247_language_render(...)` / `trans('...')` and fill both
   `Lang/vi/lang.php` and `Lang/en/lang.php` — never hardcode display text.

6. **(Only if the user wants to restyle shop pages — the fallback rule) override just those pages.**
   Publish the shop's default storefront views as a reference, then copy **only** the requested pages
   into your template at the exact same sub-path, and edit them there:

   ```bash
   php artisan vendor:publish --tag=gp247:shop-view-front
   ```

   This copies the shop defaults into `app/GP247/Templates/GP247Front` (the command's fixed
   destination). Copy the wanted page(s) — e.g.
   `GP247Front/screen/shop_product_list.blade.php` → `<Name>/screen/shop_product_list.blade.php` — and
   restyle inside `<Name>`. `gp247/shop` then prefers your version for the pages you copied and keeps its
   default for everything else. Do **not** copy the whole set. The override path table is in
   `references/file-templates.md`. If the user wants no shop customization, skip this step entirely — the
   site still sells normally on the shop defaults.

   **If you override the checkout view** (`livewire/shop_checkout-wizard.blade.php`), keep the two
   total-method includes at the confirm step —
   `@include('gp247-shop-front::partials.checkout_total_methods')` and
   `@include('gp247-shop-front::partials.order_totals')`. They render every coupon/point plugin
   (`CheckoutTotalMethod` contract) generically; dropping them silently removes the coupon input for
   every total-method plugin. Fragments must use only UI tokens your template's precompiled CSS ships
   (step 7).

7. **(If Tailwind classes are new) rebuild the CSS.** GP247 uses **precompiled** Tailwind, not JIT — a
   class that was never built has no effect. If you only reused existing classes, no rebuild is needed.
   If you added new ones, recompile the template's CSS (`npx tailwindcss ...`) and include the output in
   the template's `public/` (see ADR-014 conventions). If Node/network is unavailable, say so and flag
   the classes as unverified rather than claiming the look is done.

8. **Verify.** Run `php artisan optimize:clear` to load the new routes/views/config, then tell the user
   to install the template from admin → **Templates**, activate it for the store, and open the home page
   (and, if `gp247/shop` is present, a product/cart page — overridden ones show the new look, others show
   the default). Check phone (responsive) and dark mode if supported.

**Update-safety invariants** (hold across every step; details in `references/file-templates.md`):
`version` only ever increases and `configKey` never changes; editable settings live in the DB, not in
files; DB/config-format changes between releases are migrated in the idempotent
`AppConfig::update($fromVersion)` hook (guarded by `version_compare`); and no user-uploaded files are
stored inside the template folder (both `app/…` and `public/…` copies are deleted and replaced on
update).

## Output format

Do not print a document. Apply the edits, then give a short English summary in this shape (mark each
line `[x]` done or `[ ]` skipped, and say *why* an optional step was skipped):

```
Created template <Name> (v2, update-safe):
- [x] Scaffolded: php artisan gp247:make-template --name=<Name> --download=0
- [x] gp247.json → configGroup "Templates", configKey <Name>, requirePackages ["gp247/front"], version 1.0
- [ ] Update-safe config: <effective/save helpers wired / skipped — no editable settings>
- [x] Front look: layout + screen/home, page_detail, 404 (<what it looks like>)
- [ ] Shop overrides: <copied screen/shop_cart only / skipped — using gp247/shop fallback defaults>
- [ ] Tailwind rebuild: <rebuilt / not needed — reused existing classes / UNVERIFIED — no Node>
- [x] Lang: vi + en strings added
Next step: run `php artisan optimize:clear`, then install + activate from admin → Templates and test.
```

## Examples

The user may phrase the request in Vietnamese, Japanese, or English; you always respond in English.

**Example 1 — front-only theme, no shop customization**
Input: "Tạo template GP247 tên AuroraStore, trang chủ có banner lớn + lưới sản phẩm nổi bật, còn các
trang shop giữ mặc định." → Spec is clear and the user explicitly wants shop pages on the default, so
confirm and scaffold `AuroraStore`; set `gp247.json` (`requirePackages: ["gp247/front"]`); build
`layout.blade.php` + `screen/home.blade.php` (banner + featured grid) + `page_detail`/`404`; **skip the
shop-override step** — product list/cart/checkout run on the `gp247/shop` fallback. Add vi/en strings.
Summary marks the shop-overrides step skipped with that reason.

**Example 2 — theme that restyles the cart only, with an editable accent color**
Input: "Create a template ModaSkin; I want a custom cart page and an admin-set accent color." →
Confirm: only `shop_cart` is restyled; accent color is an editable setting. Scaffold; wire
`ModaSkin_effective_config()` / `ModaSkin_save_config()` with default `{accent:'#4f46e5'}` in
`config.php`; build the front shell reading the accent from the effective config; `vendor:publish
--tag=gp247:shop-view-front` then copy **only** `screen/shop_cart.blade.php` into `ModaSkin` and restyle
it; leave every other shop page on the default. Rebuild CSS if the accent introduced new classes.
Summary marks front look + config + the single shop override done, other shop pages on fallback.

## Common mistakes

| Mistake | Why it hurts / how to avoid |
| --- | --- |
| Forgetting `requirePackages: ["gp247/front"]` | The template silently does nothing — a template only runs with the storefront present. |
| Copying the whole `gp247/shop` view set into the template | Needless maintenance burden; those pages freeze against shop-package updates. Override only the pages the user asked for (step 6). |
| Assuming a template with no shop pages breaks the shop | It doesn't — `gp247/shop` falls back to its own default views. Only override to change the look. |
| Letting the site owner edit `config.php` | 1-click update overwrites the file → their choices vanish. Editable values live in `admin_config` via the config helpers (step 4). |
| Hand-creating the template folder instead of scaffolding | Misses required v2 files (AppConfig, Provider, gp247.json, Lang). Always run `gp247:make-template` first. |
| Changing `configKey` after release, or not bumping `version` | Update won't match releases / is refused as "not newer". `configKey` is fixed; `version` only increases. |
| jQuery / AdminLTE / Bootstrap widgets in the theme | 2.0 uses Tailwind + Alpine + Livewire. Use those (flatpickr for dates). |
| Hardcoding display text | Breaks i18n. Use `gp247_language_render(...)` / `trans(...)` and fill both vi + en lang files. |
| Adding new Tailwind classes without rebuilding | Precompiled (not JIT) — an unbuilt class has no effect and the element silently falls back. Rebuild the CSS (step 7). |
| Overriding a shop page at the wrong sub-path/name | Fallback matches by exact sub-path; a wrong name means the default is used and your file is ignored. |
| Storing uploads inside the template folder | The folder is deleted and replaced on update. Store user files under a shared area or `storage/`. |
| Forgetting `php artisan optimize:clear` | Storefront shows stale routes/views/config — the #1 support issue after building. |

## Bundled resources

- `references/file-templates.md` — read at steps 3–6 for the `gp247.json` field reference, the
  `<Name>_effective_config()` / `<Name>_save_config()` helper pair with matching `config.php` defaults,
  the idempotent `AppConfig::update($fromVersion)` migration template, the shop-page override path table
  and publish workflow, and the "overwrite vs preserve" table. Load it only when you reach those steps so
  SKILL.md stays lean.

---

## Skill info

| Field | Value |
| --- | --- |
| Lần cuối cập nhật / Last updated | `2026-07-31` |
| Skill repo | https://github.com/gp247net/gp247-skills |
| GP247 core repo | https://github.com/gp247net/core |
| source | https://github.com/gp247net/gp247-docs/blob/master/extension/create-template.md |
