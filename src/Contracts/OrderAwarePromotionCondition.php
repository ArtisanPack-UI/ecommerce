<?php

/**
 * OrderAwarePromotionCondition contract.
 *
 * Optional extension of {@see PromotionCondition} for conditions that can
 * judge a placed {@see Order} directly. Kanban board routing rules reuse
 * the promotion condition registry (parent plan §9.2); a condition that
 * doesn't implement this interface is evaluated against an in-memory cart
 * rebuilt from the order's lines, which is right for cart-shaped rules
 * (subtotal, products) but not for rules that look at order history —
 * `customer-first-order` would otherwise count the order being routed.
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

use ArtisanPackUI\Ecommerce\Models\Order;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
interface OrderAwarePromotionCondition extends PromotionCondition
{
    /**
     * Whether `$order` satisfies this condition.
     *
     * Same rules as {@see PromotionCondition::evaluate()}: no mutation,
     * fail closed on missing or malformed config.
     *
     * @since 1.0.0
     *
     * @param  Order                 $order   Placed order being evaluated.
     * @param  array<string, mixed>  $config  Condition config.
     *
     * @return bool
     */
    public function evaluateOrder( Order $order, array $config ): bool;
}
