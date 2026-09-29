<?php

/**
 * CustomerInGroupCondition.
 *
 * `customer-in-group`: passes when the cart's customer belongs to any of
 * `groups`. The engine has no groups table — a customer's groups come from
 * `customers.meta.groups`, passed through the `ap.ecommerce.customer.groups`
 * filter so a membership / CRM satellite can supply them. Guest carts
 * never pass.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Promotions\Conditions;

use ArtisanPackUI\Ecommerce\Contracts\PromotionCondition;
use ArtisanPackUI\Ecommerce\Models\Cart;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class CustomerInGroupCondition implements PromotionCondition
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'customer-in-group';

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
     * @return string
     */
    public function label(): string
    {
        return __( 'Customer in group' );
    }

    /**
     * @since 1.0.0
     *
     * @param  Cart                  $cart    Cart.
     * @param  array<string, mixed>  $config  `{ groups: string[] }`.
     *
     * @return bool
     */
    public function evaluate( Cart $cart, array $config ): bool
    {
        $wanted   = array_map( 'strval', (array) ( $config['groups'] ?? [] ) );
        $customer = $cart->customer;

        if ( [] === $wanted || null === $customer ) {
            return false;
        }

        $groups = (array) applyFilters( 'ap.ecommerce.customer.groups', (array) ( $customer->meta['groups'] ?? [] ), $customer );

        return [] !== array_intersect( $wanted, array_map( 'strval', $groups ) );
    }
}
