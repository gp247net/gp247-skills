---
name: gp247-template-create
description: Scaffolds a brand-new GP247 storefront template (theme) with `php artisan gp247:make-template` for whatever gp247/core version the site runs — it probes the installation first instead of assuming a version — and builds the files a template must provide (gp247.json, AppConfig.php, config.php, function.php, layout, the required screen/ and common/ views, Lang, compiled CSS) to produce the storefront look the user describes, the update-safe way so a 1-click version update never wipes the site owner's settings. It also asks whether to restyle any gp247/shop pages and, if so, overrides only the requested ones via the view-fallback mechanism. Always use this skill when the user asks to create, build, scaffold, or start a NEW GP247 / S-Cart template, theme, or storefront skin (the customer-facing look plugged into gp247/front), for example "make a GP247 template", "create a new storefront theme", "scaffold a template". Trigger on Vietnamese, Japanese, or English phrasing of this intent — the team usually writes in Vietnamese (e.g. "tạo template GP247", "làm giao diện mới cho shop", "tạo theme storefront"), so do not wait for an exact English keyword match. Do not use this skill to build an admin feature plugin (use gp247-plugin-create), or to modify gp247/core, front, or shop themselves.
---

# gp247-template-create — Scaffold & build a new GP247 storefront template (update-safe)

## Purpose

Create a new GP247 **template** (storefront theme) the correct way. A template is the customer-facing look
of the shop; it only runs when `gp247/front` is installed. GP247 has a **1-click update** mechanism: on
update it **overwrites every file** of the template but **keeps the database** (`admin_config`). A
template written the wrong way — for example letting the site owner edit `config.php` — loses their
choices on every update. This skill probes the installation, scaffolds the template with
`php artisan gp247:make-template`, builds the views the storefront needs, and enforces the update-safety
rules.

Two facts drive the design:
- **The scaffold is an empty shell.** It ships `gp247.json`, `AppConfig.php`, `Provider.php`, `Lang/`, a
  sample block — but no layout and no pages. The template itself must provide the layout and the pages
  `gp247/front` renders; without them the storefront stops with "view not found".
- **Shop pages fall back, the layout does not.** `gp247/shop` serves its own product/cart/checkout pages
  when the template has none — but those pages extend **the template's** `layout` and include a few of
  **the template's** `common/` partials. So the template restyles shop pages only on request, yet always
  ships the layout and partials they rely on.

It turns the manual process in `gp247-docs/extension/create-template.md` into applied source edits.

**Never assume a core version.** Step 0 reads the running version and the available features from the
site; later steps branch on those facts.

## Output language

**English throughout** — code edits, questions to the user, progress notes, and the final summary are
all in English, per the GP247 English-only rule. The user may write in Vietnamese, Japanese, or English
(see the trigger note above); you respond in English. The deliverable is template source files, not a
natural-language document. Never translate code identifiers, blade directives, view namespaces
(`GP247TemplatePath::...`), or the display-text keys passed to `trans(...)` /
`gp247_language_render(...)` — only the *values* in `Lang/vi/lang.php` and `Lang/en/lang.php` are
localized.

## When NOT to use

- Building an **admin feature plugin** — use `gp247-plugin-create`. A plugin can also add storefront
  content (a layout block, a hook on a shop page) without a template.
- Editing `gp247/core`, `gp247/front`, or `gp247/shop` themselves — this skill only creates a folder
  under `app/GP247/Templates/<Name>/`.
- Restyling **one page of the default template `GP247Front`** — that is
  `php artisan gp247:template-publish GP247Front --file=<path>` then editing the copy, not a new template.
- Only *explaining* how templates work with no intent to build one — answer directly or point at
  `gp247-docs/extension/create-template.md`.

## Input

The template's specification. The user supplies it in the prompt, or you ask for the missing pieces
before scaffolding — **never guess the design**. You need four answers:

1. **Name** — PascalCase, no spaces/accents (e.g. `MyShopSkin`, `AuroraStore`). Becomes both the folder
   name and the `configKey`; they must match and must not change after release.
2. **Selling site?** — whether the template must also carry the shop (`gp247/shop`). Step 0 tells you
   whether the shop is installed; this answer tells you whether the template should require it.
3. **Shop pages — restyle any?** — ask explicitly. By default the template ships **no** shop pages and
   `gp247/shop` serves its defaults inside the template's layout. Only if the user wants a custom look
   for specific pages (product list, cart, checkout…) do you override those — and only those.
4. **Site-owner-editable settings?** — any value the site owner may change from admin (colors, layout
   toggles, a banner text…).

If the user described the design but left one of these ambiguous, ask a short, specific question for
that one point only. Do not re-ask what they already stated.

## Workflow

Do the steps in order. Steps 0–5, 7 and 9 always run. Steps 6 and 8 depend on the **Input** answers.

0. **Probe the installation.** From the website root (where `artisan` lives), run the bundled read-only
   probe and keep its JSON for the rest of the task:

   ```bash
   php <this-skill-dir>/scripts/gp247-probe.php
   ```

   It prints the core version **exactly as the extension compatibility check reads it** (`core`), a
   ready `require_core` value, which packages are usable (`packages.<core|front|shop>.code` = code
   present, `.db` = installed in the database), the `gp247:*` commands, and a `capabilities` map.
   - `bootstrapped: false` → read `bootstrap_error`. If `core` is still set, continue but treat every
     capability as unknown and say so; if `core` is `null`, stop — not a GP247 site root.
   - `packages.front.db` not `true` → **stop**: a template cannot run without an installed `gp247/front`.
   - `packages.shop.db` not `true` → build the front pages only; skip every shop-related item below and
     do not require `gp247/shop`.
   - Do not take the version from composer (`composer show`, `installed.json`, or the `versions` block of
     `gp247:info`): it can disagree with the version the compatibility check uses.

1. **Confirm the spec.** Restate the four Input answers and the probe facts that matter (core version,
   shop usable) in a few lines — especially which shop pages (if any) will be overridden. This is the
   plan; get it straight before editing.

2. **Scaffold.**

   ```bash
   php artisan gp247:make-template --name=<Name> --download=0
   ```

   `--download=0` writes the template to `app/GP247/Templates/<Name>` (and its `public/` to
   `public/GP247/Templates/<Name>`); `--download=1` instead packages a `.zip` in `storage/tmp`. If
   `capabilities.json_output`, add `--json` for the envelope
   `{"ok":true,"command":"gp247:make-template","data":{"key":"<Name>","path":"...","msg":"Success"},"warnings":[],"error":null}`.
   Check the exit code (0 = success). If it errors, stop and surface the message — do not hand-create the
   folder.

3. **Fill `gp247.json`.**
   - `configKey` must equal the folder name; `configGroup` is `"Templates"`.
   - **`requireCore` = the probe's `require_core`** (the running `major.minor`). Each entry is a range —
     `"X.Y"` means `>= X.Y.0` and `< (X+1).0.0` — so write **one entry per major** you support. Lower the
     floor only after confirming every capability the template uses exists there. Replace whatever value
     the scaffolder wrote; it is a static default, not your site's version.
   - `requireComposerPackages` **must include `"gp247/front"`**; add `"gp247/shop"` for a selling
     template.
   - Start `version` and `requireUpdateFrom` at `"1.0"`. Field reference in
     `references/file-templates.md` §1.

4. **Build the required views — the template contract.** Every view below is looked up under
   `GP247TemplatePath::<Name>.<path>` (= `app/GP247/Templates/<Name>/<path>.blade.php`); `gp247/front`
   finds the folder by itself, register nothing. The scaffold's `Provider.php` points `loadViewsFrom` at a
   `Views/` folder that does not exist — leave it; template views are not loaded that way.

   | View | Why it is required |
   |---|---|
   | `layout` | Every page — including every shop page that falls back — `@extends` it. Must define the sections and stacks in `references/file-templates.md` §2 and render the layout-block positions (step 5). |
   | `screen/home`, `screen/page_detail`, `screen/front_search`, `screen/notfound` | Rendered by `gp247/front`; a missing one **stops the request** with "view not found". |
   | `screen/404` | Optional — without it a missing page is a bare HTTP 404. |
   | `common/item_single`, `common/pagination` | Included by the default `screen/front_search` (and by shop pages). Needed whenever your screens include them — keep them when you start from the default screens. |
   | `common/pagination_result`, `common/jsonld_product`, `common/jsonld_breadcrumb`, `common/render_form_custom_field` | **With `gp247/shop`:** the shop's fallback pages include these (and the two above) from the active template. |

   The fastest correct start is to copy these files from the default template's package folder
   (`vendor/gp247/front/src/Views/templates/GP247Front/` — `layout.blade.php`, `layout/`, `screen/`,
   `common/`) into `app/GP247/Templates/<Name>/` and then restyle them; they are the reference
   implementation of the contract. Copy **only** the files listed above (plus the `layout/` fragments
   your layout includes) — not `blocks/`, not the shop files. Then build the user's design: **Tailwind +
   Alpine + Livewire, no jQuery / AdminLTE / Bootstrap widgets** (flatpickr for dates). Render every string
   via `gp247_language_render(...)` / `trans('...')` and fill both `Lang/vi/lang.php` and
   `Lang/en/lang.php` — never hardcode display text.

5. **Layout blocks.** The site owner places content (banners, product grids, HTML, plugin blocks) into the
   storefront through admin Layout Block, by position. The layout must call
   `{!! gp247_render_block('<position>', $layout_page ?? null) !!}` for **every** position listed in the
   probe's `capabilities.front_layout_positions` — at the time of writing `header` (render it **inside
   `<head>`**: head code such as analytics), `top`, `left`, `center`, `right`, `bottom`, `footer`; trust
   the probe over this list. A position the layout does not render silently drops every block filed under
   it. A block of type "view" named `<x>` resolves to the template's `blocks/<x>` first,
   then to a plugin's block of the same name (if `capabilities.front_layout_block_views`) — so a block
   name your template ships overrides a plugin's. Seed the template's own default blocks in
   `AppConfig::setupStore()` and delete them in `removeStore()` (template in `references/file-templates.md`
   §5).

6. **(If it has site-owner-editable settings) wire update-safe settings.** A release overwrites
   `config.php`, so it holds **defaults only**; every editable value lives in `admin_config` (DB). Use the
   `<Name>_effective_config()` / `<Name>_save_config()` helper pair in `function.php` and read the effective
   values in the layout/pages (`references/file-templates.md` §3). No editable settings → skip.

7. **CSS — Tailwind is precompiled, not JIT.** The template ships its own compiled CSS; a class that was
   never built has no effect. Follow `references/file-templates.md` §4: source in
   `resources/assets/{css/app.css,js/app.js,tailwind.config.js}`, output in the template's
   `public/css/app.css` and `public/js/app.js`, loaded by the layout through
   `gp247_file($GP247TemplateFile.'/css/app.css')`. The Tailwind `content` globs must include the shop and
   front package views that render inside this template (fallback pages use their own classes), and the
   config must define the colour tokens those views use. Build with `npx tailwindcss … --minify`. If
   Node/network is unavailable, say so and flag the look as unverified rather than claiming it is done.

8. **(Only if the user wants to restyle shop pages — the fallback rule) override just those pages.**
   Copy the requested page from `vendor/gp247/shop/src/Views/templates/GP247Front/<sub-path>` to
   `app/GP247/Templates/<Name>/<sub-path>` (same sub-path, same file name) and restyle it there. The shop
   prefers your copy and keeps its default for every other page. Do **not** copy the whole set — every
   copied file stops receiving fixes from shop updates. Page table and the checkout caveat in
   `references/file-templates.md` §6.

9. **Verify.** Run `php artisan optimize:clear`, then:
   - **Install** — with the user's OK (it writes the DB), install in place (the folder is on disk:
     nothing is downloaded, no API License needed):

     ```bash
     php artisan gp247:ext-install --type=template --key=<Name>
     php artisan gp247:ext-list --type=template --json
     ```

     If you change files under the template's `public/` afterwards and `capabilities.ext_publish`, re-copy
     them with `php artisan gp247:ext-publish --type=template --key=<Name>`. To ship the theme to another
     site, zip the folder and install it there with `gp247:ext-install --type=template --file=<Name>.zip`.
   - **Activate** — tell the user to do it in admin → **System management → Website information**,
     **Template** field, then confirm. It is per store, and there is **no CLI command** for it. Switching
     runs the outgoing template's `removeStore()` and the incoming template's `setupStore()` — the default
     template deletes its own layout blocks and banners for that store there — so try it on a development
     site. Never switch a store's template yourself.
   - **Check** — home, a CMS page, search, a missing page; with the shop: a product list, product detail,
     cart and checkout (overridden pages show the new look, the rest the shop's default inside your
     layout). Check phone width and dark mode if supported, and that every layout-block position renders.

**Update-safety invariants** (hold across every step; details in `references/file-templates.md`):
`version` only ever increases and `configKey` never changes; editable settings live in the DB, not in
files; no user-uploaded files are stored inside the template folder (both `app/…` and `public/…` copies
are deleted and replaced on update); and `AppConfig::update(?string $fromVersion)` is **idempotent and
handles `null`**. Besides the 1-click update, when `capabilities.extension_data_updater` core also runs
`update()` after files changed by `git pull` / composer / manual copy (`gp247:update`,
`gp247:ext-update --type=template --key=<Name> --local`, or the admin template list's "apply data update"
action) — once with `$fromVersion = null` on a site with no recorded version.

**Secret settings must be encrypted at rest** (if `capabilities.secret_cast`). A template rarely needs a
credential, but if an option holds one — a third-party API secret or token — never store it as a plain
option: see `references/file-templates.md` §8. Public keys meant to appear in the page's HTML (e.g. a
Google Maps *browser* key) are **not** secrets — keep those as ordinary options. Never print a secret into
a Blade view or a log.

## Output format

Do not print a document. Apply the edits, then give a short English summary in this shape (mark each
line `[x]` done or `[ ]` skipped, and say *why* an optional step was skipped):

```
Created template <Name> (update-safe) on gp247/core <probe.core>:
- [x] Probed: core <core>, front usable, shop <usable/not>; missing capabilities: <none / list>
- [x] Scaffolded: php artisan gp247:make-template --name=<Name> --download=0
- [x] gp247.json → configKey <Name>, requireCore <probe.require_core>, requireComposerPackages [<list>], version 1.0
- [x] Required views: layout, screen/{home,page_detail,front_search,notfound,404}, common/{item_single,pagination} (+ <the four shop partials / none — no shop>)
- [x] Layout blocks: header (in <head>), top, left, center, right, bottom, footer; default blocks seeded in setupStore()
- [ ] Settings: <effective/save helpers wired / skipped — no editable settings>
- [ ] CSS: <built to public/css/app.css / UNVERIFIED — no Node>
- [ ] Shop overrides: <screen/shop_cart only / skipped — shop defaults inside the template layout>
- [x] Lang: vi + en strings added
- [ ] Installed: <php artisan gp247:ext-install --type=template --key=<Name> / skipped — user will install>
Next step: activate it in admin → System management → Website information (Template field) on a dev site, then test.
```

## Examples

The user may phrase the request in Vietnamese, Japanese, or English; you always respond in English.

**Example 1 — front-only theme, no shop customization**
Input: "Tạo template GP247 tên AuroraStore, trang chủ có banner lớn + lưới sản phẩm nổi bật, còn các
trang shop giữ mặc định." → Probe: front and shop usable. Scaffold `AuroraStore`; `requireCore` = probe
value, `requireComposerPackages: ["gp247/front","gp247/shop"]`; copy the contract views from the default
template's package folder and restyle the layout; build `screen/home` with the banner and a featured grid;
ship the six `common/` partials; render every block position; build CSS with the shop views in the
`content` globs. **Skip step 8** — product list/cart/checkout use the shop defaults inside AuroraStore's
layout.

**Example 2 — theme that restyles the cart only, with an editable accent color**
Input: "Create a template ModaSkin; I want a custom cart page and an admin-set accent color." → Confirm:
only `screen/shop_cart` is restyled; accent color is an editable setting. Scaffold; required views; wire
`ModaSkin_effective_config()` / `ModaSkin_save_config()` with default `{accent:'#4f46e5'}` in
`config.php`; read the accent in the layout; copy **only**
`vendor/gp247/shop/src/Views/templates/GP247Front/screen/shop_cart.blade.php` to
`app/GP247/Templates/ModaSkin/screen/shop_cart.blade.php` and restyle it; rebuild CSS.

**Example 3 — site without the shop**
Input: "Làm template blog/landing tên Lumen cho site chỉ có gp247/front." → Probe: front usable, shop not
installed. Required views with only `common/item_single` and `common/pagination` (search uses them);
`requireComposerPackages: ["gp247/front"]`; no shop step. Summary says the shop items were skipped because the shop is not installed.

## Common mistakes

| Mistake | Why it hurts / how to avoid |
| --- | --- |
| Copying a `requireCore` value from an example or old template | Each entry is a range ending before the next major; a value from another major makes core refuse to install. Use the probe's `require_core`. |
| Shipping only `home` and a layout | `page_detail`, `front_search` and `notfound` are required too — a missing one stops the request with "view not found". |
| Missing `common/*` partials | Search (and, on a selling site, every shop fallback page) includes them from the active template → "view not found". Ship the partials in step 4. |
| Rendering only some layout-block positions, or `header` outside `<head>` | Blocks filed under a missing position never appear; head-code blocks (analytics, verification tags) belong in `<head>`. |
| Tailwind `content` globs that cover only the template folder | Shop fallback pages render with their own classes, which then have no CSS. Include the package views; define the colour tokens they use. |
| Forgetting `requireComposerPackages: ["gp247/front"]` | A template only runs with the storefront present. |
| Copying the whole `gp247/shop` view set into the template | Those pages freeze against shop-package updates. Override only the pages the user asked for (step 8). |
| Letting the site owner edit `config.php` | 1-click update overwrites the file → their choices vanish. Editable values live in `admin_config` (step 6). |
| Hand-creating the template folder instead of scaffolding | Misses required files (AppConfig, Provider, gp247.json, Lang). Always run `gp247:make-template` first. |
| Changing `configKey` after release, or not bumping `version` | Update won't match releases / is refused as "not newer". |
| jQuery / AdminLTE / Bootstrap widgets in the theme | The storefront stack is Tailwind + Alpine + Livewire. |
| Hardcoding display text | Breaks i18n. Use `gp247_language_render(...)` / `trans(...)` and fill both vi + en lang files. |
| Overriding a shop page at the wrong sub-path/name | Fallback matches by exact sub-path; a wrong name means the default is used and your file is ignored. |
| `update()` that returns early on `$fromVersion === null` | Core calls it with `null` after git/composer updates on sites with no recorded version. Guard on the current state instead. |
| Storing uploads inside the template folder | The folder is deleted and replaced on update. Store user files under a shared area or `storage/`. |
| Forgetting `php artisan optimize:clear` | Storefront shows stale routes/views/config — the #1 support issue after building. |
| Telling the user to click "Activate" in the Templates list | There is no such button. A template is activated per store in admin → System management → Website information (Template field, with a confirmation). |
| Trying to activate with `ext-enable --type=template` or `gp247:template-setup` | Neither assigns the template to a store: `ext-enable` only enables the config row, `template-setup` only applies the default template to the root store. Activation is always done in admin. |

## Bundled resources

- `scripts/gp247-probe.php` — step 0. Read-only; prints one JSON object (core version, `require_core`,
  usable packages, `gp247:*` commands, capabilities). Identical copies ship with every gp247-* skill.
- `references/file-templates.md` — read at steps 3–8: `gp247.json` field reference, the layout contract
  (sections, stacks, block positions), settings helpers, the CSS build, block seeding in
  `setupStore()`/`removeStore()`, the shop-page override table, `update()`, the overwrite/preserve map,
  secrets.

---

## Skill info

| Field | Value |
| --- | --- |
| Lần cuối cập nhật / Last updated | `2026-10-03` |
| Skill repo | https://github.com/gp247net/gp247-skills |
| GP247 core repo | https://github.com/gp247net/core |
| source | https://github.com/gp247net/gp247-docs/blob/main/extension/create-template.md |
