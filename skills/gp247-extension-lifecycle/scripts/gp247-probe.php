<?php
/**
 * gp247-probe.php — read-only environment probe for the GP247 agent skills.
 *
 * Usage (from the Laravel project root):
 *     php <skill-dir>/scripts/gp247-probe.php [project-root]
 *
 * Prints ONE JSON object describing the GP247 installation the skill is about to work on:
 * the core version exactly as the extension compatibility check reads it, which packages
 * are installed (code AND database), which gp247:* commands exist, and which extension
 * points / features are available. Skills branch on these facts instead of on version
 * numbers written into the skill.
 *
 * It changes nothing by itself. Booting the app is the same as running any
 * `php artisan` command (service providers run). If the app cannot boot (broken .env,
 * database down…), it falls back to reading the core config file and says so in
 * "bootstrapped" / "bootstrap_error".
 *
 * The same file ships in every gp247-* skill so each skill works when installed alone;
 * keep the copies identical.
 */

$root = rtrim($argv[1] ?? getcwd(), '/\\');

$result = [
    'probe'           => 'gp247-probe/1',
    'root'            => $root,
    'bootstrapped'    => false,
    'bootstrap_error' => null,
    'core'            => null,
    'core_source'     => null,
    'require_core'    => null,
    'php'             => PHP_VERSION,
    'laravel'         => null,
    'packages'        => [],
    'commands'        => [],
    'capabilities'    => [],
];

// Fallback source of the core version: the package's own config file. Read as text,
// never included — the file calls env(), which only exists inside a booted app.
$coreConfig = $root.'/vendor/gp247/core/src/Config/gp247.php';
if (is_file($coreConfig)
    && preg_match("/'core'\s*=>\s*'([^']+)'/", (string) file_get_contents($coreConfig), $m)) {
    $result['core'] = $m[1];
    $result['core_source'] = 'vendor/gp247/core/src/Config/gp247.php';
}

try {
    if (!is_file($root.'/vendor/autoload.php') || !is_file($root.'/bootstrap/app.php')) {
        throw new RuntimeException('Not a Laravel project root: '.$root);
    }
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    $result['bootstrapped'] = true;
    $result['laravel'] = $app->version();

    // The value gp247_extension_check_compatibility() compares requireCore against.
    $core = config('gp247.core');
    if ($core) {
        $result['core'] = (string) $core;
        $result['core_source'] = "config('gp247.core')";
    }

    $prefix = (string) config('gp247-config.env.GP247_DB_PREFIX', 'gp247_');
    $hasTable = function (string $table) use ($prefix) {
        try {
            return Illuminate\Support\Facades\Schema::hasTable($prefix.$table);
        } catch (Throwable $e) {
            return null; // database unreachable: unknown, not "absent"
        }
    };

    // A package counts as usable only when its code is present AND its install step
    // created its tables — a composer package alone is not enough.
    $result['packages'] = [
        'core'  => ['code' => true, 'db' => $hasTable('admin_config')],
        'front' => ['code' => class_exists('GP247\\Front\\FrontServiceProvider'), 'db' => $hasTable('front_page')],
        'shop'  => ['code' => class_exists('GP247\\Shop\\ShopServiceProvider'), 'db' => $hasTable('shop_product')],
    ];

    $commands = [];
    foreach ($kernel->all() as $name => $command) {
        if (strpos($name, 'gp247:') === 0) {
            $commands[$name] = $command;
        }
    }
    ksort($commands);
    $result['commands'] = array_keys($commands);

    $hasOption = function (string $name, string $option) use ($commands) {
        return isset($commands[$name]) && $commands[$name]->getDefinition()->hasOption($option);
    };
    $configForm = 'GP247\\Core\\AdminShell\\Infrastructure\\ConfigForm';
    $shopProduct = 'GP247\\Shop\\Models\\ShopProduct';

    $result['capabilities'] = [
        // CLI
        'ext_commands'             => isset($commands['gp247:ext-install']),
        'ext_publish'              => isset($commands['gp247:ext-publish']),
        'ext_register_license'     => isset($commands['gp247:ext-register-license']),
        'ext_update_local'         => $hasOption('gp247:ext-update', 'local'),
        'json_output'              => $hasOption('gp247:info', 'json'),
        'doctor'                   => isset($commands['gp247:doctor']),
        'template_publish'         => isset($commands['gp247:template-publish']),
        // Extension data & settings
        'extension_data_updater'   => class_exists('GP247\\Core\\Library\\ExtensionDataUpdater'),
        'secret_cast'              => class_exists('GP247\\Core\\Casts\\Secret'),
        'config_form'              => class_exists($configForm),
        'config_form_sections'     => method_exists($configForm, 'sections'),
        'config_form_field_hints'  => method_exists($configForm, 'fieldHints'),
        'config_form_store_scoped' => method_exists($configForm, 'storeScoped'),
        'config_form_write_only_secrets' => method_exists($configForm, 'secretStateOf'),
        // Storefront (gp247/front)
        'front_plugin_hooks'       => function_exists('gp247_render_plugin_hook'),
        'front_layout_block_views' => is_array(config('gp247-config.front.layout_block_views')),
        'template_vendor_views'    => function_exists('gp247_template_source_roots'),
        // The block positions admin Layout Block offers; a template's layout must render each one.
        'front_layout_positions'   => array_keys((array) config('gp247-config.front.layout_position', [])),
        // Shop (gp247/shop)
        'shop_checkout_total_method' => interface_exists('GP247\\Shop\\Front\\Contracts\\CheckoutTotalMethod'),
        'shop_payment_gateway'       => interface_exists('GP247\\Shop\\Payment\\Contracts\\PaymentGateway'),
        'shop_order_change_status'   => method_exists('GP247\\Shop\\Models\\ShopOrder', 'changeStatus'),
        'shop_order_record_payment'  => method_exists('GP247\\Shop\\Models\\ShopOrder', 'recordPayment'),
        'shop_price_resolvers'       => class_exists($shopProduct)
            && strpos((string) file_get_contents((new ReflectionClass($shopProduct))->getFileName()), 'price_resolvers') !== false,
    ];
} catch (Throwable $e) {
    $result['bootstrap_error'] = get_class($e).': '.$e->getMessage();
}

// requireCore entries are ranges: "X.Y" means >= X.Y.0 and < (X+1).0.0. The suggested
// floor is the running major.minor — the version the extension is actually built and
// tested on. Lower it only after confirming every capability the extension uses exists there.
if ($result['core'] && preg_match('/^(\d+)(?:\.(\d+))?/', $result['core'], $v)) {
    $result['require_core'] = [$v[1].'.'.($v[2] ?? '0')];
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
