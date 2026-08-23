# File templates & troubleshooting — GP247 plugin v1 → v2

Load this only at Workflow steps 5, 6, or 8, or when verifying (step 9). In every template, replace
`Extension_Key` with the plugin's `configKey` (PHP namespace segment) and `ExtensionUrlKey` with the
route-name prefix already used in `Route.php`. Source of truth:
`gp247-docs/extension/convert-plugin-v1-to-v2.md`.

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

**After (v2 — core 2.1):**

```json
{
    "version": "1.0",
    "requireCore": ["2.1"],
    "requireUpdateFrom": "1.0",
    "requireComposerPackages": [],
    "requireGp247Extensions": []
}
```

- `requireCore`: the core version the plugin targets — set to `["2.1"]`.
- `requireUpdateFrom`: minimum installed version allowed to 1-click update to this release. `"1.0"` is
  safe (practically no restriction); only raise it for a major release that cannot auto-migrate.
- `requireComposerPackages` / `requireGp247Extensions`: renamed from `requirePackages` / `requireExtensions`
  in core 2.1. Core 2.1 still reads the old keys (backward compatible) but they are deprecated — emit the new ones.

---

## Step 3 — minimal `Views/Admin.blade.php` after the layout change

```blade
@extends('gp247-admin::layouts.admin')

@section('main')
<div class="row">
      <div class="col-md-12">
            Your-content!
      </div>
</div>
@endsection

@push('styles')
      {{-- style css --}}
@endpush

@push('scripts')
      {{-- script --}}
@endpush
```

---

## Step 5 — Livewire admin screen (2 new files)

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

- Extending `GP247AdminComponent` gives the plugin automatic Layer-2 RBAC (permission check on open),
  toast notifications, and the shared admin layout — exactly like the core's own screens.
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

## Step 6 — Livewire route in `Route.php`

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

Keep the old controller route unchanged — the plugin then has both the old screen and the new Livewire screen.

---

## Step 7 — `AppConfig.php` `disable()`

**Before (v1):**

```php
if (!$process) {
    $return = ['error' => 1, 'msg' => 'Error disable'];
}
```

**After (v2):**

```php
if (!$process) {
    $return = ['error' => 1, 'msg' => gp247_language_render('admin.extension.action_error', ['action' => 'Disable'])];
}
```

Optionally change the top-of-file comment from `Plugin format 1.0` to `Plugin format 2.0`. The other
methods (`install`, `uninstall`, `enable`, `getInfo`…) stay unchanged.

---

## Step 8 — SEO sitemap (optional)

**`Seo.php`:**

```php
<?php

namespace App\GP247\Plugins\Extension_Key;

class Seo
{
    public static function sitemapUrls($storeId): array
    {
        // Return the plugin's public URLs for the sitemap.
        // Returning an empty array is safe when there are none.
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

## Step 8b — LayoutBlock page-type (optional)

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

## Step 8c — Total-method plugin at checkout (optional)

Only for a total-method plugin (`configCode: "Total"` — coupon/point) that needs a checkout input.
Contract: `GP247\Shop\Front\Contracts\CheckoutTotalMethod` (ADR-storefront-checkout-total-method-contract).

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

## Step 9 — verify

```bash
php artisan optimize:clear
```

Then open the plugin's admin screen (and the `/livewire` path if added), and enable/disable the plugin
to confirm `AppConfig.php` still runs.

---

## Troubleshooting Q&A

| Symptom / question | Answer |
| --- | --- |
| `View [gp247-core::layout] not found` | Step 3 not done — change `@extends('gp247-core::layout')` to `@extends('gp247-admin::layouts.admin')`. |
| Edited route/view but admin still shows the old version | Run `php artisan optimize:clear` to clear route/view/config cache, then reload. Most common issue. |
| Must I switch to Livewire? | No. A static admin screen only needs step 3. Livewire (steps 5–6) is only for dynamic interaction previously done with jQuery. |
| Must I rewrite Models/logic? | No. 2.0 keeps the 1.x schema and logic layer; only the UI + a few config files change. |
| What value for `requireUpdateFrom`? | `"1.0"` is safest. Raise it only when a major release's `update()` hook cannot migrate older lines. |
| `Seo.php` for every plugin? | No — only when the plugin has a public page contributing URLs to `sitemap.xml`. |
| Does the `Provider.php` sitemap block error without gp247/front? | No — it is wrapped in `class_exists('GP247\Front\Controllers\RootFrontController')` and simply skipped. |
| Must I register a LayoutBlock page-type? | Only if the plugin has its own public storefront page that admins should attach LayoutBlock blocks to (step 8b). Admin-only plugins skip it. |
| I attached a block in admin but it doesn't show on the plugin's page | The registered token must equal the `$layout_page` the controller passes to `view()`; a mismatch means the block never renders (step 8b). |
| Should a template (theme) register page-types too? | No — only a plugin with its own page registers page-types; a template just renders based on the `$layout_page` the controller emits. |
| Fastest way to get a fresh v2 plugin instead of editing? | `php artisan gp247:make-plugin --name=YourPluginName --download=0`, then copy the old logic in. |
