# Product search

Storefront search goes through a **search provider**: a
[`SearchProvider`](../src/Contracts/SearchProvider.php) takes a term plus
catalog filters and returns a page of products with facet counts and
suggestions. The core `default` provider uses Laravel Scout and the shared
catalog query, so search works with no extra infrastructure. Search
satellites (Meilisearch, Typesense, Algolia) register their own provider, and
storefronts never need to know which one is active.

`Product` also uses Scout's `Searchable` trait directly (parent plan §4.1).
The engine defaults to Scout's **`database`** driver:

```php
use ArtisanPackUI\Ecommerce\Models\Product;

$results = Product::search( 'linen shirt' )->get();

// Only products the storefront may show:
$results = Product::search( 'linen shirt' )
    ->query( fn ( $query ) => $query->storefrontVisible() )
    ->paginate( 24 );
```

Only `active` products are indexed (`shouldBeSearchable()`), and Scout removes
a product from the index when it stops being active. Products scheduled for
later publication are indexed but filtered out of results until their
`published_at` passes.

## Searching through the provider

In process, ask the registry for the active provider and pass it a
[`SearchQuery`](../src/Search/SearchQuery.php):

```php
use ArtisanPackUI\Ecommerce\Registries\SearchProviderRegistry;
use ArtisanPackUI\Ecommerce\Search\SearchQuery;

$result = app( SearchProviderRegistry::class )->active()->search( new SearchQuery(
    term: 'linen',
    filters: [ 'category' => 'shirts', 'in_stock' => '1', 'attributes' => [ 'color' => [ 'blue' ] ] ],
    sort: null,          // null = relevance
    page: 1,
    perPage: 24,
    currency: 'EUR',
    with: [ 'prices' ],
) );

$result->items;       // Collection<Product> on this page
$result->total;       // all matches
$result->facets;      // facet counts (see below)
$result->suggestions; // list<string>
$result->paginator(); // a LengthAwarePaginator, for resource responses
```

| `SearchQuery` argument | Type | Default | |
|---|---|---|---|
| `term` | `string` | — | The search term. |
| `filters` | `array` | `[]` | Catalog filters: `category`, `descendants`, `tag`, `price_min`, `price_max`, `attributes` (`key => value or values`), `in_stock`, `on_sale`, `featured`, `min_rating`, `ids`. |
| `sort` | `?string` | `null` | A `CatalogQuery::SORTS` value: `relevance`, `newest`, `price`, `-price`, `popularity`, `rating`, `name`, `position`. `null` means relevance. |
| `page` | `int` | `1` | 1-based page. |
| `perPage` | `int` | `25` | Page size. |
| `currency` | `?string` | `null` | Currency for price filters, the price sort, and price facets. `null` means the base currency. |
| `with` | `array` | `[]` | Relations to eager-load on the results. |

`SearchResult` carries `items`, `total`, `facets`, `suggestions`, `page`, and
`perPage`.

### The `default` provider

[`DatabaseSearchProvider`](../src/Search/DatabaseSearchProvider.php) (key
`default`) runs the term through
[`CatalogQuery`](../src/Catalog/CatalogQuery.php), the same query behind
`GET products`. That gives search every catalog filter, sort, and facet:

- **Matching.** The term (cut to 200 characters) goes to the Scout engine set
  by `search.driver`, which returns up to 1,000 matching product ids. Under
  the `database` driver the engine runs a case-insensitive `LIKE` across the
  name, slug, SKU, barcode, and descriptions of storefront-visible products.
- **Relevance.** With no `sort`, results come back in the engine's order.
  Under the `database` driver, names that start with the term rank first,
  then names that contain it, then other matches.
- **Filters.** The catalog filters then narrow the matches in the database,
  and only storefront-visible products are ever returned.
- **Facets.** `facets` is `CatalogQuery::facets()` over the filtered matches
  (see [the response shape](#rest-get-search)).
- **Suggestions.** The default provider returns none; `suggestions` is
  always `[]`.

An empty term finds nothing rather than listing the whole catalog.

## REST: `GET search`

`GET /api/ecommerce/v1/search` uses the active provider. It's public, rate
limited by the `ecommerce.catalog.read` policy, and cached like the other
catalog reads.

| Parameter | |
|---|---|
| `q` | Required. The term, 1 to 200 characters after trimming. |
| `filter[...]` | The `GET products` catalog filters: `category`, `descendants`, `tag`, `price_min`, `price_max`, `in_stock`, `on_sale`, `featured`, `min_rating`, `ids`. Unknown keys are dropped. |
| `attributes[key]` | Attribute values, as `attributes[color]=blue,green`. |
| `sort` | A catalog sort. Defaults to relevance. |
| `currency` | ISO 4217 code for price filters, the price sort, and price facets. |
| `include` | The same includes as `GET products`. |
| `page`, `per_page` | Page-number pagination. `per_page` defaults to `api.default_per_page` (25) and is capped at `api.max_per_page` (100). |

A missing or too-long `q` returns a `422` problem response. So does paging
past result 10,000 (`page × per_page > 10000`), because deep paging makes a
search engine scan the whole catalog.

The response is the `GET products` list shape (`data`, `links`, `meta`) with
two extra `meta` keys:

```json
{
  "data": [ { "id": 12, "name": "Linen shirt", "...": "..." } ],
  "meta": {
    "current_page": 1,
    "per_page": 24,
    "total": 3,
    "facets": {
      "price": { "min": 2900, "max": 5900, "currency": "USD" },
      "attributes": { "color": [ { "value": "blue", "label": "Blue", "count": 2 } ] },
      "categories": { "4": 3 },
      "tags": { "9": 1 },
      "stock": { "in_stock": 2, "total": 3 }
    },
    "suggestions": []
  }
}
```

`meta.facets` uses the shape the active provider returns. For the `default`
provider that is the price range in minor units of the query currency,
attribute value counts by attribute key, product counts by category id and by
tag id, and the in-stock count. `meta.suggestions` is a list of suggested
terms (empty for `default`). The `data` array runs through
`ap.ecommerce.api.list.product`, like `GET products`.

## GraphQL

```graphql
search(query: "linen", first: 24) { nodes { name slug } pageInfo { hasNextPage endCursor } }
```

The GraphQL `search` field queries Scout directly (`Product::search()`), not
the active search provider, and returns a cursor connection of
storefront-visible products. The query must be 1 to 200 characters. Cursors
must fall on a page boundary of the same `first`. For filters and facets, use
`products(filter: { search: "linen", ... })`, which runs through
`CatalogQuery`.

## Configuration

`config/artisanpack/ecommerce.php`:

| Key | Env | Default | |
|---|---|---|---|
| `search.provider` | `ECOMMERCE_SEARCH_PROVIDER` | `default` | Key of the `SearchProvider` that `GET search` uses. A key that isn't registered falls back to `default`. |
| `search.driver` | `ECOMMERCE_SEARCH_DRIVER` | `database` | Scout engine for products. Leave it empty to follow the app's `scout.driver`. |
| `search.index` | `ECOMMERCE_SEARCH_INDEX` | `ecommerce_products` | Index name on dedicated engines. `scout.prefix` is prepended, and the default avoids colliding with a host app's own `products` index. |
| `features.scout` | — | `true` | Turn product indexing off entirely (`shouldBeSearchable()` returns `false`). |

The product driver is independent of `scout.driver`, so a host app can keep its
own models on one engine and the catalog on another.

### What the `database` driver searches

The `database` driver runs a `LIKE` against each key of `toSearchableArray()`,
so it can only use real columns. Under that driver the document is cut back to
`Product::DATABASE_SEARCH_COLUMNS`: `id`, `name`, `slug`, `sku`, `barcode`,
`short_description`, `description`. Searching a numeric string through Scout
also matches the product id. Filters and facets then come from the catalog
query.

## The search document

On a dedicated engine, `toSearchableArray()` indexes the full document:

| Field | |
|---|---|
| `id`, `name`, `slug`, `sku`, `barcode`, `short_description`, `description` | Text fields. |
| `type`, `status`, `is_featured`, `avg_rating`, `reviews_count` | Product columns. |
| `published_at` | Unix timestamp, or `null`. |
| `category_ids`, `category_slugs` | The product's categories. |
| `tag_ids`, `tag_slugs` | The product's tags. |
| `attributes` | Attribute values by attribute key: `{ "color": [ "blue", "green" ] }`. |
| `prices` | The current price per currency in minor units: `{ "USD": 2900 }`. Only currencies with an undated price row are listed. |
| `in_stock` | Whether the product can be bought now. |

The document runs through `ap.ecommerce.product.searchableData` (below), so
satellites can add more. Configure the fields you filter or facet on as
filterable attributes in the engine's index settings. For Meilisearch that's
`scout.meilisearch.index-settings`, keyed by the full index name
(`{scout.prefix}ecommerce_products`). `scout:import` eager-loads categories,
tags, attributes, and prices for the whole batch.

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
(queue it with `SCOUT_QUEUE=true` on large catalogs). The `default` provider
then gets its matches and relevance order from that engine, while filters and
facets still come from the database.

## Writing a search satellite

A satellite that ships a dedicated engine usually does three things from its
service provider's `boot()`:

1. Sets `artisanpack.ecommerce.search.driver`, and registers any custom
   Scout engine with `EngineManager::extend()`.
2. Registers a `SearchProvider` that uses the engine's own filtering,
   faceting, and suggestions:

   ```php
   app( SearchProviderRegistry::class )->register( 'meilisearch', MeilisearchProvider::class, [
       'label' => __( 'Meilisearch' ),
   ] );
   ```

   The host then sets `ECOMMERCE_SEARCH_PROVIDER=meilisearch`. A provider must
   only ever return storefront-visible products, as `Product` models in
   `items`.
3. Optionally registers a `SearchIndexer` when it feeds an index outside
   Scout (see below).

Prove the provider with
[`SearchProviderContractTest`](contracts.md#searchprovidercontracttest).
Extend it, return your provider from `provider()`, and override `index()` to
push the test products to your engine (for example, through a fake client).

### `SearchIndexer`

A [`SearchIndexer`](../src/Contracts/SearchIndexer.php) keeps a custom search
transport in step with the catalog, next to Scout:

```php
interface SearchIndexer {
    public function key(): string;
    public function indexMany( iterable $products ): void; // add or update
    public function delete( Product $product ): void;
    public function flush(): void;                         // empty the index
}
```

Register it in `SearchIndexerRegistry` (empty by default):

```php
app( SearchIndexerRegistry::class )->register( 'acme:typesense', TypesenseIndexer::class );
```

The engine syncs every registered indexer from the product lifecycle hooks,
after the database transaction commits:

- `ap.ecommerce.product.saved`: a product shoppers can see is passed to
  `indexMany( [ $product ] )`. One they can't see (not active, or scheduled
  for later) is passed to `delete()`.
- `ap.ecommerce.product.deleted`: `delete()`.

A failing indexer is logged to the `ecommerce` log channel
(`ecommerce.search.indexer_failed`) and doesn't stop the other indexers or the
save. The engine never calls `flush()` and has no bulk re-index command for
indexers, so a satellite that needs a full rebuild ships its own command that
calls `flush()` and then `indexMany()` in chunks. There is no contract-test
suite for `SearchIndexer`; `ecommerce:verify-satellite` reports it as
`no-suite`.

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
document is restricted to real columns after the filter runs, and the facet
fields aren't built at all.
