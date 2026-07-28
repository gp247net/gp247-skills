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

**After (v2):**

```json
{
    "version": "1.0",
    "requireCore": ["2.0"],
    "requireUpdateFrom": "1.0",
    "requirePackages": [],
    "requireExtensions": []
}
```

- `requireCore`: the core version the plugin targets — set to `["2.0"]`.
- `requireUpdateFrom`: minimum installed version allowed to 1-click update to this release. `"1.0"` is
  safe (practically no restriction); only raise it for a major release that cannot auto-migrate.

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
| Fastest way to get a fresh v2 plugin instead of editing? | `php artisan gp247:make-plugin --name=YourPluginName --download=0`, then copy the old logic in. |
