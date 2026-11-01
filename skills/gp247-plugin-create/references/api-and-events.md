# api-and-events — admin API writes, sync events, shop events

Read this only when the plugin adds **admin REST API endpoints** (for an ERP, CRM or warehouse tool), records
**sync events** of its own, or **writes shop data** (orders, stock, products, customers) that an ERP should notice.
Full guide with examples: [How to build an API correctly — section 5.2](https://github.com/gp247net/gp247-docs/blob/main/api/create-api.md)
(how an application calls these: [GP247 API Introduction](https://github.com/gp247net/gp247-docs/blob/main/api/api-introduction.md),
sections 6 and 7).

**Check the site first.** These pieces exist only when the site's core has them. Confirm before writing code against
them, for example:

```bash
php artisan tinker --execute="echo class_exists('GP247\\Core\\Api\\Sync\\ApiEvents') ? 'yes' : 'no';"
```

`no` → tell the user their core lacks admin API writes and sync events instead of writing the code.

---

## 1. Admin API write endpoints

Register the routes through `ApiRouteRegistrar` (or wrap them in `GP247_API_MIDDLEWARE` yourself), under
`GP247_API_V2_CORE_PREFIX`, with these middleware in this order:

```php
'middleware' => [
    'auth:admin-api',
    \GP247\Core\Api\Support\ApiAbilities::admin(),
    'api.admin:admin_<url_key>.index,write',   // allowed only for accounts that may change data on that admin screen
    'throttle:gp247-api-write',               // per-account write limit (GP247_API_WRITE_THROTTLE, default 300/min)
    'api.idempotent:optional',                // or 'api.idempotent' when running twice would be wrong (e.g. stock deltas)
],
```

- `,write` checks exactly like the screen's own write actions; a view-only account gets `403`. Do not invent a separate
  permission scheme.
- `api.idempotent` requires an `Idempotency-Key` header; a retry with the same key and body answers the first result
  (`Idempotent-Replayed: true`); the same key with another body → `409 idempotency_key_reused`.
- In the controller: find the record through `ApiStoreScope::apply(...)` (`404` outside the account's store), validate an
  **allowlist** of fields and refuse unknown keys (`prohibited`), change state through the model / a business method —
  never raw SQL. Return `ApiQuery::one(...)` with a declared shape.

## 2. Sync-ready lists

Pass `changedSince: true` to `ApiQuery::page(...)` so the list accepts `updated_since` with `page[after]` keyset paging.
The table needs an `updated_at` column (create an index on it in `installExtension()`), and every write must keep
`updated_at` current — save through Eloquent.

## 3. Plugin event types

Register types in the plugin's `Provider.php` (boot) by **merging** into the existing array — never overwrite it, core and
gp247/shop register theirs in the same key:

```php
config(['gp247-config.api.events' => array_merge((array) config('gp247-config.api.events', []), [
    '<name_lower>.<resource>.<action>' => [
        'fields'     => ['status'],                  // the only keys ever kept in data
        'permission' => 'admin_<url_key>.index',     // admins allowed to open this screen may read the event
    ],
])]);
```

Record after the change:

```php
\GP247\Core\Api\Sync\ApiEvents::record('<name_lower>.<resource>.<action>', (string) $row->id, (string) $row->store_id, [
    'status' => (int) $row->status,
]);
```

- Names: lower case, dot-separated, at most 64 characters, **prefixed with the plugin key** (lower case). A malformed
  name is ignored.
- Only declared `fields` are kept. **Never put personal data in `data`** (names, e-mails, phones, addresses); the ERP
  re-reads the record through its detail endpoint when it needs more.
- The event is written after the surrounding transaction commits, nothing is written while the site owner keeps the event
  log off, and a failure is reported, never thrown. Recorded events also reach webhooks following that type.

## 4. Changes to shop data

To get the shop's own events (`order.*`, `stock.changed`, `product.*`, `customer.*`) for what the plugin changes, save
through gp247/shop's models (Eloquent `save()` / `update()` / `delete()`) or its methods (for example
`ShopOrder::changeStatus()`). Raw SQL or a bare query-builder `update()` on shop tables is not seen — the ERP will miss it.

## 5. Fields, includes, caching and deprecation (core 3.8.0+)

Check first: `class_exists('GP247\\Core\\Api\\V2\\ApiShape')` — `false` means the site's core predates these.

- **ETag / 304** comes for free on every successful v2 `GET` registered through `GP247_API_MIDDLEWARE`; nothing to add.
- **`fields[<type>]` / `include`:** declare an `ApiShape` for the endpoint and pass the resolved choice to `ApiQuery`:

  ```php
  $shape = (new \GP247\Core\Api\V2\ApiShape('<name_lower>_item', ['name' => ['descriptions'], 'price', 'status'], [
      'brand' => ['with' => ['brand' => fn ($q) => $q->where('status', 1)],
                  'render' => fn ($row) => $row->brand ? ['id' => $row->brand->id, 'name' => $row->brand->name] : null,
                  'type' => 'brand', 'fields' => ['id', 'name']],
  ]))->resolve($request);                                   // 422 on an unknown type, field or include
  $query = Item::query()->with($shape->with(['descriptions']));   // drops relations only unchosen fields read
  return \GP247\Core\Api\V2\ApiQuery::page($query, $request, $toArray, [], [], null, false, $shape);
  ```

  `$toArray` must not read the relation of a field that was not chosen (build costly values with
  `ApiShapeSelection::build($shape, ['field' => fn () => …])`). Included records follow the same visibility rules as when
  read on their own; one level only, at most 3 per request.
- **`api.cache.public`** only on public routes that never carry one person's data (catalog, content) — never on carts,
  accounts, orders or admin routes.
- **`api.deprecated:<deprecated-on>,<sunset-on>,<guide anchor>`** on a route you plan to remove; give at least 12 months and
  2 minor releases of notice.
