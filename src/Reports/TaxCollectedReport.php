<?php

/**
 * TaxCollectedReport.
 *
 * Tax charged on orders placed in the range, by rate label and
 * jurisdiction (engine issue #146), for filing.
 *
 * Per-rate detail comes from `orders.meta.tax_breakdown` when the code that
 * placed the order stored it — a list of
 * `{ label, amount, rate_ubps?, country_code?, region_code? }` with
 * `amount` in minor units of the order currency, i.e. the `breakdown` of
 * {@see \ArtisanPackUI\Ecommerce\ValueObjects\TaxResult::toArray()} plus the
 * jurisdiction. Orders without it — or whose breakdown does not add up to
 * `tax_amount` — contribute one row: their `tax_amount` under the store's
 * tax label. A missing jurisdiction falls back to the
 * shipping address, then the billing address.
 *
 * Refunds issued in the range reduce the rows by the tax they returned
 * ({@see RefundSplit}), split across the order's breakdown, so the report is
 * tax collected net of refunds for the period.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Reports;

use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Support\TaxLabel;
use InvalidArgumentException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class TaxCollectedReport extends Report
{
    /**
     * `orders.meta` key holding the per-rate breakdown.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const META_KEY = 'tax_breakdown';

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     *
     * @param  ReportRange|null      $range    Range.
     * @param  array<string, mixed>  $options  Unused.
     *
     * @throws InvalidArgumentException Without a range.
     *
     * @return array<string, mixed>
     */
    public function run( ?ReportRange $range, array $options = [] ): array
    {
        if ( null === $range ) {
            throw new InvalidArgumentException( __( 'The tax collected report needs a date range.' ) );
        }

        $amounts      = $this->amounts();
        $defaultLabel = TaxLabel::for();
        $rows         = [];
        $orders       = [];

        Order::query()
            ->toBase()
            ->select( [ 'id', 'currency', 'base_currency', 'fx_rate_to_base_e8', 'tax_amount', 'shipping_address', 'billing_address', 'meta' ] )
            ->whereNotNull( 'placed_at' )
            ->whereBetween( 'placed_at', $range->queryBounds() )
            ->whereIn( 'payment_status', self::SALE_PAYMENT_STATUSES )
            ->lazyById( 1000 )
            ->each( function ( object $order ) use ( $amounts, $defaultLabel, &$rows, &$orders ): void {
                $address = self::address( $order->shipping_address ) ?? self::address( $order->billing_address ) ?? [];

                foreach ( $this->entries( $order, $defaultLabel ) as $entry ) {
                    $amount = $amounts->toBase( $entry['amount'], (string) $order->currency, (string) $order->base_currency, $order->fx_rate_to_base_e8, $order->id );

                    if ( null === $amount || 0 === $amount ) {
                        continue;
                    }

                    $this->addToRow( $rows, $entry, $address, $amount, (int) $order->id );
                    $orders[ $order->id ] = true;
                }
            } );

        // Refunds issued in the range take back the tax they returned, from
        // the same jurisdiction rows in proportion to the order's breakdown.
        RefundSplit::each( $range->queryBounds(), function ( object $refund, array $split ) use ( $amounts, $defaultLabel, &$rows ): void {
            if ( 0 === $split['tax'] ) {
                return;
            }

            $order   = (object) [ 'tax_amount' => $refund->order_tax, 'meta' => $refund->meta ];
            $entries = $this->entries( $order, $defaultLabel );
            $address = self::address( $refund->shipping_address ) ?? self::address( $refund->billing_address ) ?? [];
            $shares  = self::apportion( $split['tax'], array_column( $entries, 'amount' ) );

            foreach ( $entries as $index => $entry ) {
                $amount = $amounts->toBase( $shares[ $index ] ?? 0, (string) $refund->currency, (string) $refund->base_currency, $refund->fx_rate_to_base_e8, $refund->order_id );

                if ( null === $amount || 0 === $amount ) {
                    continue;
                }

                $this->addToRow( $rows, $entry, $address, -$amount, null );
            }
        } );

        $rows = array_map( static fn ( array $row ): array => [ ...$row, 'orders' => count( $row['orders'] ) ], array_values( $rows ) );

        usort( $rows, static fn ( array $a, array $b ): int => [ $a['jurisdiction'], $a['label'], $a['rate_ubps'] ?? 0 ] <=> [ $b['jurisdiction'], $b['label'], $b['rate_ubps'] ?? 0 ] );

        return $this->result( $range, $amounts, [
            'totals' => [
                'amount' => array_sum( array_column( $rows, 'amount' ) ),
                'orders' => count( $orders ),
            ],
            'rows'   => $rows,
        ] );
    }

    /**
     * Adds `$amount` to the row for `$entry`'s rate and jurisdiction,
     * creating it when needed.
     *
     * @since 1.0.0
     *
     * @param  array<string, array<string, mixed>>  $rows     Rows by key.
     * @param  array<string, mixed>                 $entry    Tax entry.
     * @param  array<string, mixed>                 $address  Order address (jurisdiction fallback).
     * @param  int                                  $amount   Base-currency amount (negative for a refund).
     * @param  int|null                             $orderId  Order to count, or null (refunds don't add orders).
     *
     * @return void
     */
    protected function addToRow( array &$rows, array $entry, array $address, int $amount, ?int $orderId ): void
    {
        $country = strtoupper( (string) ( $entry['country_code'] ?? $address['country_code'] ?? '' ) );
        $region  = strtoupper( (string) ( $entry['region_code'] ?? $address['region_code'] ?? $address['region'] ?? '' ) );
        $key     = implode( '|', [ $entry['label'], (string) ( $entry['rate_ubps'] ?? '' ), $country, $region ] );

        $rows[ $key ] ??= [
            'label'        => $entry['label'],
            'rate_ubps'    => $entry['rate_ubps'],
            'country_code' => '' === $country ? null : $country,
            'region_code'  => '' === $region ? null : $region,
            'jurisdiction' => implode( '-', array_filter( [ $country, $region ] ) ),
            'amount'       => 0,
            'orders'       => [],
        ];

        $rows[ $key ]['amount'] += $amount;

        if ( null !== $orderId ) {
            $rows[ $key ]['orders'][ $orderId ] = true;
        }
    }

    /**
     * Splits `$amount` across `$weights` (largest remainder, so the parts
     * sum exactly). An even split when the weights are all zero.
     *
     * @since 1.0.0
     *
     * @param  int              $amount   Amount.
     * @param  array<int, int>  $weights  Weights.
     *
     * @return array<int, int>
     */
    protected static function apportion( int $amount, array $weights ): array
    {
        $weights = array_map( static fn ( int $weight ): int => max( 0, $weight ), $weights );
        $sum     = array_sum( $weights );
        $count   = count( $weights );

        if ( 0 === $count ) {
            return [];
        }

        if ( 0 === $sum ) {
            $weights = array_fill( 0, $count, 1 );
            $sum     = $count;
        }

        $parts      = [];
        $remainders = [];

        foreach ( $weights as $index => $weight ) {
            $parts[ $index ]      = intdiv( $amount * $weight, $sum );
            $remainders[ $index ] = ( $amount * $weight ) % $sum;
        }

        arsort( $remainders );

        foreach ( array_keys( array_slice( $remainders, 0, $amount - array_sum( $parts ), true ) ) as $index ) {
            ++$parts[ $index ];
        }

        return $parts;
    }

    /**
     * The order's tax entries in order-currency minor units.
     *
     * @since 1.0.0
     *
     * @param  object  $order         Order row.
     * @param  string  $defaultLabel  Label for orders without a breakdown.
     *
     * @return array<int, array{label: string, amount: int, rate_ubps: int|null, country_code?: string|null, region_code?: string|null}>
     */
    protected function entries( object $order, string $defaultLabel ): array
    {
        $meta      = is_string( $order->meta ) ? json_decode( $order->meta, true ) : $order->meta;
        $breakdown = is_array( $meta ) ? ( $meta[ self::META_KEY ] ?? null ) : null;
        $entries   = [];

        if ( is_array( $breakdown ) ) {
            foreach ( $breakdown as $entry ) {
                if ( ! is_array( $entry ) || ! isset( $entry['amount'] ) || ! is_numeric( $entry['amount'] ) ) {
                    continue;
                }

                $entries[] = [
                    'label'        => is_string( $entry['label'] ?? null ) && '' !== $entry['label'] ? $entry['label'] : $defaultLabel,
                    'amount'       => (int) $entry['amount'],
                    'rate_ubps'    => isset( $entry['rate_ubps'] ) && is_numeric( $entry['rate_ubps'] ) ? (int) $entry['rate_ubps'] : null,
                    'country_code' => isset( $entry['country_code'] ) ? (string) $entry['country_code'] : null,
                    'region_code'  => isset( $entry['region_code'] ) ? (string) $entry['region_code'] : null,
                ];
            }
        }

        // A breakdown that does not add up to the order's tax is ignored, so
        // the report always totals what was actually charged.
        if ( [] === $entries || array_sum( array_column( $entries, 'amount' ) ) !== (int) $order->tax_amount ) {
            $entries = [ [ 'label' => $defaultLabel, 'amount' => (int) $order->tax_amount, 'rate_ubps' => null ] ];
        }

        return $entries;
    }

    /**
     * Decodes an address column.
     *
     * @since 1.0.0
     *
     * @param  mixed  $value  Raw column.
     *
     * @return array<string, mixed>|null
     */
    protected static function address( mixed $value ): ?array
    {
        $decoded = is_string( $value ) ? json_decode( $value, true ) : $value;

        return is_array( $decoded ) && [] !== $decoded ? $decoded : null;
    }
}
