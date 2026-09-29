# Product search

`Product` uses Laravel Scout's `Searchable` trait (parent plan §4.1). The engine
defaults to Scout's **`database`** driver, so search works with no extra
infrastructure:

```php
use ArtisanPackUI\Ecommerce\Models\Product;

$results = Product::search( 'linen shirt' )->get();

// Only products the storefront may show:
$results = Product::search( 'linen shirt' )
    ->query( fn ( $query ) => $query->storefrontVisible() )
    ->paginate( 24 );
```

The same search backs the storefront API:

- REST: `GET /api/ecommerce/v1/search?q=linen&per_page=24&page=2`
  (page-number paginated; `ecommerce.catalog.read` rate policy).
- GraphQL: `search(query: "linen", first: 24) { nodes { name slug } pageInfo { hasNextPage endCursor } }`.

Both return only storefront-visible products (`active`, published).

Only `active` products are indexed (`shouldBeSearchable()`), and Scout removes
a product from the index when it stops being active. Products scheduled for
later publication are indexed but filtered out of results until their
`published_at` passes.

## Configuration

`config/artisanpack/ecommerce.php`:

| Key | Env | Default | |
|---|---|---|---|
| `search.driver` | `ECOMMERCE_SEARCH_DRIVER` | `database` | Scout engine for products. Leave it empty to follow the app's `scout.driver`. |
| `search.index` | `ECOMMERCE_SEARCH_INDEX` | `ecommerce_products` | Index name on dedicated engines. `scout.prefix` is prepended, and the default avoids colliding with a host app's own `products` index. |
| `features.scout` | — | `true` | Turn product indexing off entirely (`shouldBeSearchable()` returns `false`). |

The product driver is independent of `scout.driver`, so a host app can keep its
own models on one engine and the catalog on another.

### What the `database` driver searches

The `database` driver runs a `LIKE` against each key of `toSearchableArray()`,
so it can only use real columns. Under that driver the document is cut back to
`Product::DATABASE_SEARCH_COLUMNS`: `id`, `name`, `slug`, `sku`, `barcode`,
`short_description`, `description`. Searching a numeric string also matches the
product id.

## Swapping to Meilisearch, Typesense, or Algolia

Dedicated engines are **satellite territory**: the engine doesn't depend on any
search client. To move the catalog to one:

1. Install the engine's PHP client and configure it as the Scout docs describe:

   | Engine | Package | Config |
   |---|---|---|
   | Meilisearch | `meilisearch/meilisearch-php` + `http-interop/http-factory-guzzle` | `MEILISEARCH_HOST`, `MEILISEARCH_KEY` |
   | Typesense | `typesense/typesense-php` | `TYPESENSE_API_KEY`, `TYPESENSE_HOST`, plus a collection schema in `scout.typesense.model-settings` |
   | Algolia | `algolia/algoliasearch-client-php` | `ALGOLIA_APP_ID`, `ALGOLIA_SECRET` |

2. Point the catalog at it:

   ```dotenv
   ECOMMERCE_SEARCH_DRIVER=meilisearch
   ```

3. Import the existing catalog:

   ```bash
   php artisan scout:import "ArtisanPackUI\Ecommerce\Models\Product"
   ```

After that Scout keeps the index current as products are saved and deleted
(queue it with `SCOUT_QUEUE=true` on large catalogs).

On a dedicated engine the full document is indexed: the text columns above plus
`type`, `status`, `avg_rating`, `reviews_count`, and `published_at` (Unix
timestamp), along with anything added through the filter below. Filter on
`status` / `type` with Scout's `where()`, and configure them as filterable
attributes in the engine's index settings. For Meilisearch that's
`scout.meilisearch.index-settings`, keyed by the full index name
(`{scout.prefix}ecommerce_products`).

A satellite package that ships an engine (for example an
`ecommerce-meilisearch` satellite with a tuned index schema) sets
`artisanpack.ecommerce.search.driver` from its own service provider and
registers any custom engine with `EngineManager::extend()`.

## `ap.ecommerce.product.searchableData`

Filter the indexed document to add fields:

```php
addFilter( 'ap.ecommerce.product.searchableData', function ( array $data, Product $product ): array {
    $data['brand']        = $product->meta['brand'] ?? null;
    $data['variant_skus'] = $product->variants->pluck( 'sku' )->filter()->values()->all();

    return $data;
} );
```

| | |
|---|---|
| Type | filter |
| Fired | `Product::toSearchableArray()`, each time Scout indexes a product |
| Payload | `(array $data, Product $product)` |
| Return | `array` — the document to index |

Added fields reach dedicated engines only: under the `database` driver the
document is restricted to real columns after the filter runs.
