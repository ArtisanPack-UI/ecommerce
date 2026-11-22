# Products and catalog writes

Every catalog write goes through one service, `Services\ProductService`, with two
small companions, `Services\ProductCategoryService` and
`Services\ProductTagService`. Admin UIs, the REST and GraphQL admin endpoints,
and imports all call them, so a product is validated the same way however it was
made (engine spec §3.1–§3.10a, §9.5).

## Product types

Core registers five types in `ProductTypeRegistry`:

| Key | Sold as | Ships | Stock |
|---|---|---|---|
| `simple` | one line at the product's price | yes | the product's own row |
| `variable` | one of its variants (a cart line needs `variant_id`) | yes | per variant |
| `digital` | one line; delivers files and/or license keys | no | not tracked |
| `grouped` | never on its own; shoppers add its children | no | not tracked |
| `bundled` | one line at the bundle's own price | yes | the bundle's own row |

A bundle's members are copied into the order line snapshot under `bundle`
(product, variant, name, SKU, quantity), so the packing list survives later
catalog edits.

A product whose type isn't registered (its satellite was uninstalled) is
read-only: every write refuses it with the `type-missing` code.

## Creating and updating

```php
$product = app( ProductService::class )->create( [
    'type'         => 'simple',
    'name'         => 'Linen Shirt',          // slug fills from the name when blank
    'status'       => 'active',
    'prices'       => [
        [ 'currency' => 'USD', 'price_amount' => 4500, 'compare_at_amount' => 5000 ],
        [ 'currency' => 'USD', 'price_amount' => 3900, 'starts_at' => '2026-11-01', 'ends_at' => '2026-11-08' ],
    ],
    'category_ids' => [ 3 ],
    'tag_ids'      => [ 7 ],
    'images'       => [ [ 'image_url' => 'https://…/front.jpg', 'alt_text' => 'Front' ] ],
    'inventory'    => [ 'track_inventory' => true, 'low_stock_threshold' => 3, 'quantity_on_hand' => 12 ],
] );
```

`create()` and `update()` take the product columns plus these related keys. A
related key that is present **replaces** that set; an absent key is left alone.

| Key | Holds |
|---|---|
| `prices` | Rows of `currency`, `price_amount`, `compare_at_amount`, `cost_amount`, `starts_at`, `ends_at` (integer minor units). Two rows can't share a currency and schedule window. |
| `category_ids`, `tag_ids` | Ids to link. |
| `images` | The ordered gallery: rows of `media_id` (media library) **or** `image_url` (http/https), plus `alt_text`. A row with the `id` of an existing image updates it. |
| `featured_image_url` | URL fallback for the featured image when the media library is absent, kept in `meta.featured_image_url`. With the library, set `featured_image_media_id`. |
| `attributes` | Rows of `key`, `label`, `is_variation`, `values` (`label`, `value`, `swatch`). Matched to existing attributes by `id`, then `key`. |
| `children` | A grouped or bundled product's members (see below). |
| `relations` | Hand-picked links by type: `{ "upsell": [ids], "cross_sell": [ids], "related": [ids] }`. Each type present replaces that list (see [Related products](#related-products-upsells-and-cross-sells)). |
| `inventory` | `track_inventory`, `allow_backorder`, `low_stock_threshold`; on create also an opening `quantity_on_hand`. |
| `stock_adjustment` | Update only: `{ delta, reason }`. |

`is_featured` (for "featured" listings) and `position` (manual catalog
order, the storefront default sort) are plain columns. `description` and
`short_description` are cleaned with `kses()`. Slugs and
SKUs must be unique; SKUs are unique across products **and** variants. The
whole write runs in one transaction, so a bad price row leaves nothing behind.

## Variants

```php
$products->createVariant( $product, [ 'sku' => 'TEE-M', 'option_values' => [ $sizeId => $mediumId ], 'prices' => [ … ] ] );
$products->variantMatrixSize( $product );          // 12 for 3 sizes × 4 colours
$products->generateVariants( $product, [ 'prices' => [ … ] ] ); // creates only the missing combinations
$products->reorderVariants( $product, [ 9, 8, 10 ] );
```

`generateVariants()` builds every combination of the product's attributes
marked `is_variation`, names each variant from its option labels ("M / Red"),
and refuses more than `ProductService::MAX_GENERATED_VARIANTS` (500) at once.
Two variants can't have the same option combination.

## Grouped and bundled children

Members live in `product_children` (engine spec §3.10a):

| Column | Meaning |
|---|---|
| `parent_product_id` | The grouped or bundled product. |
| `child_product_id` | A member product. |
| `child_variant_id` | Optional: one variant of the member. |
| `quantity` | How many the parent contains (≥ 1). |
| `position` | Order in the list. |

```php
$products->syncChildren( $bundle, [
    [ 'product_id' => $tee->id, 'variant_id' => $teeMedium->id, 'quantity' => 2 ],
    [ 'product_id' => $mug->id ],
] );
```

Only `grouped` and `bundled` products have children; a satellite type opts in
with `has_children => true` in its registry meta. A product can't contain
itself, the same product and variant twice, or a product that already contains
it at any depth, so the structure never loops.

## Related products, upsells, and cross-sells

Hand-picked links live in `product_relations` (engine spec §3.10b): an
`upsell` (offered on the product page instead of the product), a `cross_sell`
(suggested in the cart), or a `related` product ("you may also like"), each
list ordered by `position`.

```php
$products->syncProductRelations( $phone, ProductRelation::CROSS_SELL, [ $case->id, $charger->id ] );
```

Each call replaces that one type's list; the other types are left alone. A
product can't be linked to itself or to the same product twice. Admins also
write them with the `relations` key of a product write or
`POST admin/products/{product}/relations` (`{ type, ids }`, answers the
linked products).

`Catalog\RelatedProducts` reads them for storefronts, visible products only:

- `for( $product, $type = 'related', $limit = 8 )` returns the hand-picked
  products in order. A `related` list that comes up short is filled with
  products sharing a category (most shared first), then a tag. The result
  runs through the `ap.ecommerce.product.related` filter
  `(Collection $products, Product $product, string $type, int $limit)`.
- `crossSellsForCart( $cart, $limit = 8 )` returns the cross-sells of the
  cart's products, first line first, leaving out anything already in the
  cart, through `ap.ecommerce.cart.crossSells` `(Collection $products, Cart $cart, int $limit)`.

Over REST: `GET products/{product}/related?type=&limit=` (`limit` at most
50) and `GET carts/{cart}/cross-sells`.

## Showing a product

Storefronts read everything a product page needs from one place,
`GET products/{product}/purchase-options?currency=&country_code=&region_code=&postal_code=`
(currency defaults to the base currency), or in-process from the services
below. It answers `{ product_id, currency, price, stock, variants, options }`.

### Display prices

`Pricing\PriceDisplayResolver::for( $product|$variant, $currency, ?Address $destination, ?Customer $customer )`
returns a `Pricing\DisplayPrice`, or null when there's no price in that
currency:

| Field | Meaning |
|---|---|
| `price` | The current price, through the product type (so bundles price as they do in the cart) and the `ap.ecommerce.pricing.priceDisplay` filter `(Money $price, Product\|ProductVariant $subject, ?Customer $customer)`. A scheduled sale price applies while it runs. |
| `compare_at`, `on_sale` | The same price row's compare-at price, shown only when it's higher than the price. |
| `min_price`, `max_price`, `is_range` | For a variable product: its cheapest and dearest variant. `price` and `compare_at` are the cheapest variant's ("From $19 ~~$25~~"). |
| `price_including_tax`, `price_excluding_tax`, `prices_include_tax`, `tax_label` | One unit taxed by the active tax provider at the destination (default: the store's country, `store.country`). A tax-inclusive store gets the price with the tax backed out; an exclusive one gets the tax added. `tax_label` is null when no tax applies. |

`ProductPriceResolver::resolveWithCompareAt()` returns the raw row pair
(`price`, `compare_at`), converted together when only a base-currency row
exists.

### Variants

`Catalog\VariantResolver::match( $product, $attributeValueIds )` returns the
variant whose option values are exactly those ids (any order), or null.
`matrix( $product, $currency )` lists every variant for a variation picker:
`variant_id`, `sku`, `attribute_value_ids`, `available` (purchasable and
priced), `stock`, `price` (a display price), and `image_media_id`.

### Stock status

`Inventory\StockStatus::for( $product|$variant )` reports `in_stock`,
`low_stock` (at or under the item's `low_stock_threshold`), `backorder`, or
`out_of_stock`, with `purchasable()`. It never writes: a product with no
inventory row is in stock. A variable product takes its best variant; a
bundle follows its components. The quantity is included only when
`inventory.show_quantity` is on (off by default).

### Listings

Pricing and stocking one product runs a few queries; a listing would run
them per product. Load what both services read with the
`withDisplayData()` scope (or `loadMissing( Product::displayRelations() )` on
products you already have) and they read the loaded relations instead:

```php
$products = Product::query()->storefrontVisible()->withDisplayData()->paginate( 24 );

foreach ( $products as $product ) {
    $price = app( PriceDisplayResolver::class )->for( $product, 'USD' );
    $stock = StockStatus::for( $product );
}
```

The listing then runs the same queries for 2 products or 200: prices, stock
rows, and variants with theirs come from the eager load, and tax rates are
read once per request (`Tax\TaxRateCandidates`). `VariantResolver::matrix()`
loads its variants' prices and stock the same way. Reservations, checkout,
and refunds always read stock rows fresh; only display code uses loaded rows.

### Add-to-cart fields

A product type that implements `Contracts\ProvidesStorefrontOptions` describes
its add-to-cart form framework-agnostically: `storefrontOptions( $product )`
returns a list of fields. Core implements it for:

- `grouped`: one `quantity` field per visible child (`name` `items.{childId}`,
  `meta.product_id` / `variant_id`, `meta.add_separately` true), because each
  child is added to the cart as its own line;
- `bundled`: one read-only `info` field, "Includes", listing the members and
  quantities in `meta.items`.

Every field has:

| Key | Type | Meaning |
|---|---|---|
| `type` | string | `text`, `textarea`, `number`, `quantity`, `select`, `radio`, `checkbox`, `info`, or `swatch` (since 1.4.0). Satellites can add types through the `ap.ecommerce.storefrontOptions.fieldTypes` filter. |
| `name` | string | The cart option key, in dot or bracket notation (`frame.mat`, `frame[mat]`). |
| `label` | string | Translated label. |
| `options` | list | Choices, required (and non-empty) for `select`, `radio`, and `swatch`. See below. |
| `rules` | list or string | Laravel validation rules, enforced through `StorefrontOptionSchema::validate()`. |
| `required` | bool | Adds `required` to `rules`. |
| `default`, `help` | mixed, string | Initial value, help text. |
| `visible_when` | list | Since 1.4.0. `[ { field, in: [values] } ]`: the field shows only when every condition's `field` holds one of its `in` values (for a list value such as a multi-checkbox, any of them). An empty value never matches, so a field that hasn't been answered hides its dependants; `''` in `in` is ignored (a condition left with no values drops the field). |
| `meta` | object | Type-specific extras. Since 1.4.0, `meta.disables` is `{ value: { field: [values] } }`: choosing `value` in this field disables those values of other fields, client-side. Server validation stays authoritative. |

Each entry in `options` is `{ value, label }` (`value` a string or number)
and may carry (since 1.4.0):

| Key | Shape | Meaning |
|---|---|---|
| `price_delta` | `{ amount, currency }` | What the choice adds, in minor units (`amount` may be negative). For display; the type's `priceLine()` still decides the price. |
| `image` | `{ url, srcset?, alt }` | An image card for the choice. |
| `swatch` | `{ hex?, image_url?, alt }` | A colour (`#rgb`, `#rrggbb`, or `#rrggbbaa`) or texture image; at least one of `hex` / `image_url`. |
| `hint` | string | Short extra text ("+$5"). |
| `disabled` | bool | Shown but not selectable. |

`swatch` is a single choice rendered as swatches; a storefront that doesn't
support it renders a `radio`.

```php
public function storefrontOptions( Product $product ): array
{
    return [
        [
            'type'    => 'swatch',
            'name'    => 'color',
            'label'   => __( 'Color' ),
            'options' => [
                [ 'value' => 'black', 'label' => __( 'Black' ), 'swatch' => [ 'hex' => '#111111', 'alt' => __( 'Black' ) ] ],
                [
                    'value'       => 'gold',
                    'label'       => __( 'Metallic gold' ),
                    'swatch'      => [ 'image_url' => 'https://cdn.example.com/gold.png', 'alt' => __( 'Gold' ) ],
                    'image'       => [ 'url' => 'https://cdn.example.com/gold-card.jpg', 'srcset' => 'https://cdn.example.com/gold-card@2x.jpg 2x', 'alt' => __( 'Gold frame' ) ],
                    'price_delta' => [ 'amount' => 500, 'currency' => 'USD' ],
                    'hint'        => __( '+$5' ),
                ],
                [ 'value' => 'silver', 'label' => __( 'Silver' ), 'disabled' => true ],
            ],
            'meta'    => [ 'disables' => [ 'gold' => [ 'frame' => [ 'none' ] ] ] ],
        ],
        [
            'type'     => 'select',
            'name'     => 'frame',
            'label'    => __( 'Frame' ),
            'required' => true,
            'rules'    => [ 'in:none,oak' ],
            'options'  => [ [ 'value' => 'none', 'label' => __( 'No frame' ) ], [ 'value' => 'oak', 'label' => __( 'Black oak' ) ] ],
        ],
        [
            'type'         => 'select',
            'name'         => 'mat',
            'label'        => __( 'Mat' ),
            'required'     => true,
            'visible_when' => [ [ 'field' => 'frame', 'in' => [ 'oak' ] ] ],
            'options'      => [ [ 'value' => 'white', 'label' => __( 'White' ) ] ],
        ],
    ];
}
```

`GET products/{product}/purchase-options` and the GraphQL
`productPurchaseOptions` query serve the fields after
`Support\StorefrontOptionSchema::normalize()`, which never throws: a
malformed field (unknown type, bad name, a choice field with no valid
options, a malformed `visible_when`) or option is dropped, and malformed
option metadata or `meta.disables` is removed on its own, each with an
`ecommerce.storefront_options.*` warning on the `ecommerce` log channel.

**Hidden fields are not validated and are dropped.** A field whose
`visible_when` doesn't match (or that depends on a hidden field) must not be
validated or stored. Call the schema from `validateCartOptions()`:

```php
public function validateCartOptions( Product $product, array $options ): array
{
    // Drops hidden fields' values, validates the visible fields' rules
    // (ValidationException keyed `options.{name}`), returns what is left.
    $options = app( StorefrontOptionSchema::class )->validate( $this->storefrontOptions( $product ), $options );

    return array_intersect_key( $options, array_flip( [ 'color', 'frame', 'mat' ] ) );
}
```

The building blocks are public too: `isVisible( $field, $values )`,
`visibleFields( $fields, $values )`, `stripHidden( $fields, $options )`, and
`rules( $fields, $options )` (dot-path rules for the visible fields).

### Line option descriptions

Carts, orders, emails, and both APIs show a line's options as
human-readable rows (`option_lines`: `[ { label, value } ]`, since 1.4.0),
from `Support\LineOptionDescriber`:

- a type implementing `Contracts\DescribesLineOptions` describes its own
  options (`describeLineOptions( $product, $options )`), e.g.
  "Size: 16 × 20 in", "Paper: Lustre", "Frame: Black oak";
- other types get the default rows: the variant name (with an empty label),
  then each scalar option with its key headlined (`gift_message` →
  "Gift Message"; `true` → "Yes"). `variant_id`, `product_id`, keys starting
  with `_`, nested arrays (grouped `items`), and empty values are skipped.

`forCartItem( $item )` describes a cart line (the REST cart item resource and
the GraphQL `CartItem.option_lines`). At placement the rows are copied into
`order_items.product_snapshot.option_lines`, and `forOrderItem( $item )`
reads them from there (the order item resource, `OrderItem.option_lines`,
and the order notification templates), so a placed order keeps its wording
after the store renames a choice. Orders placed before 1.4.0 fall back to
describing the snapshot's options. Every result passes through
`ap.ecommerce.lineOptions.described`.

### Price quotes

`Services\PriceQuoteService::quote( $product, $options, $quantity, $currency )`
prices a product with a set of add-to-cart options without touching a cart
(since 1.4.0), for live prices as a shopper configures a product. It runs the
type's `validateCartOptions()` then `priceLine()` (for one unit and for the
quantity) and returns a `ValueObjects\PriceQuote`: `unitPrice()`,
`lineTotal()`, `currency()`, `quantity()`, `breakdown()`, `errors()`,
`isValid()`, and `toArray()`. A type implementing `Contracts\QuotesPrice`
adds breakdown rows (`[ { label, amount: Money } ]`).

**Promotions are not applied**: a quote is the list price, and nothing is
written, reserved, or dispatched. Rejected options don't throw: the quote
has zero prices and `errors` keyed `options.*` (a type's
`ValidationException` keys are prefixed with `options.`; an
`InvalidArgumentException` becomes `options`). A variant of another product
is `options.variant_id`; no price in the currency is `currency`.

Over REST, `POST products/{product}/quote` (public for storefront-visible
products, `catalog.read` rate policy, no Idempotency-Key):

```json
{ "options": { "size": "16x20", "frame": "oak" }, "quantity": 1, "currency": "USD" }
```

returns 200:

```json
{ "data": {
    "currency": "USD", "quantity": 1,
    "unit_price": { "amount": 18500, "currency": "USD", "formatted": "$185.00" },
    "line_total": { "amount": 18500, "currency": "USD", "formatted": "$185.00" },
    "breakdown": [
        { "label": "Print (16 × 20 in)", "amount": { "amount": 12000, "currency": "USD", "formatted": "$120.00" } },
        { "label": "Black oak frame", "amount": { "amount": 6500, "currency": "USD", "formatted": "$65.00" } }
    ],
    "errors": {}, "valid": true
} }
```

or 422 problem+json with `errors: [ { field: "options.frame", code, message } ]`,
or 404 when the product isn't on sale. GraphQL: `productQuote( product,
options, quantity, currency )` (see [graphql.md](graphql.md)). The result
passes through `ap.ecommerce.priceQuote.quoted`.

### Recording views

Storefronts call `Catalog\ProductViews::record( $product, ?$customer )` when
a product page is shown (REST storefronts post to
`POST products/{product}/views`, which needs an Idempotency-Key). It fires
`ap.ecommerce.product.viewed` `(Product $product, ?Customer $customer)` for
satellites such as recently-viewed; the engine stores nothing itself.

## Stock

Stock levels change only through audited adjustments:

```php
$products->adjustStock( $variant, -2, 'Damaged in transit' );
```

This creates the `inventory_items` row when missing and calls
`InventoryService::adjust()`, which fires the inventory hooks and writes an
`inventory.adjusted` activity entry. A reason is required. Falling to or
under the low-stock threshold fires `ProductStockLow` (webhook
`product.stock.low`), and running out fires `ProductOutOfStock`
(`product.out.of.stock`), next to the `ap.ecommerce.inventory.lowStock` /
`outOfStock` hooks.

### Back-in-stock subscriptions

Since 1.4.0, shoppers can ask to hear when an out-of-stock product (or one of
its variants) can be bought again:

```php
$service = app( BackInStockService::class );

$subscription = $service->subscribe( $product, $variant, 'ada@example.com', $customer, 'fr' );
$service->isSubscribed( $product, $variant, 'ada@example.com' ); // true
$service->unsubscribe( $subscription );
```

`subscribe()` lowercases and trims the email, links the customer record with
that email when no customer is passed, and returns the existing pending
subscription instead of a duplicate. A `null` variant subscribes to the
product as a whole. It throws `InvalidArgumentException` for an invalid
email or another product's variant. Storefront sign-up forms should throttle
it with the `ecommerce.backInStock.subscribe` rate policy
(`EcommerceRateLimiter::attempt()`).

When available stock (on hand less reserved) on a tracked,
non-backorderable row goes from zero or less to above zero — a restock, a
refund that restocks, or a released reservation, but not a rise from 3 to
5 — `InventoryService` fires `ap.ecommerce.inventory.backInStock`, and every
pending subscriber of that product or variant (plus product-level
subscribers when a variant restocks) is sent `inventory.back-in-stock.customer`
(see [notifications.md](notifications.md#back-in-stock)). Each subscription is
notified once. Bundles aren't notified when a member restocks.

## Categories and tags

- `ProductCategoryService`: `create()`, `update()`, `delete()` (children move up
  to the deleted category's parent), `reorder( $parentId, $ids )`. A category
  can't be moved under itself or one of its descendants.
- `ProductTagService`: `create()`, `findOrCreate( $name )`, `update()`,
  `delete()`, `merge( $source, $target )` (moves the products, then deletes the
  source).

Storefronts read them with `Catalog\CategoryTree` (the whole tree, cached
and rebuilt on any category change; a category and its descendants; lookup
by id or slug) and over REST with `GET categories`, `GET categories/{category}`
(id or slug, `include=parent,children`), `GET categories/{category}/products`
(every `GET products` filter, sort, and include), and `GET tags` (with each
tag's visible product count). `GET products` filters by
`filter[category]` (id or slug, with sub-categories unless
`filter[descendants]=0`) and `filter[tag]`.

## Errors

Catalog rules throw `Exceptions\ProductWriteException`, whose `errors` hold
`{ field, code, message }` rows (dotted fields for nested rows, e.g.
`prices.1.currency`). REST renders it as a 422 `product-write-failed` problem
and GraphQL returns the rows as `UserError`s.

| Code | When |
|---|---|
| `unknown-type`, `type-missing` | Type not registered / product is read-only |
| `required` | Name, slug, attribute key or label, value label, or price missing |
| `slug-taken`, `sku-taken` | Already used |
| `invalid-status`, `unknown-tax-class` | Bad status or tax class |
| `invalid-currency`, `invalid-amount`, `invalid-date`, `invalid-window`, `duplicate-price` | Bad price row |
| `image-source`, `invalid-url` | Image with no source, or a non-http(s) URL |
| `attribute-key-taken`, `duplicate-value`, `option-value`, `duplicate-combination` | Attribute and variant rules |
| `no-variation-attributes`, `too-many-variants` | Can't generate variants |
| `not-a-parent`, `child-self`, `child-missing`, `child-variant`, `duplicate-child`, `child-cycle`, `invalid-quantity` | Children rules |
| `invalid-relation-type`, `relation-self`, `duplicate-relation`, `relation-missing` | Related-product rules |
| `reason-required`, `invalid-threshold` | Stock rules |
| `in-carts` | A product in an open cart (not expired, not yet an order), or a variant in any cart, can't be deleted; archive it instead. `ecommerce:prune-carts` clears old carts daily. |
| `type-has-variants`, `type-has-children` | A type change would strand variants or grouped/bundled members |
| `unknown-id`, `category-cycle`, `merge-self` | Links, reorders, categories, tags |

## Meta

`meta` is merged one level deep: each top-level key you send replaces that
key whole (so `{ "digital": { "download_limit": 3 } }` replaces the whole
`digital` object), and keys you don't send are kept. Image URLs stored in
`meta.featured_image_url` (and a variant's `meta.image_url`) must be http(s).

## Rich text

`description`, `short_description`, and category descriptions are cleaned with
the security package's `kses()` in htmLawed's safe mode
(`ProductService::KSES_CONFIG`): scripts, styles, stylesheet links, forms,
embeds, event-handler and `style` attributes, and non-http(s) URLs are removed.

## Hooks

The lifecycle hooks fire from the models, so every write path fires them:
`ap.ecommerce.product.saving`, `.saved`, `.published` (status becomes
`active`), `.unpublished` (status leaves `active`, to `draft` or `archived`), `.deleted`, and
`ap.ecommerce.variant.saved`. Every hook except `.saving` waits for the
surrounding transaction to commit, so listeners see the product with its
prices and links and never see a write that rolled back. See
[hooks.md](hooks.md).

## REST and GraphQL

The `admin/products…`, `admin/product-categories…`, and `admin/product-tags…`
endpoints are listed in [api.md](api.md#admin-admin-authenticated-adminmutate);
the matching mutations are in [graphql.md](graphql.md#mutations).
