# checkout-plugins — payment, shipping and total-method plugins

Read this at step 8 of `SKILL.md`, and only when the probe reports `packages.shop.db: true`. These plugins
plug into `gp247/shop`'s checkout, so they carry contracts an ordinary plugin does not. Add `gp247/shop` to
`requireComposerPackages`. Reference plugins that ship with S-Cart: `CashPayment` (simplest payment),
`StripePayment` and `PaypalExpress` (hosted gateway + webhook), `ShippingStandard` (shipping),
`ShopDiscount` (total-method).

---

## 1. How checkout finds the plugin

Checkout lists every **installed and enabled** plugin whose `configCode` matches the role and calls its
`AppConfig::getInfo()` for the option shown to the shopper:

| Role | `configCode` | Probe check |
|---|---|---|
| Payment method | `"Payment"` | — |
| Shipping method | `"Shipping"` | — |
| Total-method (coupon, points…) | `"Promotion"` (older `"Total"` still accepted) | `capabilities.shop_checkout_total_method` |

`getInfo()` keeps the scaffold's keys (`title`, `key`, `code`, `image`, `permission`, `version`, …). A
shipping plugin returns its fee in `value`. Read settings for the effective store with
`gp247_config('<Name>_<setting>', gp247_plugin_store_id())` when `storeScope` is `"store"`.

## 2. Payment method

**Hand-off.** When the shopper places the order, the shop creates the order, keeps its id in
`session('orderID')`, and calls `Controllers\FrontController::processOrder()` of the chosen plugin if that
method exists — otherwise it completes the order directly (cash-on-delivery style). A gateway plugin
redirects to the provider from `processOrder()` and finishes the order when the provider reports back.

**Order money — never write the order's status or paid amount yourself.**
- Record money received with `$order->recordPayment($amount, '<Name>', $gatewayTxnId, ...)` (if
  `capabilities.shop_order_record_payment`) and money given back with `recordRefund(...)`. The ledger is
  idempotent on the gateway transaction id, so a webhook delivered twice records the money once; still
  catch the duplicate-key error a concurrent second delivery can raise.
- Change the order status only through `$order->changeStatus($statusId, $history)` (if
  `capabilities.shop_order_change_status`). It carries every side effect of a transition — goods back to
  stock on cancel, the history row, the status events — that a raw `update(['status' => …])` skips. It
  returns `null` on success or a language key when it refuses the transition; check the result.
- Confirm the amount and currency the provider reports against the order before recording it.
- On uninstall, delete the plugin's settings and menu only. Payments already recorded belong to the
  store's accounts and stay in the order ledger.

**Routes.**
- Shopper-facing return/cancel URLs go in a group with `GP247_FRONT_MIDDLEWARE`; throttle the ones that
  call the provider's API (e.g. `throttle:30,1`).
- The **webhook goes outside the storefront group**: it is a server-to-server POST with no session or CSRF
  token, and storefront middleware may answer it with a redirect or a maintenance page that the provider
  counts as "delivered". Give it a generous throttle (e.g. `throttle:600,1`) and **verify the provider's
  signature before touching the database**.

```php
Route::post('plugin/<url_key>/webhook', [\App\GP247\Plugins\<Name>\Controllers\FrontController::class, 'webhook'])
    ->middleware('throttle:600,1')
    ->name('<url_key>.webhook');
```

**Settings.** Sandbox and live credentials as separate `password` rows (`security = 1`), grouped on the
settings screen with `ConfigForm::sections()` if `capabilities.config_form_sections`, and a test-mode
switch. See `file-templates.md` §3 (model A) and §6.

**Payment links outside checkout** (if `capabilities.shop_payment_gateway`): the shop can collect money
through a link it sends the customer. A payment plugin joins that feature by implementing
`GP247\Shop\Payment\Contracts\PaymentGateway` and registering itself from `Provider.php`; write the whole
array, because a key containing a dot would be split by dot-notation:

```php
if (interface_exists(\GP247\Shop\Payment\Contracts\PaymentGateway::class)) {
    $gateways = (array) config('gp247-config.payment.gateways', []);
    $gateways['<Name>'] = [
        'label' => '<Provider name>',
        'capabilities' => ['collect', 'refund'],
        'driver' => \App\GP247\Plugins\<Name>\Services\<Name>Gateway::class,
    ];
    config(['gp247-config.payment.gateways' => $gateways]);
}
```

**Tests.** Never call the real provider from tests: fake the HTTP layer (`Http::fake([...])`) and post
signed sample webhooks, including a duplicate delivery and a wrong amount.

## 3. Shipping method

`configCode: "Shipping"`. `getInfo()` returns the option and its fee in `value`, computed from the cart and
the plugin's settings for the effective store.

**Money contract:** the site owner enters amounts (fee, free-shipping threshold) in the store's **base**
currency, and that is what the settings hold. `value` must be returned in the **display** currency — the
shop does not convert it. Convert with `gp247_currency_value($amount)` and compare thresholds against the
cart subtotal, which is already in the display currency. The same applies to any amount a total-method
returns.

## 4. Total-method (coupon, points…)

`configCode: "Promotion"`. Besides `getInfo()`, `AppConfig` must implement
`GP247\Shop\Front\Contracts\CheckoutTotalMethod`, or checkout hides the plugin:

```php
public function checkoutApply(array $payload): array;   // validate + apply; return ['error' => 0|1, 'msg' => …]
public function checkoutRemove(): void;                  // clear what checkoutApply stored
public function checkoutView(): ?string;                 // view key of the input fragment, or null
```

The fragment receives `$pluginKey`, `$plugin` and `$message`, and calls the wizard's `applyTotal` /
`removeTotal` actions with the `totalPayload` field. `ShopDiscount` is the reference.

## 5. Price changes on the product (advanced)

If the plugin changes a product's price (sale, member price), and `capabilities.shop_price_resolvers`, add
a resolver to `gp247-config.shop.price_resolvers` from `Provider.php` instead of editing prices in the
database. Read the docblock above the resolver loop in `GP247\Shop\Models\ShopProduct` for the callback
contract before writing one.
