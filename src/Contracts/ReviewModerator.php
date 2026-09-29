<?php

/**
 * ReviewModerator contract.
 *
 * Decides what happens to a freshly submitted product review before a
 * human sees it. The engine ships {@see \ArtisanPackUI\Ecommerce\Reviews\NoopReviewModerator}
 * (every review waits in the moderation queue); satellites bind their own
 * — an Akismet check, an LLM classifier, "auto-approve verified purchases"
 * — to this interface in the container.
 *
 * Engine spec §4.16, parent plan §5.12.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Contracts;

use ArtisanPackUI\Ecommerce\Models\ProductReview;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
interface ReviewModerator
{
    /**
     * Stable identifier, for logs and diagnostics.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function key(): string;

    /**
     * The verdict for `$review`: `approve`, `reject`, `spam`, or `pending`
     * (leave it for a human).
     *
     * @since 1.0.0
     *
     * @param  ProductReview  $review  The persisted, still-pending review.
     *
     * @return string One of `approve`, `reject`, `spam`, `pending`.
     */
    public function moderate( ProductReview $review ): string;
}
