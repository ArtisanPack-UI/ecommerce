<?php

/**
 * CustomerStatsService.
 *
 * Keeps a customer's `orders_count`, `total_spent_amount` /
 * `total_spent_currency`, and `last_ordered_at` in step with their orders
 * (audit D15). Each recalculation reads them from the orders, so it is
 * idempotent and self-healing:
 *
 * - `orders_count` — paid orders (`paid` or `partially_refunded`);
 * - `total_spent_*` — those orders net of refunds, in the store's base
 *   currency (each order converted at the rate snapshotted when it was
 *   placed; orders that can't be converted are left out);
 * - `last_ordered_at` — when the latest of them was placed.
 *
 * {@see \ArtisanPackUI\Ecommerce\Listeners\UpdateCustomerStats} recalculates
 * after a payment, a refund, a cancellation, and an order claim.
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
use ArtisanPackUI\Ecommerce\Reports\BaseAmounts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class CustomerStatsService
{
    /**
     * Payment statuses that count as a paid order.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const PAID_STATUSES = [ 'paid', 'partially_refunded' ];

    /**
     * @since 1.0.0
     *
     * @param  CurrencyConverter  $converter   Converts order totals into the base currency.
     * @param  StoreCurrencies    $currencies  Base currency.
     */
    public function __construct(
        protected CurrencyConverter $converter,
        protected StoreCurrencies $currencies,
    ) {
    }

    /**
     * Recalculates and saves the customer's stats (under a row lock).
     *
     * @since 1.0.0
     *
     * @param  Customer  $customer  Customer.
     *
     * @return Customer The refreshed customer.
     */
    public function recalculate( Customer $customer ): Customer
    {
        return DB::transaction( function () use ( $customer ): Customer {
            $locked = Customer::query()->lockForUpdate()->findOrFail( $customer->id );
            $last   = $this->paidOrders( $locked )->max( 'placed_at' );

            $locked->forceFill( [
                'orders_count'         => $this->paidOrders( $locked )->count(),
                'total_spent_amount'   => $this->lifetimeSpend( $locked ),
                'total_spent_currency' => $this->currencies->base(),
                'last_ordered_at'      => null === $last ? null : Carbon::parse( $last ),
            ] )->saveQuietly();

            return $locked;
        } );
    }

    /**
     * The customer's paid orders.
     *
     * @since 1.0.0
     *
     * @param  Customer  $customer  Customer.
     *
     * @return Builder<Order>
     */
    public function paidOrders( Customer $customer ): Builder
    {
        return Order::query()
            ->where( 'customer_id', $customer->id )
            ->whereIn( 'payment_status', self::PAID_STATUSES );
    }

    /**
     * The customer's paid orders, net of refunds, in minor units of the
     * store's base currency. Orders that can't be converted are left out.
     *
     * @since 1.0.0
     *
     * @param  Customer  $customer  Customer.
     *
     * @return int
     */
    public function lifetimeSpend( Customer $customer ): int
    {
        $amounts = new BaseAmounts( $this->converter );
        $total   = 0;

        // One row per currency + rate snapshot rather than one per order.
        $groups = $this->paidOrders( $customer )
            ->toBase()
            ->selectRaw( 'currency, base_currency, fx_rate_to_base_e8, SUM(total_amount - total_refunded_amount) AS net, MIN(id) AS sample_id' )
            ->groupBy( 'currency', 'base_currency', 'fx_rate_to_base_e8' )
            ->get();

        foreach ( $groups as $group ) {
            $total += (int) $amounts->toBase(
                (int) $group->net,
                (string) $group->currency,
                (string) $group->base_currency,
                (int) $group->fx_rate_to_base_e8,
                (int) $group->sample_id,
            );
        }

        return $total;
    }
}
