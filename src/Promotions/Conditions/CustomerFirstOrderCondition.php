<?php

/**
 * CustomerFirstOrderCondition.
 *
 * `customer-first-order`: passes when the shopper has no prior order
 * (failed orders don't count). The shopper is identified by the cart's
 * customer, falling back to the cart email. A cart with neither passes —
 * the identity isn't known yet — so checkout MUST re-evaluate promotions
 * once the email is captured, before the order is placed.
 *
 * Kanban routing evaluates the condition against the placed order itself
 * ({@see OrderAwarePromotionCondition}), leaving that order out of the
 * history it checks.
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

use ArtisanPackUI\Ecommerce\Contracts\DescribesConfig;
use ArtisanPackUI\Ecommerce\Contracts\OrderAwarePromotionCondition;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Order;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class CustomerFirstOrderCondition implements OrderAwarePromotionCondition, DescribesConfig
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'customer-first-order';

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
        return __( "Customer's first order" );
    }

    /**
     * Fields this condition's `config` takes (engine issue #149).
     *
     * @since 1.0.0
     *
     * @return array<int, array<string, mixed>>
     */
    public function configSchema(): array
    {
        return [];
    }

    /**
     * @since 1.0.0
     *
     * @param  Cart                  $cart    Cart.
     * @param  array<string, mixed>  $config  Unused.
     *
     * @return bool
     */
    public function evaluate( Cart $cart, array $config ): bool
    {
        return ! $this->hasPriorOrder( $cart->customer_id, (string) $cart->email );
    }

    /**
     * Whether `$order` is the shopper's first (non-failed) order.
     *
     * @since 1.0.0
     *
     * @param  Order                 $order   Placed order.
     * @param  array<string, mixed>  $config  Unused.
     *
     * @return bool
     */
    public function evaluateOrder( Order $order, array $config ): bool
    {
        return ! $this->hasPriorOrder( $order->customer_id, (string) $order->email, (int) $order->id );
    }

    /**
     * Whether the shopper identified by customer id or email has a
     * non-failed order other than `$excludeOrderId`. An unknown shopper
     * has none.
     *
     * @since 1.0.0
     *
     * @param  int|null  $customerId      Customer id.
     * @param  string    $email           Shopper email.
     * @param  int|null  $excludeOrderId  Order to leave out.
     *
     * @return bool
     */
    protected function hasPriorOrder( ?int $customerId, string $email, ?int $excludeOrderId = null ): bool
    {
        $email = strtolower( trim( $email ) );

        if ( null === $customerId && '' === $email ) {
            return false;
        }

        return Order::query()
            ->where( 'system_status', '!=', 'failed' )
            ->when( null !== $excludeOrderId, static fn ( $query ) => $query->whereKeyNot( $excludeOrderId ) )
            ->where( function ( $query ) use ( $customerId, $email ): void {
                if ( null !== $customerId ) {
                    $query->orWhere( 'customer_id', $customerId );
                }

                if ( '' !== $email ) {
                    $query->orWhereRaw( 'LOWER(email) = ?', [ $email ] );
                }
            } )
            ->exists();
    }
}
