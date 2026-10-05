<?php

/**
 * CheckoutStart.
 *
 * What {@see \ArtisanPackUI\Ecommerce\Services\CheckoutService::start()}
 * did: the cart (with its stock now held) and the lines it had to reduce
 * or remove because not enough was left.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\ValueObjects;

use ArtisanPackUI\Ecommerce\Models\Cart;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class CheckoutStart
{
    /**
     * @since 1.0.0
     *
     * @param  Cart                                                                              $cart         The cart.
     * @param  array<int, array{item_id: int, product_id: int, requested: int, available: int}>  $adjustments  Lines reduced (available > 0) or removed (available = 0).
     */
    public function __construct(
        public readonly Cart $cart,
        public readonly array $adjustments = [],
    ) {
    }

    /**
     * Whether any line had to change.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function hasAdjustments(): bool
    {
        return [] !== $this->adjustments;
    }
}
