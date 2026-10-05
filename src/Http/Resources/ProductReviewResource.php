<?php

/**
 * ProductReviewResource.
 *
 * REST representation of {@see \ArtisanPackUI\Ecommerce\Models\ProductReview}
 * (engine spec §9.13). Filterable via `ap.ecommerce.api.resource.review`.
 * The reviewer's email, customer / order links, and moderation details
 * are admin-only.
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
 * @property \ArtisanPackUI\Ecommerce\Models\ProductReview $resource
 */
class ProductReviewResource extends EcommerceResource
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'review';

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
            'product_id'           => $this->resource->product_id,
            'customer_id'          => $this->adminOnly( $request, $this->resource->customer_id ),
            'order_id'             => $this->adminOnly( $request, $this->resource->order_id ),
            'author_name'          => $this->resource->author_name,
            'author_email'         => $this->adminOnly( $request, $this->resource->author_email ),
            'rating'               => $this->resource->rating,
            'title'                => $this->resource->title,
            'body'                 => $this->resource->body,
            'is_verified_purchase' => $this->resource->is_verified_purchase,
            'status'               => $this->adminOnly( $request, $this->resource->status ),
            'approved_at'          => $this->resource->approved_at,
            'reviewed_by_user_id'  => $this->adminOnly( $request, $this->resource->reviewed_by_user_id ),
            'media_ids'            => $this->resource->media->pluck( 'media_id' )->values()->all(),
            'created_at'           => $this->resource->created_at,
            'updated_at'           => $this->resource->updated_at,
        ];
    }
}
