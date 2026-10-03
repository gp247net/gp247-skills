# file-templates — code templates for gp247-template-create

Read this when you reach steps 3–8 of `SKILL.md`, or when writing the `update()` hook. Replace every
`<Name>` with the template's `configKey` (folder name). All code and identifiers stay in English.
Features marked "if `capabilities.X`" refer to the JSON printed by `scripts/gp247-probe.php`.

---

## 1. `gp247.json` field reference (step 3)

The scaffolder emits this shape; edit the values, not the shape:

```json
{
    "name": "<Name> module",
    "image": "images/logo.jpg",
    "auth": "GP247",
    "email": "support@gp247.net",
    "link": "https://GP247.net",
    "configGroup": "Templates",
    "configCode": "<Name>",
    "configKey": "<Name>",
    "version": "1.0",
    "requireCore": ["<probe.require_core>"],
    "requireUpdateFrom": "1.0",
    "requireComposerPackages": ["gp247/front"],
    "requireGp247Extensions": []
}
```

| Field | Rule |
|---|---|
| `configGroup` | Always `"Templates"` for a template (a plugin uses `"Plugins"`). |
| `configKey` | Unique id; **must equal the folder name**; never change after release. |
| `configCode` | The same as `configKey`. |
| `version` | `1.0`, `1.1`, `2.0`… **Every release must be greater** than the installed one (compared with `version_compare`) or 1-click update refuses it. |
| `requireCore` | Core versions the template runs on. Each entry is a range: `"X.Y"` = `>= X.Y.0` and `< (X+1).0.0`; several entries are a union. Use the probe's `require_core` (the running `major.minor`), **one entry per supported major**. Core compares against `config('gp247.core')`, which has no patch number — never write a patch-level floor like `"3.0.3"`. |
| `requireComposerPackages` | **Must include `"gp247/front"`**. Add `"gp247/shop"` for a selling template. |
| `requireUpdateFrom` | Minimum installed version allowed to 1-click update to this release. `"1.0"` = practically no restriction. |
| `requireGp247Extensions` | Other GP247 extensions required first. Usually empty for a template. |

> `requireComposerPackages` / `requireGp247Extensions` replaced the older `requirePackages` /
> `requireExtensions`. Always emit the new keys; core still reads the old ones but logs a deprecation
> warning.

---

## 2. The template contract — views, layout sections, folder layout (steps 4–5)

Views resolve under `GP247TemplatePath::<Name>.<path>`: `screen/home.blade.php` in template `<Name>` is
`GP247TemplatePath::<Name>.screen.home`. `gp247/front` scans `app/GP247/Templates` automatically. Inside
views, `$GP247TemplatePath` holds `GP247TemplatePath::<Name>` and `$GP247TemplateFile` the template's public
asset path.

**Required views** (see the table in `SKILL.md` step 4). The list follows the packages, so confirm it on
the site before building — every hit names a view the active template must provide:

```bash
# views gp247/front renders under the active template
grep -rhoE "GP247TemplatePath *\. *'\.[a-z_.]+'" vendor/gp247/front/src/Controllers | sort -u
# partials the default front screens/layout include (needed if you start from them)
grep -rhoE "GP247TemplatePath\.'\.[a-z_.]+'" vendor/gp247/front/src/Views/templates/GP247Front/screen vendor/gp247/front/src/Views/templates/GP247Front/layout.blade.php | sort -u
# views the shop's fallback pages take from the active template
grep -rhoE "GP247TemplatePath\.'\.[a-z_.]+'" vendor/gp247/shop/src/Views/templates/GP247Front | sort -u
```

**Layout sections and stacks.** Shop fallback pages `@extends($GP247TemplatePath.'.layout')`, so the
layout must yield what they fill. The default template's `layout.blade.php` is the reference: it declares
each section with `@section(...) … @show`, which gives a default and lets a page replace it. Confirm what
the shop fills on the site with
`grep -rhoE "@(section|push)\('[a-z_]+'" vendor/gp247/shop/src/Views/templates/GP247Front | sort | uniq -c`.

| Name | Kind | Used by |
|---|---|---|
| `block_main_content_center` | section | the shop's pages — list, detail, cart, checkout, account (account pages go through the shop's own `account/shop_layout`, which fills it) |
| `block_main` | section | your own full-width screens (the default `home`, `page_detail`, `notfound`, `404` replace it); shop pages do not |
| `styles`, `scripts` | stack | page CSS/JS (`@push`) |
| `jsonld` | stack | product/breadcrumb structured data, inside `<head>` |
| `block_main_content_left` / `_right`, `breadcrumb`, `block_menu`, `block_top`, `block_bottom`, `block_footer` | section | your own screens — the default layout provides them; shop pages do not fill them |

**Folder layout** after this skill (the scaffold creates only the files marked *):

```
app/GP247/Templates/<Name>/
├── gp247.json *         # template info
├── AppConfig.php *      # install/uninstall/enable/disable/setupStore/removeStore/update
├── Provider.php *       # lang/config/function loading (leave the Views/ line as is)
├── Route.php *          # the template's own routes (if any)
├── config.php *         # DEFAULTS ONLY (overwritten on update)
├── function.php *       # template helpers (settings overlay helpers live here)
├── Lang/{en,vi}/lang.php *
├── blocks/ *            # views for layout blocks of type "view" (sample: banner_image)
├── public/ *            # css/js/images → copied to public/GP247/Templates/<Name> on install
├── layout.blade.php     # shell: <head> + header + sections + footer + block positions
├── layout/              # header/footer/menu fragments the layout includes
├── screen/              # home, page_detail, front_search, notfound, 404 (+ shop_* only when overriding)
├── common/              # pagination, pagination_result, item_single, jsonld_product, jsonld_breadcrumb, render_form_custom_field
├── gp247_components/    # optional: overrides of the shared storefront components
└── resources/assets/    # css/app.css, js/app.js, tailwind.config.js (CSS source, §4)
```

---

## 3. Update-safe settings helpers (step 6)

A 1-click update **overwrites every template file**, so `config.php` holds **defaults only**. Any value the
site owner edits lives in `admin_config` (the DB), which the update preserves. Runtime value =
`defaults (config.php) ⊕ override (DB)`.

`config.php` — defaults only:

```php
return [
    'settings' => [
        'accent' => '#4f46e5',
        'show_hero' => 1,
    ],
];
```

`function.php` — add the helper pair, renamed for `<Name>`:

```php
/**
 * Effective settings = defaults (config.php) overlaid with the site owner's overrides in the DB.
 *
 * @return array The merged settings actually in effect at runtime.
 */
function <Name>_effective_config()
{
    $defaults = (array) config('Templates/<Name>.settings', []);

    $row = \GP247\Core\Models\AdminConfig::where('group', 'Templates')
        ->where('key', '<Name>_config')
        ->first();
    $overrides = $row ? json_decode((string) $row->value, true) : null;

    return is_array($overrides) ? array_merge($defaults, $overrides) : $defaults;
}

/**
 * Persist the site owner's chosen settings into the DB (survives 1-click update).
 *
 * @param array $settings The settings to store as the override row.
 * @return void
 */
function <Name>_save_config(array $settings)
{
    \GP247\Core\Models\AdminConfig::updateOrCreate(
        ['group' => 'Templates', 'key' => '<Name>_config'],
        [
            'code' => '<Name>_config',
            'store_id' => GP247_STORE_ID_GLOBAL,
            'value' => json_encode($settings),
        ]
    );
}
```

- The layout/pages **read** effective values via `<Name>_effective_config()`; whatever admin UI edits them
  **writes** via `<Name>_save_config($validated)`. Delete the row in `uninstall()`.
- No editable settings → do not add these helpers and keep `config.php` minimal.
- A credential never goes in this JSON row — see §8.

---

## 4. CSS build (step 7)

The storefront uses **precompiled** Tailwind (v3 CLI). Keep the source next to the template and the
output in the template's `public/`:

```
app/GP247/Templates/<Name>/resources/assets/css/app.css          # @tailwind base; components; utilities; + your CSS
app/GP247/Templates/<Name>/resources/assets/js/app.js            # Alpine helpers, if any
app/GP247/Templates/<Name>/resources/assets/tailwind.config.js
app/GP247/Templates/<Name>/public/css/app.css                    # build output
app/GP247/Templates/<Name>/public/js/app.js                      # copied from resources
```

Start `tailwind.config.js` from the default template's
(`vendor/gp247/front/src/Views/templates/GP247Front/resources/assets/tailwind.config.js`) so the colour
tokens the shop's fallback pages use (e.g. `brand-*`) exist, then point `content` at this template **and**
at every view that renders inside it:

```js
content: [
    'app/GP247/Templates/<Name>/**/*.blade.php',
    'app/GP247/Templates/<Name>/resources/assets/js/**/*.js',
    'vendor/gp247/shop/src/Views/templates/GP247Front/**/*.blade.php',   // shop fallback pages (selling template)
    'vendor/gp247/front/src/Views/templates/GP247Front/gp247_components/*.blade.php', // shared storefront components
    'vendor/livewire/livewire/src/Features/SupportPagination/views/*.blade.php',
],
```

Build from the project root, then copy the JS:

```bash
npx tailwindcss -c app/GP247/Templates/<Name>/resources/assets/tailwind.config.js -i app/GP247/Templates/<Name>/resources/assets/css/app.css -o app/GP247/Templates/<Name>/public/css/app.css --minify
```

Load them in the layout with `{{ gp247_file($GP247TemplateFile.'/css/app.css') }}` and
`{{ gp247_file($GP247TemplateFile.'/js/app.js') }}`. After the template is installed, publish the changed
`public/` with `php artisan gp247:ext-publish --type=template --key=<Name>` (if `capabilities.ext_publish`).
Ship the built output inside the template's `public/` — the site that installs the template does not
build anything. Use logical properties (`ms-*`/`me-*`, `start`/`end`) rather than `left`/`right` so the
template works for right-to-left languages.

---

## 5. Default layout blocks — `setupStore()` / `removeStore()` (step 5)

When an admin assigns the template to a store, core calls the outgoing template's `removeStore($storeId)`
and then this template's `setupStore($storeId)`. The scaffold's `setupStore()` only writes the store's
`template` column; add the template's default blocks there and remove exactly those in `removeStore()`.
Tag every seeded row with the template key so removal touches nothing else:

```php
// At the top of AppConfig.php, next to the scaffold's other imports:
use GP247\Front\Models\FrontLayoutBlock;

// Inside the class:
public function setupStore($storeId = null)
{
    if (!$storeId) {
        return null;
    }
    AdminStore::where('id', $storeId)->update(['template' => $this->configKey]);

    FrontLayoutBlock::insert([[
        'id'       => (string) \Illuminate\Support\Str::uuid(),
        'name'     => 'Banner top ('.$this->configKey.')',
        'position' => 'top',           // one of capabilities.front_layout_positions
        'page'     => 'front_home',    // page scope
        'text'     => 'banner_image',  // view blocks/banner_image.blade.php
        'type'     => 'view',
        'sort'     => 10,
        'status'   => 1,
        'template' => $this->configKey,
        'store_id' => $storeId,
    ]]);
}

public function removeStore($storeId = null)
{
    FrontLayoutBlock::where('template', $this->configKey)
        ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
        ->delete();
}
```

`AppConfig` of the default template (`app/GP247/Templates/GP247Front/AppConfig.php` on any site) is the full
reference, including banners.

---

## 6. Shop-page override — the view-fallback mechanism (step 8)

When a customer opens a shop page, the shop resolves the view with `gp247_shop_process_view()`:

1. It tries the active template's view first — `GP247TemplatePath::<Name>.screen.shop_product_list`
   (file `app/GP247/Templates/<Name>/screen/shop_product_list.blade.php`).
2. If the template has that file → it is used (the template "overrode" the shop view).
3. If not → the shop default `gp247-shop-front::screen.shop_product_list`
   (`vendor/gp247/shop/src/Views/templates/GP247Front/screen/shop_product_list.blade.php`), which still
   extends **your** layout and includes **your** `common/` partials.

Copy the page you want, keeping the sub-path:

```
Copy:  vendor/gp247/shop/src/Views/templates/GP247Front/screen/shop_product_list.blade.php
To:    app/GP247/Templates/<Name>/screen/shop_product_list.blade.php
```

Overridable shop pages — list them on the site with
`ls vendor/gp247/shop/src/Views/templates/GP247Front/screen` (the shop adds pages over time):

| File in the template | Page |
|---|---|
| `screen/shop_product_list.blade.php` | Product list |
| `screen/shop_product_detail.blade.php` | Product detail |
| `screen/shop_item_list.blade.php` | Category / brand / supplier list |
| `screen/shop_cart.blade.php` | Cart |
| `screen/shop_checkout.blade.php` | Checkout |
| `screen/shop_order_success.blade.php` | Order success |
| `screen/shop_payment_request.blade.php` | Payment link page |
| `screen/shop_wishlist.blade.php` | Wishlist |
| `screen/shop_compare.blade.php` | Product compare |
| `screen/shop_search.blade.php` | Search |

The shop package has more overridable folders — `account/`, `auth/`, `blocks/`, `common/`, `email/`,
`livewire/` — mirror the same sub-path in the template. `partials/` is **not** overridable: the checkout
includes those by the shop's own namespace (`gp247-shop-front::partials.*`), so a copy in the template is
never used.

**Checkout and total-methods.** Coupon/points plugins render inside the shop's checkout through two
includes. Prefer **not** overriding the checkout. If you do override
`livewire/shop_checkout-wizard.blade.php`, keep at the confirm step:
`@include('gp247-shop-front::partials.checkout_total_methods')` and
`@include('gp247-shop-front::partials.order_totals')` — dropping them silently removes the coupon input
for every total-method plugin.

> Only copy pages you truly change. A copied page stops receiving fixes from shop updates. Never dump the
> whole tree with `vendor:publish --tag=gp247:shop-view-front` / `--tag=gp247:front-view` — those tags
> write into the default template's folder, not yours. `gp247:shop-view-admin` is the shop's admin
> screens — unrelated to templates.

---

## 7. `AppConfig::update(?string $fromVersion)` — the data hook

The scaffold's `update()` does nothing. Override it when a release adds default blocks, changes the stored
settings format, or adds data.

```php
/**
 * Bring stored data in line with this version of the files. Idempotent.
 *
 * @param string|null $fromVersion Version installed before this update; null = unknown.
 * @return array{error:int,msg:string}
 */
public function update(?string $fromVersion = null)
{
    try {
        // Settings key renamed in 1.1: "color" -> "accent". Checked on the stored data itself,
        // so a null version and a second run are both safe.
        $row = \GP247\Core\Models\AdminConfig::where('group', 'Templates')
            ->where('key', '<Name>_config')->first();
        if ($row) {
            $data = json_decode((string) $row->value, true) ?: [];
            if (array_key_exists('color', $data) && !array_key_exists('accent', $data)) {
                $data['accent'] = $data['color'];
                unset($data['color']);
                $row->value = json_encode($data);
                $row->save();
            }
        }

        return ['error' => 0, 'msg' => ''];
    } catch (\Throwable $e) {
        return ['error' => 1, 'msg' => $e->getMessage()];
    }
}
```

When it runs:
- **1-click update from the library** — after the new files replace the old ones. Returning
  `['error' => 1, ...]` or throwing restores the backed-up old version.
- **Files changed outside the library** (if `capabilities.extension_data_updater`) — `git pull`, composer
  or a manual copy, applied by `gp247:update`, `gp247:ext-update --type=template --key=<Name> --local`, or
  "apply data update" on the admin extension list. A site with no recorded version gets **one call with
  `null`**. This path has **no file rollback**: a failure only leaves the update pending so the site owner
  can retry.

Never skip on `null`; guard every step by checking the stored state, and make running it twice harmless.

---

## 8. Secret settings (if `capabilities.secret_cast`)

A template usually stores display options only. If an option holds a **credential** (a third-party API
secret or token):

- Store it as its own `admin_config` row with `security = 1` (never inside the §3 JSON row) and, if the
  template has a settings screen built on `ConfigForm`, declare the field `password` in `fieldTypes()`.
  Core masks it and encrypts it at rest; read it with `gp247_config('<Name>_<setting>')`, which returns
  plaintext.
- **Public/browser keys are not secrets** — a value meant to appear in the page HTML (Google Maps browser
  key, a public site tag) stays an ordinary option.
- **A secret column in a table the template owns**: cast it with
  `protected $casts = ['col' => \GP247\Core\Casts\Secret::class]`, make the column TEXT, and register
  `table => [cols]` into `config('gp247-config.security.encrypted_columns')` in `Provider.php` so
  `gp247:doctor` / `gp247:encryption-key-rotate` cover it. Encrypted columns are not searchable.

Do not roll your own encryption. Operator guide: gp247-docs `system/data-encryption.md`.

---

## 9. Where update overwrites vs preserves (the mental model)

| Location | On update |
|---|---|
| `app/GP247/Templates/<Name>/…` (all blades, `config.php`, PHP) | **Deleted and replaced** with the new release. |
| `public/GP247/Templates/<Name>/…` (the template's css/js/images) | **Deleted and replaced**. |
| `admin_config` rows (settings, install flags), store assignment, layout blocks | **Preserved**. |
| User-uploaded files | Must **not** live inside the template folder — store under a shared area or `storage/`, or they are lost on update. |
