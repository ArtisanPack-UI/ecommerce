<?php

/**
 * NoopReviewModerator.
 *
 * The engine's default {@see ReviewModerator}: leaves every review in the
 * moderation queue for a human. Engine spec §4.16.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Reviews;

use ArtisanPackUI\Ecommerce\Contracts\ReviewModerator;
use ArtisanPackUI\Ecommerce\Models\ProductReview;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class NoopReviewModerator implements ReviewModerator
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'noop';

    /**
     * @since 1.0.0
     *
     * @return string
     */
    public function key(): string
    {
        return self::KEY;
    }

    /**
     * @since 1.0.0
     *
     * @param  ProductReview  $review  Review.
     *
     * @return string
     */
    public function moderate( ProductReview $review ): string
    {
        return 'pending';
    }
}
