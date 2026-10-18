# payment-contract — gp247/shop's checkout hand-off and money contract

Read this at steps 5–10 of `SKILL.md`. The code is in `vendor/gp247/shop/src/` — when in doubt, the
docblocks of the methods named here are the final word. Replace `<Name>` with the plugin's `configKey` and
`<url_key>` with its URL key. Features marked "if `capabilities.X`" refer to the probe's JSON.

```php
use GP247\Shop\Models\ShopOrder;               // recordPayment(), recordRefund(), changeStatus()
use GP247\Shop\Models\ShopOrderStatus;         // NEW, PROCESSING, … (status ids)
use GP247\Shop\Models\ShopOrderTransaction;    // the ledger rows; alreadyRecorded()
use GP247\Shop\Controllers\ShopCartController; // completeOrder(), cancelOrder()
use Illuminate\Database\UniqueConstraintViolationException;
// Payment links and refunds (if capabilities.shop_payment_gateway):
use GP247\Shop\Payment\Contracts\PaymentGateway;
use GP247\Shop\Payment\PaymentRequestService;
use GP247\Shop\Payment\Models\PaymentRequest;
use GP247\Shop\Payment\Models\PaymentMovement;
use GP247\Shop\Payment\Support\CollectResult;
use GP247\Shop\Payment\Support\RefundResult;
```

---

## 1. How checkout finds and hands off to the plugin

Checkout lists every **installed and enabled** plugin with `configCode: "Payment"` (per store when
`storeScope` is `"store"`) and shows `AppConfig::getInfo()` (`title`, `image`, `note`…). `note` is optional
plain text (never HTML): from gp247/shop 3.1.4 the default template prints it, escaped, under the method the
shopper selected and again on the confirm step; older versions print only `title`. Read settings for
the effective store with `gp247_config('<Name>_<setting>', gp247_plugin_store_id())`.

When the shopper places the order, the shop **creates the order first**, puts its id in
`session('orderID')`, then:
- if `App\GP247\Plugins\<Name>\Controllers\FrontController` has a `processOrder()` method, returns what it
  returns (usually a redirect to the provider);
- otherwise completes the order directly — the offline / cash-on-delivery path.

## 2. Gateway flow

```php
public function processOrder()
{
    $order = ShopOrder::find(session('orderID'));
    if ($order === null) {
        return redirect(gp247_route_front('front.home'))->with('error', trans('Plugins/<Name>::lang.error_order'));
    }
    // Same order sent twice → same provider session: use an idempotency key per order.
    $session = $api->createSession($this->params($order), '<name>-order-' . $order->id);
    $order->update(['transaction' => $session['id']]); // the provider reference, not money
    return redirect()->away($session['url']);
}
```

- Charge the **order's** total in the **order's** currency (`$order->total`, `$order->currency`); send one
  consolidated line when the provider would otherwise re-round per line.
- **Return URL** (shopper is back): read the payment **from the provider** by the reference you stored —
  never trust the query string — check that it belongs to `session('orderID')`, record it (§3) when it is
  already paid, then `return (new ShopCartController)->completeOrder();`. A payment still processing is
  recorded later by the webhook.
- **Cancel URL**: if money was received after all, complete instead; refuse to cancel an order that is
  no longer new; otherwise `return (new ShopCartController)->cancelOrder();`.
- Shopper-facing routes go in a group with `GP247_FRONT_MIDDLEWARE`; throttle the ones that call the
  provider (e.g. `throttle:30,1`).

## 3. Recording money — the order payment ledger

Every payment and refund of an order is one row of the ledger (`ShopOrderTransaction`); the order's
`received`, balance and payment status are **derived** from it.

```php
public function recordPayment(float $amount, ?string $method = null, ?string $txnId = null,
    $paidAt = null, ?string $note = null, $adminId = null): ?ShopOrderTransaction;
public function recordRefund(float $amount, ?string $method = null, ?string $txnId = null,
    $paidAt = null, ?string $note = null, $adminId = null): ?ShopOrderTransaction;
```

- `$amount` is in the **order's currency**, positive (a non-positive amount is ignored). Convert the
  provider's minor units with that currency's decimals.
- `$method` = `'<Name>'`; `$txnId` = the provider's transaction / payment id. It is the **idempotency key**:
  a replayed webhook records nothing new and returns the **existing** row — tell the two apart with
  `$row->wasRecentlyCreated`.
- Two deliveries at the same instant can both pass the check; the second insert then hits the unique key.
  Wrap the call and treat that as "already recorded":

  ```php
  try {
      $row = $order->recordPayment($paid, '<Name>', $providerPaymentId, null, $matches ? null : $mismatchNote);
  } catch (UniqueConstraintViolationException $e) {
      $row = null; // the other delivery recorded it
  }
  $isNew = $row !== null && $row->wasRecentlyCreated;
  ```

- `recordPayment()` and `changeStatus()` are two steps. If a delivery recorded the money and then failed
  before the status moved, the provider's retry finds the money already there — so **do not stop early on
  a replay**: when the amount matched and the order is still `NEW`, move it on the retry too (below).
  `ShopOrderTransaction::alreadyRecorded($id)` is only safe as an early exit when it is followed by that
  same status check. Write history and report only when `$isNew`.
- **Record what the provider actually took.** Compare amount and currency with the order first; when they
  differ, record the real amount with a note, report it (`gp247_report(...)`) and leave the status alone —
  a mismatch is for a human to reconcile, not to hide by recording the order total.
- Mirror a refund made at the provider (dashboard or API) with `recordRefund()` keyed by the provider's
  refund id. It re-derives the order's received amount, balance and payment status — **not** the order
  status. When nothing collected remains (`$order->received <= 0`), move the order to
  `ShopOrderStatus::REFUNDED` with `changeStatus()` yourself; a partial refund leaves the status alone.

**Status.** Change it only through the order's transition, which returns stock on cancel, writes the
history and fires the status events:

```php
if ((int) $order->status === ShopOrderStatus::NEW) {
    // A webhook runs outside the shopper's session: read settings for the ORDER's store.
    $target = (int) gp247_config('<Name>_order_status_success', $order->store_id) ?: ShopOrderStatus::PROCESSING;
    $refused = $order->changeStatus($target, ['content' => '<Name> payment ' . $providerPaymentId]);
    if ($refused !== null) {
        gp247_report('<Name>: order ' . $order->id . ' status not changed: ' . $refused);
    }
}
```

`changeStatus(int $newStatus, array $history = [])` returns `null` on success or a language key naming why
it refused. Move only an order that is still `NEW` — a late webhook must not undo what the admin already
did; otherwise just add history. Never write `status`, `payment_status` or `received` yourself.

## 4. Webhook

```php
// Route.php — OUTSIDE the storefront middleware group.
Route::post('plugin/<url_key>/webhook', [\App\GP247\Plugins\<Name>\Controllers\FrontController::class, 'webhook'])
    ->middleware('throttle:600,1')
    ->name('<url_key>.webhook');
```

- It is a server-to-server POST with no session or CSRF token; storefront middleware may answer it with a
  redirect or a maintenance page that the provider counts as delivered. Keep it outside the group —
  there it runs without session or CSRF middleware, so the signature is its only gate.
- **Verify the signature on the raw body first** (`$request->getContent()`), with the secret of the store
  the payment belongs to, comparing with `hash_equals`. Invalid → `400`/`403` and touch nothing.
- Handle only the event types you need; record through §3 (idempotent); answer `200` when done or
  already done, `500` on an internal error so the provider retries.
- Show the webhook URL on the settings screen so the site owner can paste it into the provider.

## 5. Payment links and refunds (if `capabilities.shop_payment_gateway`)

The shop can ask a customer to pay (or refund them) outside checkout through **payment requests**. A
payment plugin joins by implementing `PaymentGateway` and registering it from `Provider.php` — write the
whole array, because a key containing a dot would be split by dot-notation:

```php
if (interface_exists(\GP247\Shop\Payment\Contracts\PaymentGateway::class)) {
    $gateways = (array) config('gp247-config.payment.gateways', []);
    $gateways['<Name>'] = [
        'label' => '<Provider name>',
        'capabilities' => ['collect', 'refund'],          // subset of 'collect', 'refund', 'payout'
        'driver' => \App\GP247\Plugins\<Name>\Services\<Name>Gateway::class,
    ];
    config(['gp247-config.payment.gateways' => $gateways]);
}
```

The driver (built through the container):

| Method | Contract |
|---|---|
| `key(): string` | The registry key, `'<Name>'` |
| `capabilities(): array` | What it really supports: `'collect'` (hosted page for any amount), `'refund'` (give back money it collected), `'payout'` (pay a third party). Return exactly the list of the registry entry — **the registry entry is what the admin offers**. |
| `availableFor(PaymentRequest $request): bool` | False when the request's store has no credentials for the current mode — the gateway is then hidden from the pay page |
| `collect(PaymentRequest $request): CollectResult` | Start collecting `$request->outstanding()` in `$request->currency`; return `new CollectResult($redirectUrl, $providerSessionId)` |
| `refund(PaymentRequest $request, PaymentMovement $original, float $amount, ?string $idempotencyKey = null): RefundResult` | Refund at the provider; return `new RefundResult($providerRefundId, $completed)`. `$idempotencyKey` is set when `$original` is not a stored movement; otherwise derive your own key |

Credentials come from **`$request->store_id`** (and in webhooks from the order's or request's
`store_id`), never from the session. A method for a capability you did
not declare may throw `UnsupportedCapabilityException`.

Money of a payment request is recorded through the shop's service, not the order ledger:

```php
// Provider confirmed a collection (return URL or webhook):
app(PaymentRequestService::class)->recordMovement($request, [
    'type' => PaymentMovement::TYPE_COLLECT,
    'amount' => $paid,                      // the request's currency
    'gateway' => '<Name>',
    'gateway_ref' => $providerPaymentId,    // idempotency key: a replay returns the existing movement
    'method' => PaymentMovement::METHOD_GATEWAY,
    'reference' => $providerSessionId,
    'confirmed' => true,                    // money the provider confirmed: kept even if the request was cancelled meanwhile
]);

// A refund made at the provider: find the collection it gives back, then record it.
$service = app(PaymentRequestService::class);
$original = $service->findGatewayCollect('<Name>', $providerPaymentId);
if ($original !== null) {
    $service->recordMovement(PaymentRequest::find($original->request_id), [
        'type' => PaymentMovement::TYPE_REFUND, 'amount' => $refunded, 'gateway' => '<Name>',
        'gateway_ref' => $providerRefundId, 'method' => PaymentMovement::METHOD_GATEWAY,
        'refund_of' => $original->id, 'confirmed' => true,
    ]);
}
```

Mark the provider session with the payment request id (metadata) so the webhook can tell a payment
request's money from an order's and send each to its own path.

## 6. Cash on delivery (if `capabilities.shop_cod_payment_methods`)

A method whose orders are paid in cash to the courier adds its key, so shipments of those orders collect
the outstanding balance (shipping plugins carry the amount; the shop records the cash when the carrier
settles it):

```php
$methods = (array) config('gp247-config.shop.cod_payment_methods', []);
if (!in_array('<Name>', $methods, true)) {
    $methods[] = '<Name>';
    config(['gp247-config.shop.cod_payment_methods' => $methods]);
}
```

## 7. Tests

Never call the real provider: fake HTTP (`Http::fake([...])`) and post **signed** sample webhooks built
with a test secret. Cover at least:
- `processOrder()` creates one provider session per order (sent twice → same idempotency key) and redirects.
- Return URL with a forged/other order's reference → refused; with a paid session → one ledger row,
  status moved from `NEW`.
- Webhook: valid signature → one ledger row; **the same delivery twice → still one row**; bad signature →
  400/403 and nothing written; amount or currency different from the order → recorded with a note, status
  unchanged; order no longer `NEW` → history only.
- Provider refund event → `recordRefund()` once; partial refund leaves the order partly paid and its
  status unchanged; a full refund moves it to `REFUNDED`.
- A retry after a failure between recording and the status change still moves a `NEW` order.
- Settings read for the right store (two stores, two accounts); no credential in logs or responses.
- If built: `PaymentGateway` `availableFor()` false without credentials; `collect()` returns the redirect;
  `refund()` returns the provider id; webhook money of a payment request goes to `recordMovement()`.
