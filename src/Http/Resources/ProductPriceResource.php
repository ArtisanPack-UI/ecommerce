<?php

/**
 * ProductPriceResource.
 *
 * REST representation of {@see \ArtisanPackUI\Ecommerce\Models\ProductPrice}
 * (engine spec §9.13). Filterable via `ap.ecommerce.api.resource.productPrice`.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Http\Resources;

use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use ArtisanPackUI\Ecommerce\Support\MorphType;
use Illuminate\Http\Request;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property \ArtisanPackUI\Ecommerce\Models\ProductPrice $resource
 */
class ProductPriceResource extends EcommerceResource
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'productPrice';

    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return array<string, mixed>
     */
    protected function fields( Request $request ): array
    {
        return [
            'priceable_type' => MorphType::is( $this->resource->priceable_type, ProductVariant::class ) ? 'variant' : 'product',
            'priceable_id'   => $this->resource->priceable_id,
            'currency'       => $this->resource->currency,
            'price'          => $this->money( 'price_amount', 'currency' ),
            'compare_at'     => null === $this->resource->compare_at_amount ? null : $this->money( 'compare_at_amount', 'currency' ),
            'cost'           => $this->adminOnly( $request, null === $this->resource->cost_amount ? null : $this->money( 'cost_amount', 'currency' ) ),
            'starts_at'      => $this->resource->starts_at,
            'ends_at'        => $this->resource->ends_at,
        ];
    }
}
