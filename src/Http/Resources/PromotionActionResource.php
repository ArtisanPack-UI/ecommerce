<?php

/**
 * PromotionActionResource.
 *
 * REST representation of {@see \ArtisanPackUI\Ecommerce\Models\PromotionAction}
 * (engine spec §9.13). Filterable via `ap.ecommerce.api.resource.promotionAction`.
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
 * @property \ArtisanPackUI\Ecommerce\Models\PromotionAction $resource
 */
class PromotionActionResource extends EcommerceResource
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'promotionAction';

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
            'action_type'  => $this->resource->type,
            'config'       => $this->resource->config,
        ];
    }
}
