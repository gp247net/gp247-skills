# checkout-plugins — total-method plugins and price changes

Read this at step 8 of `SKILL.md`, and only when the probe reports `packages.shop.db: true`. These plugins
plug into `gp247/shop`'s checkout, so they carry contracts an ordinary plugin does not. Add `gp247/shop` to
`requireComposerPackages`. Reference plugin that ships with S-Cart: `ShopDiscount`.

> **Payment and shipping plugins have their own skills** — `gp247-payment-plugin-create` (checkout
> hand-off, order payment ledger, webhook, payment links) and `gp247-shipping-plugin-create` (carrier
> quotes, shipments, tracking, webhook). Use them instead of this file; they follow gp247/shop's money
> and shipping contracts closely. If they are not installed, get them from the same skill repository.

---

## 1. How checkout finds the plugin

Checkout lists every **installed and enabled** plugin whose `configCode` matches the role and calls its
`AppConfig::getInfo()` for the line shown to the shopper:

| Role | `configCode` | Probe check |
|---|---|---|
| Total-method (coupon, points…) | `"Promotion"` (older `"Total"` still accepted) | `capabilities.shop_checkout_total_method` |
| Payment method | `"Payment"` | use `gp247-payment-plugin-create` |
| Shipping method | `"Shipping"` | use `gp247-shipping-plugin-create` |

`getInfo()` keeps the scaffold's keys (`title`, `key`, `code`, `image`, `permission`, `version`, …). Read
settings for the effective store with `gp247_config('<Name>_<setting>', gp247_plugin_store_id())` when
`storeScope` is `"store"`.

## 2. Total-method (coupon, points…)

`configCode: "Promotion"`. Besides `getInfo()`, `AppConfig` must implement
`GP247\Shop\Front\Contracts\CheckoutTotalMethod`, or checkout hides the plugin:

```php
public function checkoutApply(array $payload): array;   // validate + apply; return ['error' => 0|1, 'msg' => …]
public function checkoutRemove(): void;                  // clear what checkoutApply stored
public function checkoutView(): ?string;                 // view key of the input fragment, or null
```

The fragment receives `$pluginKey`, `$plugin` and `$message`, and calls the wizard's `applyTotal` /
`removeTotal` actions with the `totalPayload` field. `ShopDiscount` is the reference.

**Money contract:** the site owner enters amounts (discount value, minimum order) in the store's **base**
currency, and that is what the settings hold. An amount the total-method returns must be in the
**display** currency — the shop does not convert it. Convert with `gp247_currency_value($amount)` and
compare thresholds against the cart subtotal, which is already in the display currency.

## 3. Price changes on the product (advanced)

If the plugin changes a product's price (sale, member price), and `capabilities.shop_price_resolvers`, add
a resolver to `gp247-config.shop.price_resolvers` from `Provider.php` instead of editing prices in the
database. Read the docblock above the resolver loop in `GP247\Shop\Models\ShopProduct` for the callback
contract before writing one.
