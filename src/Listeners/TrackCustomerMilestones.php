<?php

/**
 * TrackCustomerMilestones listener.
 *
 * Listens to `ap.ecommerce.order.paid` and fires the customer milestone
 * hooks from the hooks spec:
 *
 * - `ap.ecommerce.customer.firstOrder` — the customer's first paid order;
 * - `ap.ecommerce.customer.becameVip` — the customer crossed a VIP
 *   threshold (`artisanpack.ecommerce.customers.vip.order_count` or
 *   `.lifetime_spend`). Both thresholds are off by default.
 *
 * Each milestone fires at most once per customer. The marker lives in
 * `customers.meta` (`first_paid_order_id`, `vip`) and is written under a
 * row lock on the customer, so two payments settling at once can't both
 * fire it. The hooks fire after that write commits.
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
use ArtisanPackUI\Ecommerce\Reports\BaseAmounts;
use ArtisanPackUI\Ecommerce\Services\CurrencyConverter;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class TrackCustomerMilestones
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
     * `customers.meta` key holding the id of the customer's first paid order.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const FIRST_ORDER_META_KEY = 'first_paid_order_id';

    /**
     * `customers.meta` key holding the VIP marker (`at`, `reason`, `order_id`).
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const VIP_META_KEY = 'vip';

    /**
     * `becameVip` reason when the paid-order count crossed the threshold.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const REASON_ORDER_COUNT = 'order_count';

    /**
     * `becameVip` reason when lifetime spend crossed the threshold.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const REASON_LIFETIME_SPEND = 'lifetime_spend';

    /**
     * @since 1.0.0
     *
     * @param  Config             $config     Reads the VIP thresholds per order.
     * @param  CurrencyConverter  $converter  Converts order totals into the base currency for lifetime spend.
     */
    public function __construct(
        protected Config $config,
        protected CurrencyConverter $converter,
    ) {
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function subscribe(): void
    {
        addAction( 'ap.ecommerce.order.paid', [ $this, 'orderPaid' ] );
    }

    /**
     * Fires `customer.firstOrder` / `customer.becameVip` for the order's
     * customer when this payment reached either milestone.
     *
     * @since 1.0.0
     *
     * @param  Order  $order  The order whose payment settled.
     *
     * @return void
     */
    public function orderPaid( Order $order ): void
    {
        if ( null === $order->customer_id ) {
            return;
        }

        try {
            [ $customer, $firstOrder, $vipReason ] = $this->recordMilestones( $order );
        } catch ( Throwable $e ) {
            // The payment is already captured; a bookkeeping failure must not
            // surface as a failed checkout. VIP is checked again on the next
            // paid order; a missed firstOrder is not re-fired.
            Log::channel( 'ecommerce' )->error( 'Recording customer milestones failed.', [
                'order_id'    => $order->id,
                'customer_id' => $order->customer_id,
                'exception'   => $e->getMessage(),
            ] );

            return;
        }

        if ( null === $customer ) {
            return;
        }

        if ( $firstOrder ) {
            doAction( 'ap.ecommerce.customer.firstOrder', $customer, $order );
        }

        if ( null !== $vipReason ) {
            doAction( 'ap.ecommerce.customer.becameVip', $customer, $vipReason );
        }
    }

    /**
     * Writes the milestone markers under a row lock on the customer.
     *
     * @since 1.0.0
     *
     * @param  Order  $order  The order whose payment settled.
     *
     * @return array{0: Customer|null, 1: bool, 2: string|null} The customer, whether this was the first paid order, and the VIP reason reached (if any).
     */
    protected function recordMilestones( Order $order ): array
    {
        return DB::transaction( function () use ( $order ): array {
            $customer = Customer::query()->lockForUpdate()->find( $order->customer_id );

            if ( null === $customer ) {
                return [ null, false, null ];
            }

            $meta       = (array) ( $customer->meta ?? [] );
            $firstOrder = false;
            $vipReason  = null;

            if ( ! isset( $meta[ self::FIRST_ORDER_META_KEY ] ) ) {
                $earlier = $this->paidOrders( $customer )->whereKeyNot( $order->id )->min( 'id' );

                $meta[ self::FIRST_ORDER_META_KEY ] = null === $earlier ? (int) $order->id : (int) $earlier;
                $firstOrder                         = null === $earlier;
            }

            if ( ! isset( $meta[ self::VIP_META_KEY ] ) ) {
                $vipReason = $this->vipReason( $customer );

                if ( null !== $vipReason ) {
                    $meta[ self::VIP_META_KEY ] = [
                        'at'       => Carbon::now()->toIso8601String(),
                        'reason'   => $vipReason,
                        'order_id' => (int) $order->id,
                    ];
                }
            }

            if ( $meta !== (array) ( $customer->meta ?? [] ) ) {
                $customer->meta = $meta;
                $customer->saveQuietly();
            }

            return [ $customer, $firstOrder, $vipReason ];
        } );
    }

    /**
     * Which VIP threshold the customer has reached, or `null` for none (or
     * when both are off). The order count is checked first.
     *
     * @since 1.0.0
     *
     * @param  Customer  $customer  Locked customer.
     *
     * @return string|null
     */
    protected function vipReason( Customer $customer ): ?string
    {
        $orderCount    = (int) $this->config->get( 'artisanpack.ecommerce.customers.vip.order_count', 0 );
        $lifetimeSpend = (int) $this->config->get( 'artisanpack.ecommerce.customers.vip.lifetime_spend', 0 );

        if ( $orderCount > 0 && $this->paidOrders( $customer )->count() >= $orderCount ) {
            return self::REASON_ORDER_COUNT;
        }

        if ( $lifetimeSpend > 0 && $this->lifetimeSpend( $customer ) >= $lifetimeSpend ) {
            return self::REASON_LIFETIME_SPEND;
        }

        return null;
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
    protected function lifetimeSpend( Customer $customer ): int
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

    /**
     * @since 1.0.0
     *
     * @param  Customer  $customer  Customer.
     *
     * @return Builder<Order>
     */
    protected function paidOrders( Customer $customer ): Builder
    {
        return Order::query()
            ->where( 'customer_id', $customer->id )
            ->whereIn( 'payment_status', self::PAID_STATUSES );
    }
}
