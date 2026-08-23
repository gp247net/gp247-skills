# file-templates — code templates for gp247-template-create

Read this when you reach steps 3–6 of `SKILL.md`, or when writing the `update()` migration hook. Replace
every `<Name>` with the template's `configKey` (folder name). All code and identifiers stay in English.

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
    "requireCore": ["2.1"],
    "requireUpdateFrom": "1.0",
    "requireComposerPackages": ["gp247/front"],
    "requireGp247Extensions": []
}
```

| Field | Rule |
|---|---|
| `configGroup` | Always `"Templates"` for a template (a plugin uses `"Plugins"`). |
| `configKey` | Unique id; **must equal the folder name**; never change after release. |
| `configCode` | Usually the same as `configKey`. |
| `version` | Semver-ish (`1.0`, `1.1`, `2.0`). **Every release must be greater** than the installed one (compared with `version_compare`) or 1-click update refuses it. |
| `requireCore` | `["2.1"]` for the v2 standard. |
| `requireComposerPackages` | **Must include `"gp247/front"`** — a template only runs with the storefront. Add `"gp247/shop"` if the theme is only meant for selling sites. |
| `requireUpdateFrom` | Minimum installed version allowed to 1-click update to this release. `"1.0"` = practically no restriction. |
| `requireGp247Extensions` | Other GP247 extensions required first. Usually empty for a template. |

> `requireComposerPackages`/`requireGp247Extensions` are the gp247/core 2.1 names (renamed from `requirePackages`/`requireExtensions`). Always emit the new keys; core 2.1 still reads the old ones for backward compatibility but they are deprecated.

---

## 2. View namespace & folder layout (step 5)

Template views resolve under `GP247TemplatePath::<Name>.<path>`. Example: `screen/home.blade.php` in
template `<Name>` is referenced as `GP247TemplatePath::<Name>.screen.home`. You register nothing —
`gp247/front` scans `app/GP247/Templates` automatically.

```
app/GP247/Templates/<Name>/
├── gp247.json          # declares template info (requireComposerPackages: ["gp247/front"])
├── AppConfig.php        # lifecycle: install/uninstall/enable/disable/setupStore/update
├── config.php           # DEFAULTS ONLY (overwritten on update)
├── function.php         # template helpers (config overlay helpers live here)
├── Provider.php         # registers views/lang/config (scaffolded, usually left as-is)
├── Route.php            # template's own routes (if any)
├── Lang/{en,vi}/lang.php
├── public/              # css/js/images → copied to public/GP247/Templates/<Name> on install
├── layout.blade.php     # overall shell: <head> + header + content + footer
├── layout/              # header/footer/menu fragments
├── screen/              # home.blade.php, page_detail.blade.php, 404.blade.php, front_search.blade.php
│                        #   (+ shop_*.blade.php ONLY when overriding shop pages — section 4)
├── partials/            # reusable fragments
├── components/          # template's own Blade components
└── livewire/            # views for Livewire components (cart, product filter…)
```

`AppConfig.php` for a template additionally uses `setupStore($storeId)` to assign the template to a
store (it updates the `template` column of `AdminStore`). The scaffolded stub already works.

---

## 3. Update-safe config helpers (step 4)

Because a 1-click update **overwrites every template file**, `config.php` can hold **defaults only**. Any
value the site owner edits must live in `admin_config` (the DB), which the update preserves. Runtime
value = `defaults (config.php) ⊕ override (DB)`.

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
 * Effective config = defaults (config.php) overlaid with the site owner's overrides in the DB.
 *
 * @return array The merged settings actually in effect at runtime.
 *
 * @aidlc-unit template-config
 * @aidlc-story US-template-create
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
 *
 * @aidlc-unit template-config
 * @aidlc-story US-template-create
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

- The layout/pages **read** effective values via `<Name>_effective_config()`; whatever admin UI edits
  them **writes** via `<Name>_save_config($validated)`.
- If the template has **no** editable settings, do not add these helpers and keep `config.php` minimal.

---

## 4. Shop-page override — the view-fallback mechanism (step 6)

A template needs **no** `gp247/shop` pages. When a customer opens a shop page, the shop controller
resolves the view through `gp247_shop_process_view()`:

1. It tries the active template's view first — `GP247TemplatePath::<Name>.screen.shop_product_list`
   (file `app/GP247/Templates/<Name>/screen/shop_product_list.blade.php`).
2. If the template has that file → it is used (the template "overrode" the shop view).
3. If not → it falls back to the shop default `gp247-shop-front::screen.shop_product_list`
   (`vendor/gp247/shop/src/Views/front/screen/shop_product_list.blade.php`).

So a template with no shop page still runs the shop normally. Override a page **only** to change its
look. Publish the shop defaults as a reference, then copy **only** the wanted pages:

```bash
php artisan vendor:publish --tag=gp247:shop-view-front
```

This copies all shop front views into `app/GP247/Templates/GP247Front` (the command's fixed
destination). Copy the page you want, keeping the sub-path, e.g.:

```
Copy:  app/GP247/Templates/GP247Front/screen/shop_product_list.blade.php
To:    app/GP247/Templates/<Name>/screen/shop_product_list.blade.php
```

Overridable shop pages (place under the template's `screen/`):

| File in the template | Page |
|---|---|
| `screen/shop_product_list.blade.php` | Product list |
| `screen/shop_product_detail.blade.php` | Product detail |
| `screen/shop_cart.blade.php` | Cart |
| `screen/shop_checkout.blade.php` | Checkout |
| `screen/shop_wishlist.blade.php` | Wishlist |
| `screen/shop_compare.blade.php` | Product compare |
| `screen/shop_search.blade.php` | Search |
| `screen/shop_order_success.blade.php` | Order success |

Besides `screen/`, the shop package has other overridable folders (`account/`, `auth/`, `blocks/`,
`common/`, `livewire/`) — mirror the same sub-path in the template.

> `gp247:shop-view-front` = **storefront** views (what a template needs). `gp247:shop-view-admin` = the
> shop's **admin** screens — unrelated to templates; do not confuse them.

> Only copy pages you truly change. Copying everything and leaving it untouched creates maintenance
> burden and freezes those pages against shop-package updates.

---

## 5. `AppConfig::update($fromVersion)` — idempotent migration hook

By default `update()` returns success and does nothing (fine for a look-only release). Override it only
when a new release **changes the config format or stored data**. Guard each step with
`version_compare($fromVersion, ...)` and make it safe to run more than once.

```php
/**
 * Migrate template data after files were replaced by a newer version.
 *
 * @param string|null $fromVersion Version installed before this update.
 * @return array{error:int,msg:string}
 *
 * @aidlc-unit template-lifecycle
 * @aidlc-story US-template-create
 */
public function update(?string $fromVersion = null)
{
    // Example: releases >= 1.1 renamed the stored config key "color" -> "accent".
    if ($fromVersion !== null && version_compare($fromVersion, '1.1', '<')) {
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
    }

    return ['error' => 0, 'msg' => ''];
}
```

- Returning `['error' => 1, ...]` or throwing makes the system **roll back** to the backed-up old
  version — fail loudly when a migration is unsafe rather than leaving data half-migrated.
- Running the hook twice must not error or lose data (idempotent).

---

## 6. Where update overwrites vs preserves (the mental model)

| Location | On 1-click update |
|---|---|
| `app/GP247/Templates/<Name>/…` (all blades, `config.php`, PHP) | **Deleted and replaced** with the new release. |
| `public/GP247/Templates/<Name>/…` (the template's css/js/images) | **Deleted and replaced**. |
| `admin_config` rows (the `<Name>_config` override, install flags, store assignment) | **Preserved**. |
| User-uploaded files | Must **not** live inside the template folder — store under a shared area or `storage/`, or they are lost on update. |
