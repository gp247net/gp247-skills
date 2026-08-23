# file-templates — code templates for gp247-plugin-create

Read this when you reach steps 3–5 of `SKILL.md`, or when writing the `update()` migration hook. Replace
every `<Name>` with the plugin's `configKey` (folder name) and every `<table>` with the real table name.
All code and identifiers stay in English.

---

## 1. `gp247.json` field reference (step 3)

The scaffolder emits this; edit the values, not the shape:

```json
{
    "name": "<Name> module",
    "image": "images/logo.jpg",
    "auth": "GP247",
    "email": "support@gp247.net",
    "link": "https://GP247.net",
    "configGroup": "Plugins",
    "configCode": "<Name>",
    "configKey": "<Name>",
    "version": "1.0",
    "requireCore": ["2.1"],
    "requireUpdateFrom": "1.0",
    "requireComposerPackages": [],
    "requireGp247Extensions": []
}
```

| Field | Rule |
|---|---|
| `configKey` | Unique id; **must equal the folder name**; never change after release. |
| `configCode` | Usually the same as `configKey`. |
| `configGroup` | Always `"Plugins"` for a plugin. |
| `version` | Semver-ish (`1.0`, `1.1`, `2.0`). **Every release must be greater** than the installed one (compared with `version_compare`) or 1-click update refuses it. |
| `requireCore` | `["2.1"]` for the v2 standard. |
| `requireUpdateFrom` | Minimum installed version allowed to 1-click update to this release. `"1.0"` = practically no restriction; raise it only when a major release cannot auto-migrate from older versions. |
| `requireComposerPackages` | Composer packages from packagist.org that must be present. |
| `requireGp247Extensions` | Other GP247 extensions required first (e.g. `Shop`, `Front`, `News`). |

> `requireComposerPackages`/`requireGp247Extensions` are the gp247/core 2.1 names (renamed from `requirePackages`/`requireExtensions`). Always emit the new keys; core 2.1 still reads the old ones for backward compatibility but they are deprecated.

---

## 2. `Models/ExtensionModel.php` — create/drop the plugin's table (step 4)

Only when the plugin stores records of its own. Keep install and uninstall symmetric so removing the
plugin leaves no orphan table.

```php
/**
 * Create the plugin's data table on install.
 *
 * @return void
 *
 * @aidlc-unit plugin-lifecycle
 * @aidlc-story US-plugin-create
 */
public function installExtension()
{
    if (!\Illuminate\Support\Facades\Schema::hasTable('<table>')) {
        \Illuminate\Support\Facades\Schema::create('<table>', function ($table) {
            $table->increments('id');
            $table->string('title');
            $table->tinyInteger('status')->default(1);
            $table->timestamps();
        });
    }
}

/**
 * Drop the plugin's data table on uninstall (mirror of installExtension).
 *
 * @return void
 *
 * @aidlc-unit plugin-lifecycle
 * @aidlc-story US-plugin-create
 */
public function uninstallExtension()
{
    \Illuminate\Support\Facades\Schema::dropIfExists('<table>');
}
```

> If the plugin's uninstall must keep data unless the site owner chose "remove files", weigh that against
> a clean teardown — but the default expectation is a symmetric create/drop.

---

## 3. Update-safe config helpers (step 5) — the most important pattern

Because a 1-click update **overwrites every plugin file**, `config.php` can hold **defaults only**. Any
value the site owner edits must live in `admin_config` (the DB), which the update preserves. The runtime
value is `defaults (config.php) ⊕ override (DB)`.

`config.php` — defaults only:

```php
return [
    'settings' => [
        'enabled' => 0,
        'items_per_page' => 20,
    ],
];
```

`function.php` — uncomment the two sample helpers and rename them for `<Name>`:

```php
/**
 * Effective config = defaults (config.php) overlaid with the site owner's overrides in the DB.
 *
 * @return array The merged settings actually in effect at runtime.
 *
 * @aidlc-unit plugin-config
 * @aidlc-story US-plugin-create
 */
function <Name>_effective_config()
{
    $defaults = (array) config('Plugins/<Name>.settings', []);

    $row = \GP247\Core\Models\AdminConfig::where('group', 'Plugins')
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
 * @aidlc-unit plugin-config
 * @aidlc-story US-plugin-create
 */
function <Name>_save_config(array $settings)
{
    \GP247\Core\Models\AdminConfig::updateOrCreate(
        ['group' => 'Plugins', 'key' => '<Name>_config'],
        [
            'code' => '<Name>_config',
            'store_id' => GP247_STORE_ID_GLOBAL,
            'value' => json_encode($settings),
        ]
    );
}
```

- The admin Livewire screen **reads** its form defaults from `<Name>_effective_config()` and, on save,
  **writes** via `<Name>_save_config($validated)`.
- If the plugin has **no** editable settings, delete both helpers and skip this pattern entirely.

---

## 4. `AppConfig::update($fromVersion)` — idempotent migration hook

By default `update()` returns success and does nothing (fine for a code-only release). Override it only
when a new release **changes the DB structure** (adds a column/table, changes the config format). Guard
each step with `version_compare($fromVersion, ...)` and make it safe to run more than once.

```php
/**
 * Migrate plugin data after files were replaced by a newer version.
 *
 * @param string|null $fromVersion Version installed before this update.
 * @return array{error:int,msg:string}
 *
 * @aidlc-unit plugin-lifecycle
 * @aidlc-story US-plugin-create
 */
public function update(?string $fromVersion = null)
{
    // Example: releases >= 1.1 need a "sort" column on <table>.
    if ($fromVersion !== null && version_compare($fromVersion, '1.1', '<')) {
        if (\Illuminate\Support\Facades\Schema::hasTable('<table>')
            && !\Illuminate\Support\Facades\Schema::hasColumn('<table>', 'sort')) {
            \Illuminate\Support\Facades\Schema::table('<table>', function ($table) {
                $table->integer('sort')->default(0);
            });
        }
    }

    return ['error' => 0, 'msg' => ''];
}
```

- Returning `['error' => 1, ...]` or throwing makes the system **roll back** to the backed-up old
  version — fail loudly when a migration is unsafe rather than leaving data half-migrated.
- Never migrate destructively without a guard: running the hook twice must not error or lose data.

---

## 5. Where update overwrites vs preserves (the mental model)

| Location | On 1-click update |
|---|---|
| `app/GP247/Plugins/<Name>/…` (all code, `config.php`, blades) | **Deleted and replaced** with the new release. |
| `public/GP247/Plugins/<Name>/…` (the plugin's own css/js/images) | **Deleted and replaced**. |
| `admin_config` rows (the `<Name>_config` override, install flags) | **Preserved**. |
| The plugin's own data table(s) | **Preserved** (migrate structure via the `update()` hook). |
| User-uploaded files | Must **not** live inside the plugin folder — store under a shared `public/GP247/…` area or `storage/`, or they are lost on update. |
