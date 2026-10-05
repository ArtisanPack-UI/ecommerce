<?php

/**
 * CustomerResource.
 *
 * REST representation of {@see \ArtisanPackUI\Ecommerce\Models\Customer}
 * (engine spec §9.13). Filterable via `ap.ecommerce.api.resource.customer`.
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
 * @property \ArtisanPackUI\Ecommerce\Models\Customer $resource
 */
class CustomerResource extends EcommerceResource
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'customer';

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
            'user_id'              => $this->resource->user_id,
            'email'                => $this->resource->email,
            'first_name'           => $this->resource->first_name,
            'last_name'            => $this->resource->last_name,
            'phone'                => $this->resource->phone,
            'accepts_marketing'    => $this->resource->accepts_marketing,
            'accepts_marketing_at' => $this->resource->accepts_marketing_at,
            'total_spent'          => $this->money( 'total_spent_amount' ),
            'orders_count'         => $this->resource->orders_count,
            'last_ordered_at'      => $this->resource->last_ordered_at,
            'meta'                 => $this->publicMetaFor( $request, 'ap.ecommerce.customer.publicMetaKeys', [] ),
            'created_at'           => $this->resource->created_at,
            'updated_at'           => $this->resource->updated_at,
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
            'addresses' => [ 'addresses', CustomerAddressResource::class ],
        ];
    }
}
