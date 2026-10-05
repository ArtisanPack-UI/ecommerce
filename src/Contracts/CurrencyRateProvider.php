<?php

/**
 * CurrencyRateProvider contract.
 *
 * Foreign-exchange rate source used to convert `product_prices` from a store's
 * base currency into a customer-facing currency when no explicit per-currency
 * row exists on the priceable.
 *
 * Engine spec §4.7.
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

use Money\Currency;

/**
 * CurrencyRateProvider contract.
 *
 * Implementations return the rate to multiply an amount in `$from` by to get
 * the equivalent amount in `$to`, expressed as an integer times 10^8 (see
 * engine spec §2.3) — never a float. Storing the rate as a signed 64-bit
 * fixed-point integer preserves exact arithmetic all the way through
 * `Money::multiply()` + `Money::divide()`, whereas a `float` would introduce
 * rounding errors in the very first hop.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
interface CurrencyRateProvider
{
    /**
     * Stable registry key (e.g. `config`, `frankfurter`).
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function key(): string;

    /**
     * Rate to multiply an amount in `$from` by to get the equivalent in `$to`,
     * as an integer of `rate * 10^8`.
     *
     * @since 1.0.0
     *
     * @param  Currency  $from  Source currency.
     * @param  Currency  $to    Target currency.
     *
     * @return int
     */
    public function getRateE8( Currency $from, Currency $to ): int;
}
