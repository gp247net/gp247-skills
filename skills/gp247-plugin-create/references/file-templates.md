# file-templates — code templates for gp247-plugin-create

Read this when you reach steps 3–7 of `SKILL.md`, or when writing the `update()` hook. Replace every
`<Name>` with the plugin's `configKey` (folder name), `<url_key>` with the snake-case URL key the
scaffolder put in `Route.php`, and `<table>` with the table name **without** the GP247 prefix — the
snippets add `GP247_DB_PREFIX` themselves, like every bundled plugin, so core's tools (doctor, key
rotation, which also add the prefix) find the table. All code and identifiers stay in
English. Features marked "if `capabilities.X`" refer to the JSON printed by `scripts/gp247-probe.php`.

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
    "configGroup": "Plugins",
    "configCode": "<Name>",
    "configKey": "<Name>",
    "version": "1.0",
    "requireCore": ["<probe.require_core>"],
    "requireUpdateFrom": "1.0",
    "requireComposerPackages": [],
    "requireGp247Extensions": [],
    "requireLivewire": false,
    "storeScope": "global"
}
```

| Field | Rule |
|---|---|
| `configKey` | Unique id; **must equal the folder name**; never change after release. |
| `configCode` | The plugin's role. `"Payment"` / `"Shipping"` / `"Promotion"` (total-method) make checkout pick the plugin up — see `checkout-plugins.md`. Any other plugin: the same as `configKey`. |
| `configGroup` | Always `"Plugins"` for a plugin. |
| `version` | `1.0`, `1.1`, `2.0`… **Every release must be greater** than the installed one (compared with `version_compare`) or 1-click update refuses it. |
| `requireCore` | Core versions the plugin runs on. Each entry is a range: `"X.Y"` = `>= X.Y.0` and `< (X+1).0.0`; several entries are a union. Use the probe's `require_core` (the running `major.minor`), **one entry per supported major**. Core compares against `config('gp247.core')`, which has no patch number — so never write a patch-level floor like `"3.0.3"`: it can fail on a site that has the patch. To express "needs feature F", rely on a minor that has F. |
| `requireUpdateFrom` | Minimum installed version allowed to 1-click update to this release. `"1.0"` = practically no restriction; raise it only when a major release cannot migrate from older versions. |
| `requireComposerPackages` | Composer packages that must be present (e.g. `gp247/shop` for a checkout plugin). |
| `requireGp247Extensions` | Other GP247 extensions required first (by key). |
| `requireLivewire` | Informational; keep `false` — the admin shell ships Livewire already. |
| `storeScope` | Can settings / on-off differ per store? `"global"` (default, one value system-wide) or `"store"` (per-store enable + settings; `"platform"` is an old alias). For `"store"`: read settings via `gp247_config('<Name>_<setting>', gp247_plugin_store_id())` (falls back to the global row) and return `true` from `ConfigForm::storeScoped()`. Per-store on/off is handled on the plugin list. **Who may edit is a separate knob:** append the admin URL segment to `gp247-config.admin.store_scoped_segments` in `Provider.php` to let store admins configure their own store, or leave it out so only the root admin sets per-store values. Secrets inherit but are never revealed at a sub-store. |

> `requireComposerPackages` / `requireGp247Extensions` replaced the older `requirePackages` /
> `requireExtensions`. Always emit the new keys; core still reads the old ones but logs a deprecation
> warning.

---

## 2. `Models/ExtensionModel.php` — create/drop the plugin's table (step 4)

Only when the plugin stores records of its own. Keep install and uninstall symmetric so removing the
plugin leaves no orphan table.

```php
/**
 * Create the plugin's data table on install.
 *
 * @return void
 */
public function installExtension()
{
    if (!\Illuminate\Support\Facades\Schema::hasTable(GP247_DB_PREFIX.'<table>')) {
        \Illuminate\Support\Facades\Schema::create(GP247_DB_PREFIX.'<table>', function ($table) {
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
 */
public function uninstallExtension()
{
    \Illuminate\Support\Facades\Schema::dropIfExists(GP247_DB_PREFIX.'<table>');
}
```

> Data the store must keep for its own records (orders, payments, accounting) is **not** plugin data —
> do not drop it on uninstall.

### `Schema::create/drop` vs. Laravel migration files — and the `migrations`-ledger trap

**Recommended: manage the plugin's own schema with `Schema` directly in `ExtensionModel`, as above** (the
`News` plugin does this). It is **re-entrant by design**: `installExtension()` runs on every install and
recreates the table, so uninstall → reinstall always rebuilds it.

You *may* instead provision tables with **Laravel migration files** under `DB/migrations/`, run via
`Artisan::call('migrate', ['--path' => 'app/GP247/Plugins/<Name>/DB/migrations', '--force' => true])` in
`install()`. **But it carries a trap:** Laravel records each migration in the *shared* `migrations` ledger
and **never re-runs a migration already listed there**. If `uninstall()` drops the table but leaves the
ledger row, the next install reports *"Nothing to migrate"* and the table is **not** recreated — every
screen reading it then fails with `SQLSTATE[42S02] ... table doesn't exist`.

If you choose migration files, you **must**:

1. **Clean the plugin's own rows from the `migrations` ledger on uninstall**, and reconcile before
   `migrate` in `install()`/`update()` so an already-broken site heals on its next update:
   ```php
   // In uninstall() after dropping the table(s); and in install()/update() before migrate():
   $names = array_map(
       fn ($p) => pathinfo($p, PATHINFO_FILENAME),
       glob(base_path('app/GP247/Plugins/<Name>/DB/migrations/*.php')) ?: []
   );
   if ($names) {
       \Illuminate\Support\Facades\DB::table('migrations')->whereIn('migration', $names)->delete();
   }
   ```
   Match by the plugin's own **file names** (read from `DB/migrations`) — never a loose `LIKE '%…%'`.
2. Keep **every** migration `up()` guarded with `Schema::hasTable()` so re-running is a safe no-op for
   tables that still exist and only recreates the missing ones.

This is the whole cost of keeping migration files. **When in doubt, prefer the
`Schema`-in-`ExtensionModel` approach** — there is no ledger to reconcile.

---

## 3. Update-safe settings (step 5) — the most important pattern

A 1-click update **overwrites every plugin file**, so `config.php` can hold **defaults only**. Any value the
site owner edits must live in `admin_config` (the DB), which the update preserves. Choose one model.

### 3A. Settings screen, one row per key (recommended; if `capabilities.config_form`)

Every setting is one `admin_config` row: `group = 'Plugins'`, `key = '<Name>_<setting>'` (always
prefixed — `gp247_config()` looks keys up across all plugins), `code = '<Name>_config'`. Seed the rows in
`install()`, top them up in `update()`, delete them in `uninstall()`.

`AppConfig.php`:

```php
/**
 * Every row this plugin owns in admin_config: the on/off row + one row per setting.
 * Secret rows carry security = 1 so core encrypts them at rest.
 *
 * @return array<int, array<string, mixed>>
 */
private function seedRows(): array
{
    $rows = [[
        'group' => $this->configGroup, 'code' => $this->configCode, 'key' => $this->configKey,
        'sort' => 0, 'store_id' => GP247_STORE_ID_GLOBAL, 'value' => self::ON, 'security' => 0,
        'detail' => $this->appPath.'::lang.title',
    ]];
    // setting => [default, secret?]
    $settings = [
        'enabled'    => ['0', 0],
        'api_secret' => ['', 1],
    ];
    $sort = 0;
    foreach ($settings as $key => [$default, $security]) {
        $rows[] = [
            'group' => $this->configGroup, 'code' => $this->configKey.'_config',
            'key' => $this->configKey.'_'.$key, 'sort' => ++$sort,
            'store_id' => GP247_STORE_ID_GLOBAL, 'value' => $default, 'security' => $security,
            'detail' => $this->appPath.'::lang.'.$key,
        ];
    }

    return $rows;
}
```

Use it in `install()` (insert all rows once — every row needs the same columns, or MySQL rejects the batch),
and in `update()` insert only the rows that are missing — never overwrite a value the site owner saved
(full `update()` in §4). In `uninstall()` delete by key prefix as well as by code, so no secret row
survives to reappear on reinstall:

```php
(new AdminConfig)->where('group', $this->configGroup)->where(function ($q) {
    $q->where('key', $this->configKey)
      ->orWhere('key', 'like', $this->configKey.'\_%')
      ->orWhere('code', $this->configKey.'_config');
})->delete();
```

`Livewire/AdminLivewire.php` extends `GP247\Core\AdminShell\Infrastructure\ConfigForm` instead of
`GP247AdminComponent`:

```php
class AdminLivewire extends ConfigForm
{
    protected ?string $permission = null;

    protected function group(): string   { return 'Plugins'; }
    protected function heading(): string { return trans('Plugins/<Name>::lang.config_heading'); }

    // Without keys() the form would list every row of the Plugins group.
    protected function keys(): array
    {
        return ['<Name>_enabled', '<Name>_api_secret'];
    }

    protected function fieldTypes(): array
    {
        return ['<Name>_enabled' => 'bool', '<Name>_api_secret' => 'password'];
    }

    // storeScope "store" only:
    // protected function storeScoped(): bool { return true; }
}
```

Optional, when the probe has them: `fieldOptions()` for `select` fields, `fieldHints()`
(`capabilities.config_form_field_hints`) for a placeholder/format hint, and `sections()`
(`capabilities.config_form_sections`) to group rows under headings — e.g. sandbox vs live credentials:
`return [['id' => 'live', 'title' => trans(...), 'hint' => trans(...), 'keys' => ['<Name>_api_secret']]];`.
`StripePayment/Livewire/AdminLivewire.php` uses all three.

Read a setting anywhere with `gp247_config('<Name>_enabled')` (add `gp247_plugin_store_id()` as the second
argument when `storeScope` is `"store"`).

### 3B. One JSON row (structured settings)

For settings a flat form cannot express (lists, nested options). `config.php` holds the defaults:

```php
return [
    'settings' => [
        'enabled' => 0,
        'items_per_page' => 20,
    ],
];
```

Uncomment the two sample helpers the scaffolder put in `function.php` (they are already named for the
plugin). They read `config('Plugins/<Name>.settings')` overlaid with the JSON stored in the
`admin_config` row `<Name>_config`, and write that row back. The admin screen **reads** its form defaults
from `<Name>_effective_config()` and **writes** with `<Name>_save_config($validated)`. The scaffold's
`uninstall()` already deletes the row (`code = '<Name>_config'`).

This model **cannot hold a secret** — one JSON value cannot be flagged `security` per field. Put a
credential in a separate 3A row even when the rest of the settings use 3B.

---

## 4. `AppConfig::update(?string $fromVersion)` — the data hook

The scaffold's `update()` returns success and does nothing. Override it whenever a release adds a setting
row, a menu entry, a table or a column, or changes stored data.

```php
/**
 * Bring the database in line with this version of the files. Idempotent.
 *
 * @param string|null $fromVersion Version installed before this update; null = unknown.
 * @return array{error:int,msg:string}
 */
public function update(?string $fromVersion = null)
{
    try {
        // Settings rows a later version introduced; never touch a saved value.
        foreach ($this->seedRows() as $row) {
            $exists = AdminConfig::where('group', $row['group'])->where('key', $row['key'])
                ->where('store_id', (string) $row['store_id'])->exists();
            if (!$exists) {
                AdminConfig::insert($row);
            }
        }

        // A column added in 1.1 — guarded by the current state, so null and re-runs are safe.
        $t = GP247_DB_PREFIX.'<table>';
        if (\Illuminate\Support\Facades\Schema::hasTable($t)
            && !\Illuminate\Support\Facades\Schema::hasColumn($t, 'sort')) {
            \Illuminate\Support\Facades\Schema::table($t, function ($table) {
                $table->integer('sort')->default(0);
            });
        }

        $this->ensureMenu(); // §7, if the plugin has a menu entry

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
  or a manual copy, applied by `gp247:update`, `gp247:ext-update --type=plugin --key=<Name> --local`, or
  the admin "Apply" button. A site with no recorded version gets **one call with `null`**. This path has
  **no file rollback**: a failure only leaves the update pending so the site owner can retry.

So: never skip on `null`, guard every step by checking the current state (`hasTable`, `hasColumn`, row
exists) rather than by version alone, and make running it twice harmless. Use `version_compare($fromVersion, …)`
only for data transformations that cannot be detected from the state, and treat `null` as "oldest".

---

## 5. Where update overwrites vs preserves (the mental model)

| Location | On update |
|---|---|
| `app/GP247/Plugins/<Name>/…` (all code, `config.php`, blades) | **Deleted and replaced** with the new release. |
| `public/GP247/Plugins/<Name>/…` (the plugin's own css/js/images) | **Deleted and replaced**. |
| `admin_config` rows (on/off, settings) | **Preserved**. |
| The plugin's own data table(s), its menu rows | **Preserved** (bring them up to date in `update()`). |
| User-uploaded files | Must **not** live inside the plugin folder — store under a shared `public/GP247/…` area or `storage/`, or they are lost on update. |

---

## 6. Secret / encrypted settings — credentials must never be plaintext

Requires `capabilities.secret_cast` (the `Secret` cast, the `security` flag and
`gp247:encryption-key-rotate` come together). A plugin that talks to a paid gateway or external API almost
always has a **credential**; design it as a secret from the start.

### 6a. Secret on the settings screen (the common case)
Model 3A only. Seed the row with `'security' => 1` and declare the field `password` in `fieldTypes()`.
Core masks it, encrypts it at rest, and — if `capabilities.config_form_write_only_secrets` — keeps the saved
value out of the browser entirely (the field shows whether a value is set; typing replaces it, leaving it
empty keeps it). Read it with `gp247_config('<Name>_api_secret')` — plaintext comes back transparently. Never
echo it to a Blade view or a log line.

### 6b. Secret in the plugin's OWN table
For a secret column outside `admin_config` (e.g. a per-customer OAuth token), use the shared cast:

```php
// In the model:
protected $casts = [
    'access_token' => \GP247\Core\Casts\Secret::class,
];
```

Then, in `Provider.php` (inside the `gp247_extension_check_active(...)` block), register the column so
`gp247:doctor` and `gp247:encryption-key-rotate` cover it:

```php
config(['gp247-config.security.encrypted_columns.<table>' => ['access_token']]); // <table> without the prefix
```

The column must be **TEXT** (ciphertext is long); it **cannot be searched/filtered** while encrypted (add a
separate blind-index column — e.g. an HMAC — if you need lookup); and the table needs an `id` primary key
so key rotation can update rows.

### 6c. What NOT to do
- Do not implement your own `Crypt::encryptString` / `base64` scheme — use the `password` field type or the
  `Secret` cast, so the value uses the dedicated key and the shared rotation tooling.
- Do not put a credential in `config.php`, a JSON settings row (3B), a `.env` shipped with the plugin, or a
  plain column.
- Do not log the credential or return it in an API response.

Operator guide (dedicated encryption key, safe key change): gp247-docs `system/data-encryption.md`.

---

## 7. Admin menu entry

Insert under an existing parent block (find it by `key`, e.g. `ADMIN_SHOP_PAYMENT` for a payment method,
`ADMIN_SHOP_SHIPPING` for shipping); skip quietly when the parent is absent, and never insert twice:

```php
private const MENU_URI = 'admin::<url_key>'; // the admin route prefix after GP247_ADMIN_PREFIX

private function ensureMenu(): void
{
    $parent = AdminMenu::where('key', '<PARENT_KEY>')->first();
    if ($parent && !AdminMenu::where('uri', self::MENU_URI)->exists()) {
        AdminMenu::insert([
            'parent_id' => $parent->id, 'sort' => 40, 'title' => '<Menu title>',
            'icon' => 'fas fa-puzzle-piece', 'uri' => self::MENU_URI, 'key' => null, 'type' => 0,
        ]);
    }
}
```

Call it from `install()` and `update()`; in `uninstall()` run
`AdminMenu::where('uri', self::MENU_URI)->delete();`. (`use GP247\Core\Models\AdminMenu;`)

---

## 8. Storefront registries (step 7)

Both go in `Provider.php`, inside the `gp247_extension_check_active(...)` block and behind
`class_exists('GP247\Front\Controllers\RootFrontController')` so the plugin still boots without
`gp247/front`.

**Layout block** (if `capabilities.front_layout_block_views`) — the site owner places it from the Layout
Block screen, on any template:

```php
$blockViews = config('gp247-config.front.layout_block_views', []);
$blockViews['<block_name>'] = $extensionPath.'::blocks.<block_name>'; // view at Views/blocks/<block_name>.blade.php
config(['gp247-config.front.layout_block_views' => $blockViews]);
```

A template that ships its own `blocks/<block_name>.blade.php` overrides the plugin's view — pick a block
name unlikely to clash (prefix it with the plugin name).

**Plugin hook** (if `capabilities.front_plugin_hooks`) — output at a fixed spot a shop page offers (e.g.
`shop_product_detail_bottom` receives `['product' => $product]`, `shop_order_detail_bottom` receives
`['order' => $order]`; grep the templates for `gp247_render_plugin_hook(` to list the hooks):

```php
$hooks = config('gp247-config.front.plugin_hooks', []);
$hooks['shop_product_detail_bottom'][] = [
    'callback' => fn (array $data) => view($extensionPath.'::hooks.product_bottom', $data)->render(),
];
config(['gp247-config.front.plugin_hooks' => $hooks]);
```

**Own page in the "Page" scope of layout blocks** — uncomment the `layout_page` registration the scaffold
leaves in `Provider.php` and store the language **key**, not a rendered string (the `News` plugin is the
reference).
