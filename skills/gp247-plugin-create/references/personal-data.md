# Personal data of customers — declare it (step 4)

A plugin that keeps data about the shop's customers in a table of its own (reviews with a name, a log of
messages, a request with an email or a phone number…) **declares** that table. The platform then finds the
rows when a customer asks for a copy of their data, deletes or anonymises them when the customer is erased,
and can clean old rows by age. An undeclared table is listed on the "Personal data requests" screen as
"customer data nobody declared", and the site owner has to handle it by hand.

Not needed when the plugin keeps no customer data, or only writes into the shop's own tables (orders,
payments, shipments — gp247/shop declares those).

## 1. Declare in the plugin's `Provider.php` (preferred)

```php
// Provider.php: `use` lines at the top of the file; the declaration OUTSIDE the
// if (gp247_extension_check_active(...)) block — a disabled plugin's data must still be erasable.
use GP247\Core\PersonalData\Application\PersonalDataRegistry;
use GP247\Core\PersonalData\Domain\PersonalDataSource;
use GP247\Core\PersonalData\Domain\SubjectLink;

// WHY class_exists: the plugin also runs on a core without the personal data registry.
if (class_exists(PersonalDataRegistry::class)) {
    PersonalDataRegistry::declare(
        PersonalDataSource::table('acme_review')                         // name without GP247_DB_PREFIX; key 'id'
            ->link(SubjectLink::customer('customer_id'))                  // rows of a customer account
            ->link(SubjectLink::email('author_email', onlyWhenEmpty: 'customer_id')) // guests, by the email they typed
            ->anonymise([                                                 // keep the row, remove the person
                'author_name' => 'mark',                                  // becomes "[deleted]"
                'author_email' => 'null',
                'content' => 'scrubText',                                 // remove the person's known values from free text
            ])
            ->identity(['author_name'])                                   // values collected first, to scrub free text
            ->export(['product_id', 'rating', 'content', 'created_at'])   // columns put in the customer's data package
            ->order(30)                                                   // rows pointing to others first, low numbers first
            ->describe(['en' => 'Product reviews', 'vi' => 'Đánh giá sản phẩm', 'ja' => '商品レビュー'], 'Plugins/AcmeReview')
    );
}
```

**`identity($columns, $emailColumns)`:** the second argument is only for columns holding **the account's own** email. An email a
customer typed (review, form, note) never goes there — someone else's email would pull their data into the package or the erasure.

| Choice | Options |
|---|---|
| Link to the customer | `SubjectLink::customer('col')` · `SubjectLink::email('col', onlyWhenEmpty: 'customer_col')` · `SubjectLink::morph('type_col', 'id_col', $morphClass)` · `SubjectLink::parent('parent_table', 'col')` (child rows of a declared table) |
| What erasure does to a row | `->deleteRows()` · `->anonymise([...column rules])` (keep the row: money, dates, codes) · `->retain()` (kept, e.g. a security record with its own retention) |
| Column rules (`anonymise`) | `mark` (`[deleted]`) · `null` (empty) · `scrubText` · `['scrubJson', ['path.to.key', …]]` (or `scrubJson` alone: every string value) · `keep` |
| Table without an `id` key | `PersonalDataSource::table('acme_cart', 'identifier')` |
| Table that only looks personal | `PersonalDataRegistry::notCustomerData('acme_supplier', 'suppliers of the shop, not customers')` |

Rules the core enforces: plain identifiers only (no SQL in a declaration), at least one link, an `anonymise`
rule must change at least one column. A wrong declaration in code stops the whole site (plugin load error) — try the plugin on a
development site first; a wrong `gp247.json` entry is only skipped. Never declare a table of the platform (`admin_*`, `api_*`, `shop_*`…) —
core, front and shop declare their own.

## 2. Or in `gp247.json` (no code)

```json
"personalData": [
  { "table": "acme_review", "key": "id",
    "link": [{"customer": "customer_id"}, {"email": "author_email", "guest": "customer_id"}],
    "rule": "anonymise",
    "columns": {"author_name": "mark", "author_email": "null", "content": "scrubText"},
    "identity": ["author_name"],
    "export": ["product_id", "rating", "content", "created_at"],
    "order": 30,
    "label": {"en": "Product reviews", "vi": "Đánh giá sản phẩm", "ja": "商品レビュー"} }
]
```

Links in the manifest: `customer`, `email` (+ `guest`), `parent` + `column` (+ `type_column`, `type_value`); `morph` only in code.
`emails` is only for the account's own email. The manifest is read even while the plugin is disabled (its data must still be erasable). A broken entry is
skipped and reported. A code declaration of the same table wins.

## 3. Keep data only as long as needed (optional)

When rows lose their purpose after a while (a log, a cart of the plugin), add a retention item next to the
declaration; the site owner sees it on the "Data retention" screen, turns it on or off and sets the days:

```php
use GP247\Core\PersonalData\Domain\Retention\RetentionItem;

if (method_exists(PersonalDataRegistry::class, 'retention')) {
    // A retention item cleans only a table declared with deleteRows() (or notCustomerData).
    PersonalDataRegistry::declare(
        PersonalDataSource::table('acme_request_log')->link(SubjectLink::customer('customer_id'))->deleteRows()->order(60)
            ->describe(['en' => 'Acme request log', 'vi' => 'Nhật ký yêu cầu Acme', 'ja' => 'Acme リクエストログ'], 'Plugins/AcmeReview')
    );
    PersonalDataRegistry::retention(
        RetentionItem::rows('acme.request_log', 'acme_request_log')   // key: lower case, digits, _, one dot; ≤ 29 characters
            ->olderThan('created_at')->days(90, 7, 365)                // default, minimum, maximum
            ->describe(['en' => 'Acme request log', 'vi' => 'Nhật ký yêu cầu Acme', 'ja' => 'Acme リクエストログ'], 'Plugins/AcmeReview', 50)
    );
}
```

A retention item deletes rows only of a table declared with `->deleteRows()` (or marked `notCustomerData`).
Something that removes business data: add `->disabledByDefault()->risky(['<backup data group>'])`.

## 4. Check

- The "Personal data requests" screen → "Check the database" no longer lists the plugin's tables.
- Erase a test customer: the plugin's rows of that customer are gone or anonymised, others untouched.
