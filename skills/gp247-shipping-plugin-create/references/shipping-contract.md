# shipping-contract — gp247/shop's shipping provider contract

Read this at steps 5–8 and 10 of `SKILL.md`. Everything here is code in `vendor/gp247/shop/src/Shipping/`
(namespace `GP247\Shop\Shipping`) — when in doubt, the docblocks there are the final word. Replace `<Name>`
with the plugin's `configKey` and `<url_key>` with its admin URL key. Use only the capabilities listed in
the probe's `capabilities.shop_shipping_capabilities`.

```php
use GP247\Shop\Shipping\Contracts\ShippingProvider;          // required: rates()
use GP247\Shop\Shipping\Contracts\CreatesShipments;          // optional: 'shipments' (and 'cod')
use GP247\Shop\Shipping\Contracts\CancelsShipments;          // optional: 'cancel'
use GP247\Shop\Shipping\Contracts\ProvidesShippingDocuments; // optional: 'documents'
use GP247\Shop\Shipping\Contracts\TracksShipments;           // optional: 'tracking'
use GP247\Shop\Shipping\Contracts\HandlesShippingWebhooks;   // optional: 'webhook'
use GP247\Shop\Shipping\Data\RateRequest;
use GP247\Shop\Shipping\Data\RateResult;
use GP247\Shop\Shipping\Data\ShippingRate;
use GP247\Shop\Shipping\Data\Money;
use GP247\Shop\Shipping\Data\ShippingAddress;
use GP247\Shop\Shipping\Data\ShippingItem;
use GP247\Shop\Shipping\Data\ShipmentRequest;
use GP247\Shop\Shipping\Data\ShipmentResult;
use GP247\Shop\Shipping\Data\Parcel;
use GP247\Shop\Shipping\Data\TrackingEvent;
use GP247\Shop\Shipping\Data\WebhookResult;
use GP247\Shop\Shipping\Data\ShippingDocument;
use GP247\Shop\Shipping\ShipmentStatus;
use GP247\Shop\Shipping\ShippingException;
use GP247\Shop\Shipping\ShippingSettings;      // shop shipping settings: pick-up address, default weight
use GP247\Shop\Shipping\ShippingCurrency;      // convert between two currencies the shop knows
use GP247\Shop\Shipping\WeightConverter;       // any weight unit → grams
use GP247\Shop\Shipping\LengthConverter;       // any length unit → cm
use GP247\Shop\Shipping\RateRequestFactory;    // the shop's base currency
```

---

## 1. Registration

Checkout lists every **installed and enabled** plugin with `configCode: "Shipping"` (per store when
`storeScope` is `"store"`). For each one, the shop uses the driver the plugin registered; a plugin with no
registered driver is served through its legacy `getInfo()` (§9).

`Provider.php`, inside the `gp247_extension_check_active(...)` block:

```php
// Guarded: on a shop without the contract the plugin keeps serving getInfo().
if (interface_exists(\GP247\Shop\Shipping\Contracts\ShippingProvider::class)) {
    config(['gp247-config.shop.shipping_providers.<Name>' => [
        'driver' => \App\GP247\Plugins\<Name>\Services\<Name>Provider::class,
        // Only what the driver implements: 'rates', 'shipments', 'cancel', 'documents', 'tracking', 'webhook', 'cod'.
        'capabilities' => ['rates'],
        // Optional: the shop's shipping settings link to the plugin's settings screen.
        'settings_route' => 'admin_<url_key>.index',
    ]]);
}
```

| Capability | Interface the driver must implement | What the shop then offers |
|---|---|---|
| `'rates'` | `ShippingProvider` | Services and prices at checkout (always on) |
| `'shipments'` | `CreatesShipments` | "Create shipment" form on the order |
| `'cancel'` | `CancelsShipments` | Cancel button while the shipment is not final — the carrier may refuse after pick-up |
| `'documents'` | `ProvidesShippingDocuments` | Label download, streamed to the admin |
| `'tracking'` | `TracksShipments` | Sync button + lazy refresh when the order is opened (no cron) |
| `'webhook'` | `HandlesShippingWebhooks` | The shared webhook route answers for this plugin |
| `'cod'` | `CreatesShipments` | The booking carries the amount to collect on delivery |

The driver is built through the container (`app($driver)`), so constructor injection works. A declared
capability whose interface is missing is ignored and logged once.

## 2. Quoting — `rates(RateRequest $request): RateResult`

`RateRequest` (read-only):

| Field | Meaning |
|---|---|
| `$storeId` | Store whose settings apply — read credentials with `gp247_config('<Name>_<key>', $request->storeId)` |
| `$origin`, `$destination` | `ShippingAddress` — `name, phone, country` (ISO alpha-2), `province` (first level), `district` (may be empty), `ward, line1, line2, postcode, email, company` |
| `$items` | `ShippingItem[]` — see §4 |
| `$declaredValue` | Value of the goods, a `Money` in the shop's **base** currency |
| `$codAmount` | Usually null at quote time (the payment method is chosen after shipping) |
| `totalWeightGrams()` | Sum of line weights |

Return one `ShippingRate` per service:

```php
return new RateResult([
    new ShippingRate(
        pluginKey: '<Name>',
        serviceCode: 'express',             // '' when the carrier has one service
        title: trans('Plugins/<Name>::lang.service_express'),
        amount: new Money($fee, 'VND'),     // the CARRIER's currency; never pre-convert
        etaMinDays: 1, etaMaxDays: 2,       // optional; shown to the shopper
        expiresAt: time() + 1800,           // optional; when the quote stops being valid
        meta: ['image' => $logoUrl],        // optional; non-secret only
    ),
]);
```

Rules:
- **Refusals are data.** Missing settings, an address the carrier cannot serve, or a refusal →
  `return RateResult::error('not_configured', '');` (the code is a short machine word; the message is
  safe for a shopper, or empty). Never throw for these.
- **Currency.** Return the carrier's own currency. The shop converts carrier → base → display once, and
  drops (and logs) a rate in a currency missing from its currency list — tell the site owner to add it.
  A plugin that prices in the base currency gets it from `RateRequestFactory::baseCurrency()`; to convert
  something itself (e.g. a threshold), use `ShippingCurrency::convert($money, $to, $precision)` — it returns
  **null** when either currency is missing from the shop, and rounds to 2 decimals unless you pass the
  carrier currency's precision (0 for VND/JPY).
- **Caching and failure isolation are the shop's.** The whole answer — rates and errors — is cached per
  request fingerprint in the session for 10 minutes; do not add another cache. A carrier that **throws** is
  paused for a minute while the others still answer; a returned error is not paused, so a slow carrier
  costs its full timeout on every new cart or address. Set a short timeout (a few seconds) on every HTTP
  call; throw (rather than return an error) when the carrier is down, so it gets paused.
- **Server state.** The rate the shopper picks is re-derived on the server and stored on the order
  (`shipping_service` / `shipping_rate`); there is nothing to post back from the browser.

## 3. Addresses

`ShippingAddress` is already split into the parts carriers ask for; do not parse address lines. The
pick-up address the site owner entered is `ShippingSettings::origin($storeId)` (it is also `RateRequest::$origin`).
When the carrier needs a field the shop left empty (a ward, a postcode), return
`RateResult::error('address_incomplete', ...)` instead of guessing.

## 4. Parcel data — `ShippingItem`, `Parcel`

Each `ShippingItem` is one cart/order line, built by the shop from the product:

| Field | Notes |
|---|---|
| `name`, `qty`, `sku`, `productId`, `variant` | `variant` is the chosen options, human readable |
| `weightGrams` | **One unit**, in grams; the shop already applied its default weight when the product has none. `lineWeightGrams()` = weight × qty |
| `lengthCm`, `widthCm`, `heightCm` | One unit, in cm; 0 = unknown |
| `unitValue` | `Money` of one unit |
| `barcode`, `mpn`, `brand`, `category`, `imageUrl` | Identification some carriers ask for |
| `meta` | Carrier-specific extras added by plugins |

Downloadable/digital products are not in the list; bundles are weighed from their parts. Convert a
carrier unit you receive with `WeightConverter::toGrams($value, 'kg')` / `LengthConverter::toCm($value, 'in')`.

To add data the shop does not hold (an HS code, a fragile flag), register a resolver from `Provider.php`;
it receives the line and the product and must return a `ShippingItem` (a throw or anything else is
ignored and logged). Register an **array callable**, never a closure — config values must survive
`php artisan config:cache`:

```php
// Provider.php
$resolvers = (array) config('gp247-config.shop.shipping_item_resolvers', []);
$resolvers[] = [\App\GP247\Plugins\<Name>\Services\<Name>ItemResolver::class, 'resolve'];
config(['gp247-config.shop.shipping_item_resolvers' => $resolvers]);

// Services/<Name>ItemResolver.php
public static function resolve(ShippingItem $item, $product): ShippingItem
{
    return $item->withMeta(['fragile' => (bool) ($product->fragile ?? false)]);
}
```

When booking, the admin may have measured the packed box: `ShipmentRequest::$parcel` (a `Parcel` with
`weightGrams`, `lengthCm`, `widthCm`, `heightCm`; `hasDimensions()`) — prefer it over the sum of items.

## 5. Optional capabilities

**`CreatesShipments`**

```php
public function createShipment(ShipmentRequest $request): ShipmentResult;
public function shipmentFormFields(): array;
```

- `ShipmentRequest`: `orderId, storeId, pluginKey, serviceCode, origin, destination, items, declaredValue,
  codAmount, note, extra` (values of your form fields), `attempt`, `parcel`. At booking time
  `declaredValue`, `codAmount` and each item's `unitValue` are in the **order's currency** (at quote time
  they were in the base currency) — convert them to the carrier's currency with `ShippingCurrency::convert()`
  and throw `ShippingException` when it returns null.
- Send `$request->bookingReference()` as the carrier's order reference: it is the order id on the first
  booking and gets a suffix after a cancellation (carriers refuse a reused id). If the carrier says that
  reference is already booked, **return the existing shipment** instead of failing.
- Return `new ShipmentResult(trackingNumber: ..., status: ShipmentStatus::PENDING, fee: new Money(...),
  trackingUrl: 'https://…', providerReference: ..., etaText: ...)`. `trackingUrl` must be an `http(s)` URL — anything else is dropped.
- `shipmentFormFields()` adds carrier-specific inputs to the booking form:
  `['name' => 'pick_option', 'label' => 'Plugins/<Name>::lang.pick_option', 'type' => 'select', 'required' => true,
  'options' => ['cod' => '…', 'post' => '…'], 'default' => 'cod']` (types: `text`, `select`, `checkbox`, `textarea`;
  `max` limits text length). Their values arrive in `$request->extra`.

**`CancelsShipments`** — `cancelShipment(string $trackingNumber, string $storeId): void`; the admin can
press Cancel at any non-final state, so throw `ShippingException` whenever the carrier refuses (e.g.
already picked up).

**`ProvidesShippingDocuments`** — `document(string $trackingNumber, string $storeId, array $options = []):
ShippingDocument` (`content` bytes, `mime`, `filename`). The shop streams it; nothing is stored and the
carrier token never reaches the browser.

**`TracksShipments`** — `track(string $trackingNumber, string $storeId): array` of `TrackingEvent`
(at least the current state).

For all of them: credentials come from the `$storeId` argument; a refusal is a `ShippingException` whose
message is shown to the admin **as-is** — keep it short and free of tokens or raw responses.

## 6. Status map — `ShipmentStatus`

Map every carrier status code onto one of these in a single place (a small mapper class with a test):

| Case | Meaning |
|---|---|
| `PENDING` | Booked, not picked up |
| `PICKED_UP` | Carrier has the parcel |
| `IN_TRANSIT` | Moving between hubs |
| `OUT_FOR_DELIVERY` | With the courier for delivery |
| `DELIVERY_FAILED` | Attempt failed, will retry |
| `DELIVERED` | Final |
| `RETURNING` | On the way back to the shop |
| `RETURNED` | Back at the shop — final |
| `CANCELLED` | Cancelled — final |
| `EXCEPTION` | Lost, damaged, needs a human |

A carrier code that is only informational maps to `null` in the `TrackingEvent` (kept in the timeline,
never moves the state). The shop derives the order's shipping status from the shipment, and ignores a late
event for a shipment the shop has already cancelled.

```php
new TrackingEvent(
    trackingNumber: $tracking,
    status: ShipmentStatus::IN_TRANSIT,     // or null
    providerStatus: (string) $carrierCode,  // raw code, kept for support
    occurredAt: $carrierEventUnixTime,      // the CARRIER's time: replays must give the same event
    message: $carrierReason,
    codSettled: false, codAmount: null, fee: null,
);
```

`TrackingEvent::dedupeKey()` (plugin key + tracking + provider status + time) is how the shop drops a
replayed event — keep `providerStatus` and `occurredAt` stable for the same carrier event.

## 7. Cash on delivery

- Which payment methods mean "collect cash on delivery" is a shop setting
  (`gp247-config.shop.cod_payment_methods`); for such an order the shop computes the amount — the order's
  outstanding balance — and passes it as `ShipmentRequest::$codAmount` (a `Money` in the **order's
  currency**, possibly 0).
- Declare `'cod'`, convert that amount to the carrier's currency (`ShippingCurrency::convert()`, refuse
  the booking when it returns null) and send it. Never recompute the amount.
- When the carrier reports it has **paid the collected cash out to the shop**, emit that as **its own
  event** with `codSettled: true`, `codAmount`, and a `providerStatus` + `occurredAt` of its own: the
  settlement flag is not part of the dedupe key, so riding on an event the shop already stored would
  drop it.
- Recording that cash on the order is a site-owner setting (off by default). When it is on, the shop
  records it **once** (its own idempotency key); either way the plugin never calls the order's payment
  methods.

## 8. Webhook — `HandlesShippingWebhooks`

```php
public function handleWebhook(Request $request, Closure $storeOf): WebhookResult;
```

- The shop routes `POST /shipping/webhook/<Name>` (the `shipping/webhook` part is the configurable
  prefix) outside the storefront middleware, throttles it and answers 404 when the plugin does not declare
  `'webhook'`.
  Build the URL to show the site owner with `route('shop.shipping.webhook', ['pluginKey' => '<Name>'])`.
- `$storeOf($trackingNumber)` returns the store id that owns a shipment of this plugin, or null when the
  tracking number is unknown — use it to pick the store's credentials/secret.
- **Verify before trusting.** Check the carrier's signature, or a secret you generated on install and put
  in the URL/header (compare with `hash_equals`). Unverifiable → `return WebhookResult::rejected(403);`
  and the shop writes nothing. Do not reveal whether a tracking number exists before verification.
- States that move money or end the shipment (`DELIVERED`, `RETURNED`, COD settled) should be confirmed
  from the carrier API (`track()`) when the carrier offers no signature.
- Return `new WebhookResult(true, $events, 200)`. The shop applies each event in its own transaction and
  answers 500 on failure so the carrier retries.

## 9. Legacy contract (shop without the shipping layer)

When `capabilities.shop_shipping_provider` is false, checkout only calls `AppConfig::getInfo()` and reads
its `value` as the fee — in the **display** currency (`gp247_currency_value($amountInBase)`), compared
against the cart subtotal (already display currency). There is one service, no shipments, no webhook.

A plugin can serve **both**: keep `getInfo()` returning `title`, `image` and `value`, and register the
driver only behind `interface_exists(...)` (§1) — the public `ShippingStandard` plugin does exactly this.

## 10. Tests

Never call the real carrier from tests. With Laravel's HTTP fake:

```php
Http::fake([
    'sandbox.carrier.example/*/fee*'   => Http::response(['fee' => 30000, 'currency' => 'VND']),
    'sandbox.carrier.example/*/orders' => Http::response(['tracking' => 'T123'], 201),
]);
```

Cover at least:
- `rates()` → one rate per enabled service, in the carrier currency; missing credentials, incomplete
  address and carrier refusal → `RateResult` errors, no exception; carrier **timeout** / 5xx → an exception
  (the shop pauses the carrier).
- Status mapper: every documented carrier code → the expected `ShipmentStatus` (or null).
- `createShipment()` sends `bookingReference()`, the COD amount and the parcel; "already booked" returns
  the existing shipment; refusal → `ShippingException` without secrets in the message.
- Webhook: valid → events; bad signature/secret → rejected with nothing written; the same delivery twice
  → the same events (stable `providerStatus` + `occurredAt`).
- Settings are read for the request's `storeId` (two stores, two accounts).
