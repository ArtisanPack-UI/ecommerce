<?php

/**
 * ReviewSubmitted event.
 *
 * Dispatched by {@see \ArtisanPackUI\Ecommerce\Services\ReviewService::submit()}
 * after a review is persisted (in whatever status moderation left it).
 * Engine spec §7 event #28.
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
class ReviewSubmitted
{
    /**
     * @since 1.0.0
     *
     * @param  ProductReview  $review  The submitted review.
     */
    public function __construct(
        public readonly ProductReview $review,
    ) {
    }
}
