<?php

/**
 * BaseAmounts.
 *
 * Converts order amounts into the store's current base currency for one
 * report run, following parent plan §16.4 (engine issue #146):
 *
 * 1. An amount in the order's currency is converted to the order's
 *    `base_currency` with the order's own `fx_rate_to_base_e8` snapshot,
 *    never a fresh rate, so historical revenue stays fixed.
 * 2. If that `base_currency` is not the store's current base (the owner
 *    changed it since), the result is converted again at today's
 *    cross-rate through {@see CurrencyConverter}, and the order is counted
 *    in {@see self::notices()} so the UI can flag it.
 * 3. If no cross-rate is available, the amount is left out and the order
 *    is counted as unconverted.
 *
 * Rates are major-unit to major-unit and amounts are minor units, so the
 * subunit difference between the two currencies is folded into the divisor
 * (the same arithmetic as {@see CurrencyConverter::convert()}).
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

use ArtisanPackUI\Ecommerce\Services\CurrencyConverter;
use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Money;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class BaseAmounts
{
    /**
     * The snapshot rate that means "same currency".
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const UNIT_RATE_E8 = 100_000_000;

    /**
     * The store's current base currency.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public readonly string $base;

    /**
     * Orders converted at today's cross-rate, keyed by order id.
     *
     * @since 1.0.0
     *
     * @var array<int, true>
     */
    protected array $converted = [];

    /**
     * Orders that could not be converted, keyed by order id.
     *
     * @since 1.0.0
     *
     * @var array<int, true>
     */
    protected array $unconverted = [];

    /**
     * Subunits per currency code.
     *
     * @since 1.0.0
     *
     * @var array<string, int>
     */
    protected array $subunits = [];

    /**
     * @since 1.0.0
     *
     * @param  CurrencyConverter  $converter  Supplies the base currency and cross-rates.
     */
    public function __construct( private readonly CurrencyConverter $converter )
    {
        $this->base = $converter->baseCurrency();
    }

    /**
     * Converts `$amount` (minor units of `$currency`) on an order into minor
     * units of the current base currency.
     *
     * @since 1.0.0
     *
     * @param  int|string  $amount     Amount in minor units of `$currency`.
     * @param  string      $currency   The amount's currency (the order currency).
     * @param  string      $orderBase  The order's snapshotted `base_currency`.
     * @param  int|string  $rateE8     The order's `fx_rate_to_base_e8`.
     * @param  int|string  $orderId    Order id, for the conversion notices.
     *
     * @return int|null Null when no cross-rate to the current base exists.
     */
    public function toBase( int|string $amount, string $currency, string $orderBase, int|string $rateE8, int|string $orderId ): ?int
    {
        $amount    = (int) $amount;
        $currency  = strtoupper( $currency );
        $orderBase = strtoupper( $orderBase );
        $orderId   = (int) $orderId;

        if ( 0 === $amount ) {
            return 0;
        }

        $inOrderBase = $this->applyRate( $amount, $currency, $orderBase, (int) $rateE8 );

        if ( $orderBase === $this->base ) {
            return $inOrderBase;
        }

        try {
            $converted = (int) $this->converter->convert( new Money( $inOrderBase, new Currency( $orderBase ) ), $this->base )->getAmount();
        } catch ( Throwable ) {
            $this->unconverted[ $orderId ] = true;

            return null;
        }

        $this->converted[ $orderId ] = true;

        return $converted;
    }

    /**
     * How many orders were converted at today's cross-rate, and how many
     * could not be converted at all.
     *
     * @since 1.0.0
     *
     * @return array{converted_orders: int, unconverted_orders: int}
     */
    public function notices(): array
    {
        return [
            'converted_orders'   => count( $this->converted ),
            'unconverted_orders' => count( $this->unconverted ),
        ];
    }

    /**
     * Applies an order's snapshot rate.
     *
     * @since 1.0.0
     *
     * @param  int     $amount     Minor units of `$currency`.
     * @param  string  $currency   From currency.
     * @param  string  $orderBase  To currency.
     * @param  int     $rateE8     Major-to-major rate × 10^8.
     *
     * @return int Minor units of `$orderBase`.
     */
    protected function applyRate( int $amount, string $currency, string $orderBase, int $rateE8 ): int
    {
        if ( $currency === $orderBase && self::UNIT_RATE_E8 === $rateE8 ) {
            return $amount;
        }

        $shift = $this->subunit( $orderBase ) - $this->subunit( $currency );

        return (int) ( new Money( $amount, new Currency( $currency ) ) )
            ->multiply( (string) $rateE8 )
            ->divide( bcpow( '10', (string) ( 8 - $shift ) ) )
            ->getAmount();
    }

    /**
     * Subunits for a currency (2 for USD, 0 for JPY), defaulting to 2.
     *
     * @since 1.0.0
     *
     * @param  string  $code  ISO 4217 code.
     *
     * @return int
     */
    protected function subunit( string $code ): int
    {
        if ( ! isset( $this->subunits[ $code ] ) ) {
            try {
                $this->subunits[ $code ] = ( new ISOCurrencies() )->subunitFor( new Currency( $code ) );
            } catch ( Throwable ) {
                $this->subunits[ $code ] = 2;
            }
        }

        return $this->subunits[ $code ];
    }
}
