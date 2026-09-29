<?php

/**
 * PromotionUsageResource.
 *
 * REST representation of {@see \ArtisanPackUI\Ecommerce\Models\PromotionUsage}
 * (engine spec §9.13). Filterable via `ap.ecommerce.api.resource.promotionUsage`.
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

use Illuminate\Http\Request;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property \ArtisanPackUI\Ecommerce\Models\PromotionUsage $resource
 */
class PromotionUsageResource extends EcommerceResource
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'promotionUsage';

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
            'promotion_id'      => $this->resource->promotion_id,
            'order_id'          => $this->resource->order_id,
            'customer_id'       => $this->resource->customer_id,
            'amount_discounted' => $this->money( 'amount_discounted', 'currency' ),
            'created_at'        => $this->resource->created_at,
        ];
    }
}
