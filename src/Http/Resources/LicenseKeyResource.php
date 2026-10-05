<?php

/**
 * LicenseKeyResource.
 *
 * REST representation of {@see \ArtisanPackUI\Ecommerce\Models\LicenseKey}
 * (engine spec §9.13). Filterable via `ap.ecommerce.api.resource.licenseKey`.
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
 * @property \ArtisanPackUI\Ecommerce\Models\LicenseKey $resource
 */
class LicenseKeyResource extends EcommerceResource
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'licenseKey';

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
            'order_item_id'     => $this->resource->order_item_id,
            'digital_file_id'   => $this->resource->digital_file_id,
            'key'               => $this->resource->key,
            'activations_limit' => $this->resource->activations_limit,
            'activations_count' => $this->resource->activations_count,
            'expires_at'        => $this->resource->expires_at,
            'is_revoked'        => $this->resource->is_revoked,
            'revoked_at'        => $this->resource->revoked_at,
            'meta'              => $this->adminOnly( $request, $this->resource->meta ),
            'created_at'        => $this->resource->created_at,
            'updated_at'        => $this->resource->updated_at,
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
            'activations' => [ 'activations', LicenseActivationResource::class ],
            'order_item'  => [ 'orderItem', OrderItemResource::class ],
        ];
    }
}
