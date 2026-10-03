# File templates & troubleshooting — GP247 plugin v1 → v2

Load this only at the Workflow step that needs it, or when verifying. In every template, replace
`Extension_Key` with the plugin's `configKey` (PHP namespace segment) and `ExtensionUrlKey` with the
route-name prefix already used in `Route.php`. Features marked "if `capabilities.X`" refer to the JSON
printed by `scripts/gp247-probe.php`. Background: `gp247-docs/extension/convert-plugin-v1-to-v2.md`.

---

## Step 2 — `gp247.json` before / after

**Before (v1):**

```json
{
    "version": "1.0",
    "requireCore": ["1.2"],
    "requirePackages": [],
    "requireExtensions": []
}
```

**After (v2 format):**

```json
{
    "version": "1.0",
    "requireCore": ["<probe.require_core>"],
    "requireUpdateFrom": "1.0",
    "requireComposerPackages": [],
    "requireGp247Extensions": [],
    "requireLivewire": false
}
```

- `requireCore`: core versions the plugin runs on. Each entry is a range — `"X.Y"` = `>= X.Y.0` and
  `< (X+1).0.0`; several entries are a union. Use the probe's `require_core` (the running `major.minor`),
  one entry per supported major. Core compares against `config('gp247.core')`, which has no patch number,
  so never write a patch-level floor such as `"3.0.3"`.
- `requireLivewire`: keep `false` — the admin shell already ships Livewire. A Livewire screen only needs
  its namespace registered in `Provider.php` (step 6).
- `requireUpdateFrom`: minimum installed version allowed to 1-click update to this release. `"1.0"` is
  safe (practically no restriction); only raise it for a major release that cannot auto-migrate.
- `requireComposerPackages` / `requireGp247Extensions`: renamed from `requirePackages` / `requireExtensions`
  (in core 2.1). Core still reads the old keys but logs a deprecation warning — emit the new ones.
- `configCode`: keep the plugin's role — `"Payment"` / `"Shipping"` / `"Promotion"` (older `"Total"`) for
  checkout plugins, otherwise the same as `configKey`.

---

## Step 3 — minimal `Views/Admin.blade.php` after the layout change

```blade
@extends('gp247-admin::layouts.admin')

@section('main')
    {{-- Existing content first; replace Bootstrap grid/markup (row, col-md-*, box, btn-*) with
         Tailwind utilities or <x-gp247::*> components as you touch it. --}}
    <x-gp247::card :title="trans('Plugins/Extension_Key::lang.title')">
        Your-content!
    </x-gp247::card>
@endsection

@push('styles')
      {{-- style css --}}
@endpush

@push('scripts')
      {{-- script --}}
@endpush
```

---

## Step 4 — `AppConfig.php` `enable()` / `disable()`

**Before (v1):**

```php
if (!$process) {
    $return = ['error' => 1, 'msg' => 'Error disable'];
}
$return = ['error' => 0, 'msg' => ''];   // a later line like this silently overwrites the error
```

**After:**

```php
if (!$process) {
    return ['error' => 1, 'msg' => gp247_language_render('admin.extension.action_error', ['action' => 'Disable'])];
}
// ... status updates ...
return ['error' => 0, 'msg' => gp247_language_render('admin.extension.disable_success')];
```

Same for `enable()` with `'action' => 'Enable'` and `admin.extension.enable_success`.

---

## Step 6 — Livewire admin screen (2 new files)

**`Livewire/AdminLivewire.php`:**

```php
<?php
#App\GP247\Plugins\Extension_Key\Livewire\AdminLivewire.php

namespace App\GP247\Plugins\Extension_Key\Livewire;

use GP247\Core\AdminShell\Infrastructure\GP247AdminComponent;

class AdminLivewire extends GP247AdminComponent
{
    protected ?string $permission = null;

    public function render()
    {
        return view('Plugins/Extension_Key::livewire')
            ->layout('gp247-admin::layouts.admin', [
                'title' => trans('Plugins/Extension_Key::lang.title'),
            ]);
    }
}
```

- Extending `GP247AdminComponent` gives the plugin the admin permission check on open, toast
  notifications, and the shared admin layout — exactly like the core's own screens.
- Register the component namespace in `Provider.php`, inside the `gp247_extension_check_active(...)` block:
  `if (class_exists(\Livewire\Livewire::class)) { \Livewire\Livewire::addNamespace('Extension_Key', classNamespace: 'App\\GP247\\Plugins\\Extension_Key\\Livewire'); }`
- `$permission = null` means the permission is inferred automatically from the component name.

**`Views/livewire.blade.php`:**

```blade
<div class="space-y-5">
    <x-gp247::card :title="trans('Plugins/Extension_Key::lang.title')">
        <p class="text-sm text-gray-600 dark:text-gray-300">
            {{ trans('Plugins/Extension_Key::lang.title') }} — Your content here!
        </p>
    </x-gp247::card>
</div>
```

- Prefer existing `<x-gp247::*>` shared components over raw HTML.
- Keep dark-mode classes (`dark:text-gray-300`) so the UI works in both themes.

---

## Step 6 (cont.) — Livewire route in `Route.php`

**Before (v1):**

```php
function () {
    Route::get('/', 'AdminController@index')
    ->name('admin_ExtensionUrlKey.index');
}
```

**After (v2):**

```php
function () {
    Route::get('/', 'AdminController@index')
    ->name('admin_ExtensionUrlKey.index');

    // Livewire route, registered alongside the legacy controller so old plugins keep working
    if (class_exists(\App\GP247\Plugins\Extension_Key\Livewire\AdminLivewire::class)) {
        Route::get('/livewire', \App\GP247\Plugins\Extension_Key\Livewire\AdminLivewire::class)
        ->name('admin_ExtensionUrlKey.livewire');
    }
}
```

Keep the old controller route while the Livewire screen is incomplete — the plugin then has both screens.
When the Livewire screen covers everything, point `/` (`admin_ExtensionUrlKey.index`) at the Livewire class
and remove the legacy controller and its view.

---

## Step 7 — Settings in `admin_config` (survive 1-click update)

`config.php` keeps **defaults only**; every value the site owner edits lives in `admin_config`.

**One row per setting** (recommended; required for a credential). In `AppConfig.php`:

```php
private function seedRows(): array
{
    $rows = [[
        'group' => $this->configGroup, 'code' => $this->configCode, 'key' => $this->configKey,
        'sort' => 0, 'store_id' => GP247_STORE_ID_GLOBAL, 'value' => self::ON, 'security' => 0,
        'detail' => $this->appPath.'::lang.title',
    ]];
    foreach (['enabled' => ['0', 0], 'api_key' => ['', 1]] as $key => [$default, $security]) {
        $rows[] = [
            'group' => $this->configGroup, 'code' => $this->configKey.'_config',
            'key' => $this->configKey.'_'.$key, 'sort' => count($rows),
            'store_id' => GP247_STORE_ID_GLOBAL, 'value' => $default, 'security' => $security,
            'detail' => $this->appPath.'::lang.'.$key,
        ];
    }

    return $rows;
}
```

`install()` inserts `seedRows()` (every row has the same columns, or MySQL rejects the batch); `update()`
inserts only the rows that are missing — never overwrite a saved value; `uninstall()` deletes by key
prefix as well as by code:

```php
(new AdminConfig)->where('group', $this->configGroup)->where(function ($q) {
    $q->where('key', $this->configKey)
      ->orWhere('key', 'like', $this->configKey.'\_%')
      ->orWhere('code', $this->configKey.'_config');
})->delete();
```

The admin screen can then extend `GP247\Core\AdminShell\Infrastructure\ConfigForm` (if
`capabilities.config_form`): implement `group()` (`'Plugins'`), `heading()`, `keys()` (this plugin's keys —
without it the form lists every plugin's rows) and `fieldTypes()` (`'bool'`, `'select'`, `'password'`…).
A `password` field on a `security = 1` row is masked and encrypted at rest (if `capabilities.secret_cast`);
read it with `gp247_config('Extension_Key_api_key')`, which returns plaintext. When `gp247.json` has
`"storeScope": "store"`, read settings with `gp247_config($key, gp247_plugin_store_id())` and return `true`
from `storeScoped()`.

**One JSON row** (structured settings only, never a credential): defaults in `config.php` under
`'settings'`, the overrides as JSON in the `admin_config` row `Extension_Key_config`, merged at runtime with
`array_merge($defaults, json_decode($row->value, true) ?: [])`.

A secret column in the plugin's **own** table: cast it with `\GP247\Core\Casts\Secret::class`, make it
TEXT, and register it in `Provider.php`:
`config(['gp247-config.security.encrypted_columns.<table>' => ['col']]);` — `<table>` **without** the
GP247 prefix (doctor and key rotation add `GP247_DB_PREFIX` themselves), so the table itself must be
created as `GP247_DB_PREFIX.'<table>'`.

---

## Step 8 — Data hook `update(?string $fromVersion)` and the lifecycle

```php
public function update(?string $fromVersion = null)
{
    try {
        foreach ($this->seedRows() as $row) {             // settings rows a later version added
            $exists = AdminConfig::where('group', $row['group'])->where('key', $row['key'])
                ->where('store_id', (string) $row['store_id'])->exists();
            if (!$exists) {
                AdminConfig::insert($row);
            }
        }
        $t = GP247_DB_PREFIX.'<table>';                    // keep the plugin's existing table naming
        if (\Illuminate\Support\Facades\Schema::hasTable($t)
            && !\Illuminate\Support\Facades\Schema::hasColumn($t, 'sort')) {
            \Illuminate\Support\Facades\Schema::table($t, fn ($table) => $table->integer('sort')->default(0));
        }

        return ['error' => 0, 'msg' => ''];
    } catch (\Throwable $e) {
        return ['error' => 1, 'msg' => $e->getMessage()];
    }
}
```

- Guard every step on the **current state** (`hasTable`, `hasColumn`, row exists); treat `null` as "oldest".
- A library update restores the old files if `update()` fails. The "apply data update" path after
  `git pull` / composer (if `capabilities.extension_data_updater`) has **no** file rollback — a failure only
  leaves the update pending.
- **Migration files and the ledger trap:** if the plugin creates tables with Laravel migration files run
  from `install()`, `uninstall()` must also delete the plugin's own rows from the shared `migrations` table
  (match its file names in `DB/migrations`), and every `up()` must be `Schema::hasTable()`-guarded —
  otherwise a reinstall reports "Nothing to migrate" and the dropped table never comes back. Simpler:
  create the tables with `Schema::create()` in `Models/ExtensionModel::installExtension()` (guarded by
  `hasTable`) and drop them in `uninstallExtension()`.

---

## Step 9 — SEO sitemap (optional)

**`Seo.php`:**

```php
<?php

namespace App\GP247\Plugins\Extension_Key;

class Seo
{
    public static function sitemapUrls($storeId): array
    {
        // Return the plugin's public URLs for the sitemap, e.g.
        // [['loc' => url(...), 'alias' => $item->alias], ...]
        // `loc` is required (entries without it are dropped); `alias` lets the site owner's
        // sitemap exclusions apply. Returning an empty array is safe when there are none.
        return [];
    }
}
```

**`Provider.php`** — add inside the `if (gp247_extension_check_active(...))` section:

```php
if (class_exists('GP247\Front\Controllers\RootFrontController')) {
    $sitemapProviders = config('gp247-config.front.seo_sitemap_providers', []);
    $sitemapProviders[] = [
        'key' => $config['configKey'],
        'label' => $config['name'],
        'callback' => [\App\GP247\Plugins\Extension_Key\Seo::class, 'sitemapUrls'],
    ];
    config(['gp247-config.front.seo_sitemap_providers' => $sitemapProviders]);
}
```

The `class_exists` guard means the plugin still installs normally when `gp247/front` is absent.

---

## Step 10 — LayoutBlock page-type (optional)

Do this only when the plugin has its **own public storefront page** (e.g. a list/detail page) and
admins should be able to attach LayoutBlock blocks (banner, HTML, view…) to it. The admin "Layout
block" screen only lists page-types registered into `config('gp247-config.front.layout_page')`.

**1. Controller — emit the page-type token** when rendering the public page:

```php
return view($view, [
    // ... other data ...
    'layout_page' => 'myplugin_index',   // this page's page-type token
]);
```

**2. `Provider.php`** — add inside the `if (gp247_extension_check_active(...))` section:

```php
if (class_exists('GP247\Front\Controllers\RootFrontController')) {
    $layoutPage = config('gp247-config.front.layout_page', []);
    // Store the i18n KEY (NOT a pre-rendered string) — the admin renders it in its current locale.
    $layoutPage['myplugin_index'] = $extensionPath.'::lang.layout_block_page.myplugin_index';
    config(['gp247-config.front.layout_page' => $layoutPage]);
}
```

- The token (`myplugin_index`) **must match** the `$layout_page` value the controller emits, otherwise
  a block selected for it will never display.
- The value is a **language key** (pointing to the plugin's `Lang` files), not a pre-translated
  string — so the admin dropdown renders it in the viewer's current locale.
- The block is guarded by `class_exists`, so a website without `gp247/front` simply skips it and the
  plugin still installs normally.

**3. `Lang/en/lang.php` and `Lang/vi/lang.php`** — add the matching line to the `layout_block_page`
array, e.g. `'myplugin_index' => 'Plugin listing page'`.

Reference: the `News` plugin (`app/GP247/Plugins/News/Provider.php`) registers
`news_index`/`news_category`/`news_detail` this exact way. Note: a *template*/theme does **not**
register page-types — it only renders based on the `$layout_page` the controller already emits.

---

## Step 11 — Storefront content without template files (optional)

Both registrations go in `Provider.php`, inside the `gp247_extension_check_active(...)` block and behind
`class_exists('GP247\Front\Controllers\RootFrontController')`.

**Layout block** (if `capabilities.front_layout_block_views`) — appears in admin Layout Block for every
template; a template's own `blocks/<name>` file of the same name wins, so prefix the name with the plugin:

```php
$blockViews = config('gp247-config.front.layout_block_views', []);
$blockViews['extension_key_block'] = $extensionPath.'::blocks.extension_key_block';
config(['gp247-config.front.layout_block_views' => $blockViews]);
```

**Plugin hook** (if `capabilities.front_plugin_hooks`) — output at a fixed spot of a shop page (grep the
templates for `gp247_render_plugin_hook(` to list the hooks and the data each passes):

```php
$hooks = config('gp247-config.front.plugin_hooks', []);
$hooks['shop_product_detail_bottom'][] = [
    'callback' => fn (array $data) => view($extensionPath.'::hooks.product_bottom', $data)->render(),
];
config(['gp247-config.front.plugin_hooks' => $hooks]);
```

Then remove the view files the 1.x plugin copied into template folders, and stop copying them in
`install()`.

---

## Step 12 — Total-method plugin at checkout (optional)

Only for a total-method plugin (`configCode: "Promotion"` — coupon/point; legacy `"Total"` still accepted) that needs a checkout input.
Contract: `GP247\Shop\Front\Contracts\CheckoutTotalMethod` (if `capabilities.shop_checkout_total_method`).

**`AppConfig.php`** — implement the interface, reusing the plugin's own validation/session logic:

```php
use GP247\Shop\Front\Contracts\CheckoutTotalMethod;

class AppConfig extends ExtensionConfigDefault implements CheckoutTotalMethod
{
    // …existing methods unchanged…

    public function checkoutApply(array $payload): array
    {
        $code = trim((string) ($payload['code'] ?? ''));
        if ($code === '') {
            return ['error' => 1, 'msg' => gp247_language_render('cart.coupon_empty')];
        }
        // reuse the plugin's existing validation (e.g. FrontController::check)
        $check = (new \App\GP247\Plugins\Extension_Key\Controllers\FrontController)->check($code, customer()->id ?? 0);
        if (!empty($check['error'])) {
            return ['error' => 1, 'msg' => $check['msg']];
        }
        $totalMethod = session('totalMethod', []);
        $totalMethod[$this->configKey] = $code;
        session(['totalMethod' => $totalMethod]);
        return ['error' => 0, 'msg' => gp247_language_render($this->appPath.'::lang.process.completed')];
    }

    public function checkoutRemove(): void
    {
        $totalMethod = session('totalMethod', []);
        unset($totalMethod[$this->configKey]);
        session(['totalMethod' => $totalMethod]);
    }

    public function checkoutView(): ?string
    {
        return $this->appPath.'::checkout';   // Views/checkout.blade.php
    }
}
```

**`Views/checkout.blade.php`** — rendered INSIDE the checkout Livewire component; bind with `wire:`
(no jQuery/fetch) and use only storefront UI tokens the active template already ships:

```blade
@php($appliedCode = session('totalMethod')[$pluginKey] ?? null)
<div class="card p-5" wire:key="total-method-{{ $pluginKey }}">
    <label class="block text-sm font-medium text-ink-700 mb-2">{{ gp247_language_render('cart.coupon') }}</label>
    @if ($appliedCode)
        <div class="flex items-center justify-between gap-3">
            <span class="text-sm font-semibold text-emerald-600">{{ $appliedCode }}</span>
            <button type="button" class="btn-ghost text-red-500" wire:click="removeTotal('{{ $pluginKey }}')">{{ gp247_language_render('cart.remove_coupon') }}</button>
        </div>
    @else
        <div class="flex items-center gap-2">
            <input type="text" class="input flex-1" wire:model="totalPayload.{{ $pluginKey }}.code" wire:keydown.enter.prevent="applyTotal('{{ $pluginKey }}')">
            <button type="button" class="btn-primary" wire:click="applyTotal('{{ $pluginKey }}')" wire:loading.attr="disabled">{{ gp247_language_render('cart.apply') }}</button>
        </div>
    @endif
    @if (!empty($message) && !empty($message['msg']))
        <p class="mt-2 text-sm {{ empty($message['error']) ? 'text-emerald-600' : 'text-red-500' }}">{{ $message['msg'] }}</p>
    @endif
</div>
```

- Variables passed by the zone partial: `$pluginKey`, `$plugin` (getInfo array), `$message`.
- The wizard exposes `totalPayload` / `totalMessages` state and `applyTotal($key)` / `removeTotal($key)` actions.
- Reference implementation: the `ShopDiscount` plugin.

**Template authors only** (not part of the plugin): a custom checkout view supports every total-method
plugin by adding two includes at the confirm step —
`@include('gp247-shop-front::partials.checkout_total_methods')` and
`@include('gp247-shop-front::partials.order_totals')`.

---

## Step 13 — verify

```bash
php artisan optimize:clear
```

Then run the lifecycle from `SKILL.md` step 13 (always `ext-uninstall --only-data`), open the plugin's
admin screen (and the `/livewire` path if added), and enable/disable the plugin to confirm `AppConfig.php`
returns the right messages.

---

## Troubleshooting Q&A

| Symptom / question | Answer |
| --- | --- |
| `View [gp247-core::layout] not found` | Step 3 not done — change `@extends('gp247-core::layout')` to `@extends('gp247-admin::layouts.admin')`. |
| Edited route/view but admin still shows the old version | Run `php artisan optimize:clear` to clear route/view/config cache, then reload. Most common issue. |
| Must I switch to Livewire? | No. A static admin screen only needs step 3. Livewire (steps 5–6) is only for dynamic interaction previously done with jQuery. |
| Must I rewrite Models/logic? | Not the business logic. But settings kept in `config.php` must move to `admin_config` (step 7), and `install`/`uninstall`/`update` must be re-runnable (step 8). |
| Install says "not compatible" | `requireCore` does not cover the running core. Set it to the probe's `require_core` (step 2). |
| What value for `requireUpdateFrom`? | `"1.0"` is safest. Raise it only when a major release's `update()` hook cannot migrate older lines. |
| `Seo.php` for every plugin? | No — only when the plugin has a public page contributing URLs to `sitemap.xml`. |
| Does the `Provider.php` sitemap block error without gp247/front? | No — it is wrapped in `class_exists('GP247\Front\Controllers\RootFrontController')` and simply skipped. |
| Must I register a LayoutBlock page-type? | Only if the plugin has its own public storefront page that admins should attach LayoutBlock blocks to (step 10). Admin-only plugins skip it. |
| I attached a block in admin but it doesn't show on the plugin's page | The registered token must equal the `$layout_page` the controller passes to `view()`; a mismatch means the block never renders (step 10). |
| Should a template (theme) register page-types too? | No — only a plugin with its own page registers page-types; a template just renders based on the `$layout_page` the controller emits. |
| Fastest way to get a fresh v2 plugin instead of editing? | Use the `gp247-plugin-create` skill (it runs `gp247:make-plugin`), then copy the old logic in. |
