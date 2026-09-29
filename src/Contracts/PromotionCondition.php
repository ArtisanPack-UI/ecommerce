<?php

/**
 * PromotionCondition contract.
 *
 * A pluggable eligibility rule for a promotion (`min-subtotal`,
 * `cart-contains-product`, `day-of-week`, …). Each `promotion_conditions`
 * row names a condition by registry key and supplies its `config`.
 * Satellites register novel conditions against
 * {@see \ArtisanPackUI\Ecommerce\Registries\PromotionConditionRegistry}.
 *
 * Engine spec §4.10, parent plan §5.9.
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

use ArtisanPackUI\Ecommerce\Models\Cart;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
interface PromotionCondition
{
    /**
     * Registry key (engine spec §2.5).
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function key(): string;

    /**
     * Human-readable label for the promotion builder.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function label(): string;

    /**
     * Whether `$cart` satisfies this condition.
     *
     * Implementations MUST NOT mutate the cart and MUST fail closed —
     * return `false`, never throw — when `$config` is missing or malformed.
     *
     * @since 1.0.0
     *
     * @param  Cart                  $cart    Cart being evaluated.
     * @param  array<string, mixed>  $config  User-supplied config from `promotion_conditions.config`.
     *
     * @return bool
     */
    public function evaluate( Cart $cart, array $config ): bool;
}
