<?php

/**
 * CouponResource.
 *
 * REST representation of {@see \ArtisanPackUI\Ecommerce\Models\Coupon}
 * (engine spec §9.13). Filterable via `ap.ecommerce.api.resource.coupon`.
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
 * @property \ArtisanPackUI\Ecommerce\Models\Coupon $resource
 */
class CouponResource extends EcommerceResource
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'coupon';

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
            'promotion_id' => $this->resource->promotion_id,
            'code'         => $this->resource->code,
            'created_at'   => $this->resource->created_at,
            'updated_at'   => $this->resource->updated_at,
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, array{0: string, 1: class-string<EcommerceResource>}>
     */
    protected function relations(): array
    {
        return [
            'promotion' => [ 'promotion', PromotionResource::class ],
        ];
    }
}
