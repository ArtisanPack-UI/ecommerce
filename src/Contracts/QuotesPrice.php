<?php

/**
 * QuotesPrice.
 *
 * Optional on a {@see ProductType} (#224): explains a price quote with
 * breakdown rows ("Base print: $120.00", "Black oak frame: +$65.00") that
 * {@see \ArtisanPackUI\Ecommerce\Services\PriceQuoteService} adds to the
 * {@see \ArtisanPackUI\Ecommerce\ValueObjects\PriceQuote}. Types without it
 * get the unit price and line total only.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.4.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Contracts;

use ArtisanPackUI\Ecommerce\Models\Product;
use Money\Money;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.4.0
 */
interface QuotesPrice
{
    /**
     * Breakdown rows for a quote. Called only after
     * `validateCartOptions()` accepted `$options`, with the options it
     * returned. Amounts must be in `$currency`; rows in another currency
     * or of the wrong shape are dropped. Labels are already translated.
     *
     * @since 1.4.0
     *
     * @param  Product               $product   Product being quoted.
     * @param  array<string, mixed>  $options   Sanitised options.
     * @param  int                   $quantity  Units quoted.
     * @param  string                $currency  ISO 4217 code.
     *
     * @return array<int, array{label: string, amount: Money}>
     */
    public function quoteBreakdown( Product $product, array $options, int $quantity, string $currency ): array;
}
