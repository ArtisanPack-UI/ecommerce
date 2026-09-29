<?php

/**
 * PromotionAction contract.
 *
 * A pluggable discount effect for a promotion (`percent-off-cart`,
 * `buy-x-get-y`, `free-shipping`, …). Actions record their effect on the
 * {@see DiscountLedger}; they never touch the cart's Money totals directly.
 * Satellites register novel actions against
 * {@see \ArtisanPackUI\Ecommerce\Registries\PromotionActionRegistry}.
 *
 * Engine spec §4.11, parent plan §5.9.
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
use ArtisanPackUI\Ecommerce\Support\DiscountLedger;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
interface PromotionAction
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
     * Records this action's effect on `$ledger`.
     *
     * Implementations MUST NOT mutate the cart, MUST only discount through
     * the ledger (which clamps to what remains), and MUST be a no-op —
     * never throw — when `$config` is missing or malformed.
     *
     * @since 1.0.0
     *
     * @param  Cart                  $cart    Cart being discounted.
     * @param  DiscountLedger        $ledger  Running discount ledger.
     * @param  array<string, mixed>  $config  User-supplied config from `promotion_actions.config`.
     *
     * @return void
     */
    public function apply( Cart $cart, DiscountLedger $ledger, array $config ): void;
}
