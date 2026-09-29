<?php

/**
 * ReviewApproved event.
 *
 * Dispatched by {@see \ArtisanPackUI\Ecommerce\Services\ReviewService::approve()}
 * once a review is visible on the storefront and counted in the product's
 * rating. Engine spec §7 event #29.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Events;

use ArtisanPackUI\Ecommerce\Models\ProductReview;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class ReviewApproved
{
    /**
     * @since 1.0.0
     *
     * @param  ProductReview  $review  The approved review.
     */
    public function __construct(
        public readonly ProductReview $review,
    ) {
    }
}
