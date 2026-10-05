<?php

/**
 * CustomerOrderHistory.
 *
 * A shopper's own orders, newest first (#173): every order linked to their
 * customer record, optionally narrowed to a status group —
 *
 * - `open` — still being worked on (`pending`, `processing`);
 * - `completed` — `complete`;
 * - `cancelled` — `cancelled`, `refunded`, or `failed`;
 *
 * or to one `system_status`. Lines are eager-loaded.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Services;

use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\Order;
use Illuminate\Database\Eloquent\Builder;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class CustomerOrderHistory
{
    /**
     * Status groups a shopper can filter by.
     *
     * @since 1.0.0
     *
     * @var array<string, array<int, string>>
     */
    public const GROUPS = [
        'open'      => [ 'pending', 'processing' ],
        'completed' => [ 'complete' ],
        'cancelled' => [ 'cancelled', 'refunded', 'failed' ],
    ];

    /**
     * The customer's orders.
     *
     * @since 1.0.0
     *
     * @param  Customer     $customer  Customer.
     * @param  string|null  $status    A group key or a `system_status`.
     *
     * @return Builder<Order>
     */
    public function query( Customer $customer, ?string $status = null ): Builder
    {
        $query = Order::query()->forCustomer( $customer )->with( 'items' )->latest( 'placed_at' )->latest( 'id' );

        if ( null === $status || '' === $status ) {
            return $query;
        }

        return $query->whereIn( 'system_status', self::GROUPS[ $status ] ?? [ $status ] );
    }

    /**
     * One of the customer's orders, or null when it isn't theirs.
     *
     * @since 1.0.0
     *
     * @param  Customer  $customer  Customer.
     * @param  int       $orderId   Order id.
     *
     * @return Order|null
     */
    public function find( Customer $customer, int $orderId ): ?Order
    {
        return Order::query()->forCustomer( $customer )->with( 'items' )->find( $orderId );
    }
}
