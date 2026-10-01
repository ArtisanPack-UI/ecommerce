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
| `inventory` | `track_inventory`, `allow_backorder`, `low_stock_threshold`; on create also an opening `quantity_on_hand`. |
| `stock_adjustment` | Update only: `{ delta, reason }`. |

`description` and `short_description` are cleaned with `kses()`. Slugs and
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

## Stock

Stock levels change only through audited adjustments:

```php
$products->adjustStock( $variant, -2, 'Damaged in transit' );
```

This creates the `inventory_items` row when missing and calls
`InventoryService::adjust()`, which fires the inventory hooks and writes an
`inventory.adjusted` activity entry. A reason is required.

## Categories and tags

- `ProductCategoryService`: `create()`, `update()`, `delete()` (children move up
  to the deleted category's parent), `reorder( $parentId, $ids )`. A category
  can't be moved under itself or one of its descendants.
- `ProductTagService`: `create()`, `findOrCreate( $name )`, `update()`,
  `delete()`, `merge( $source, $target )` (moves the products, then deletes the
  source).

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
| `reason-required`, `invalid-threshold` | Stock rules |
| `in-carts` | A product or variant in a shopper's cart can't be deleted (archive it instead) |
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
`active`), `.unpublished` (status becomes `archived`), `.deleted`, and
`ap.ecommerce.variant.saved`. Every hook except `.saving` waits for the
surrounding transaction to commit, so listeners see the product with its
prices and links and never see a write that rolled back. See
[hooks.md](hooks.md).

## REST and GraphQL

The `admin/products…`, `admin/product-categories…`, and `admin/product-tags…`
endpoints are listed in [api.md](api.md#admin-admin-authenticated-adminmutate);
the matching mutations are in [graphql.md](graphql.md#mutations).
