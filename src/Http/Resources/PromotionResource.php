<?php

/**
 * PromotionResource.
 *
 * REST representation of {@see \ArtisanPackUI\Ecommerce\Models\Promotion}
 * (engine spec §9.13). Filterable via `ap.ecommerce.api.resource.promotion`.
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
 * @property \ArtisanPackUI\Ecommerce\Models\Promotion $resource
 */
class PromotionResource extends EcommerceResource
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'promotion';

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
            'key'                      => $this->resource->key,
            'name'                     => $this->resource->name,
            'description'              => $this->resource->description,
            'source_type'              => $this->resource->source_type,
            'is_exclusive'             => $this->resource->is_exclusive,
            'priority'                 => $this->resource->priority,
            'starts_at'                => $this->resource->starts_at,
            'ends_at'                  => $this->resource->ends_at,
            'usage_limit_total'        => $this->resource->usage_limit_total,
            'usage_limit_per_customer' => $this->resource->usage_limit_per_customer,
            'times_used'               => $this->resource->times_used,
            'is_active'                => $this->resource->is_active,
            'created_at'               => $this->resource->created_at,
            'updated_at'               => $this->resource->updated_at,
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
            'conditions' => [ 'conditions', PromotionConditionResource::class ],
            'actions'    => [ 'actions', PromotionActionResource::class ],
            'coupons'    => [ 'coupons', CouponResource::class ],
        ];
    }
}
