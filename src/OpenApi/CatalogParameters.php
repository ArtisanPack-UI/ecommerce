<?php

/**
 * CatalogParameters.
 *
 * Developer-facing descriptions of the storefront catalog's extra query
 * parameters (audit F2), referenced from the controllers'
 * `#[ApiOperation( query: … )]`. The filter, sort, and include allow-lists
 * stay on {@see \ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\ProductController}
 * next to the code that applies them.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\OpenApi;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class CatalogParameters
{
    /**
     * `GET products` and `GET categories/{category}/products`.
     *
     * @since 1.0.0
     *
     * @var array<string, array<string, mixed>>
     */
    public const PRODUCT_LIST = [
        'q'          => [ 'schema' => [ 'type' => 'string', 'maxLength' => 200 ], 'description' => 'Search term (same as filter[search]).' ],
        'currency'   => [ 'schema' => [ 'type' => 'string', 'pattern' => '^[A-Za-z]{3}$' ], 'description' => 'Currency for price filters, the price sort, and facets.' ],
        'attributes' => [ 'schema' => [ 'type' => 'object', 'additionalProperties' => [ 'type' => 'string' ] ], 'style' => 'deepObject', 'description' => 'Attribute values as attributes[key]=value1,value2.' ],
        'facets'     => [ 'schema' => [ 'type' => 'boolean' ], 'description' => 'Add meta.facets (price range, attribute, category, tag, and stock counts).' ],
        'page'       => [ 'schema' => [ 'type' => 'integer', 'minimum' => 1 ], 'description' => 'Page number for the computed sorts (price, popularity, relevance, newest), which page by number instead of cursor.' ],
    ];

    /**
     * `GET search`.
     *
     * @since 1.0.0
     *
     * @var array<string, array<string, mixed>>
     */
    public const SEARCH = [
        'q'          => [ 'schema' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ], 'required' => true, 'description' => 'Search term.' ],
        'filter'     => [ 'schema' => [ 'type' => 'object', 'additionalProperties' => [ 'type' => 'string' ] ], 'style' => 'deepObject', 'description' => 'The GET products catalog filters (category, tag, price_min, price_max, in_stock, on_sale, featured, min_rating, ids).' ],
        'attributes' => [ 'schema' => [ 'type' => 'object', 'additionalProperties' => [ 'type' => 'string' ] ], 'style' => 'deepObject', 'description' => 'Attribute values as attributes[key]=value1,value2.' ],
        'sort'       => [ 'schema' => [ 'type' => 'string', 'enum' => [ 'relevance', 'newest', 'price', '-price', 'popularity', 'rating', 'name', 'position' ] ], 'description' => 'Default relevance.' ],
        'currency'   => [ 'schema' => [ 'type' => 'string', 'pattern' => '^[A-Za-z]{3}$' ], 'description' => 'Currency for price filters, the price sort, and facets.' ],
        'page'       => [ 'schema' => [ 'type' => 'integer', 'minimum' => 1 ] ],
        'per_page'   => [ 'schema' => [ 'type' => 'integer', 'minimum' => 1 ], 'description' => 'Capped at artisanpack.ecommerce.api.max_per_page.' ],
    ];
}
