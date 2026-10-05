<?php

/**
 * UpdateCustomerStats listener.
 *
 * Recalculates the customer's stats ({@see CustomerStatsService}) after a
 * payment (`ap.ecommerce.order.paid`), a refund (`order.refunded`), a
 * cancellation (`order.cancelled`), and when a customer claims a guest
 * order (`customer.orderClaimed`). Those hooks fire after their change
 * commits. A failure is logged, never surfaced: the stats heal on the next
 * recalculation.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Listeners;

use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Services\CustomerStatsService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class UpdateCustomerStats
{
    /**
     * @since 1.0.0
     *
     * @param  CustomerStatsService  $stats  Stats.
     */
    public function __construct(
        protected CustomerStatsService $stats,
    ) {
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function subscribe(): void
    {
        addAction( 'ap.ecommerce.order.paid', [ $this, 'forOrder' ] );
        addAction( 'ap.ecommerce.order.refunded', [ $this, 'forOrder' ] );
        addAction( 'ap.ecommerce.order.cancelled', [ $this, 'forOrder' ] );
        addAction( 'ap.ecommerce.customer.orderClaimed', [ $this, 'forCustomer' ] );
    }

    /**
     * @since 1.0.0
     *
     * @param  Order  $order  Order that changed.
     *
     * @return void
     */
    public function forOrder( Order $order ): void
    {
        $customerId = Order::query()->whereKey( $order->id )->value( 'customer_id' );

        if ( null !== $customerId ) {
            $this->recalculate( Customer::query()->find( $customerId ) );
        }
    }

    /**
     * @since 1.0.0
     *
     * @param  Customer  $customer  Customer who claimed an order.
     *
     * @return void
     */
    public function forCustomer( Customer $customer ): void
    {
        $this->recalculate( $customer );
    }

    /**
     * @since 1.0.0
     *
     * @param  Customer|null  $customer  Customer.
     *
     * @return void
     */
    protected function recalculate( ?Customer $customer ): void
    {
        if ( null === $customer ) {
            return;
        }

        try {
            $this->stats->recalculate( $customer );
        } catch ( Throwable $exception ) {
            Log::channel( 'ecommerce' )->error( 'Recalculating customer stats failed.', [
                'customer_id' => $customer->id,
                'exception'   => $exception->getMessage(),
            ] );
        }
    }
}
