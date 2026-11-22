<?php

/**
 * PriceQuoteService.
 *
 * Prices a product with a set of add-to-cart options without touching a
 * cart (#224), for live prices on configurable product pages. The product
 * type validates the options (`validateCartOptions()`) and prices the line
 * (`priceLine()`); a type implementing {@see QuotesPrice} adds breakdown
 * rows. Promotions are not applied (this is the list price), and nothing
 * is written, reserved, or dispatched.
 *
 * Rejected options don't throw: the quote comes back with `errors` keyed
 * `options.*` (a type's `ValidationException` keys are prefixed with
 * `options.` when they aren't already; an `InvalidArgumentException`
 * becomes `options`) and zero prices. A variant that doesn't belong to the
 * product is `options.variant_id`; a product with no price in the currency
 * is `currency`.
 *
 * The result passes through `ap.ecommerce.priceQuote.quoted`.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.4.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Services;

use ArtisanPackUI\Ecommerce\Contracts\QuotesPrice;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use ArtisanPackUI\Ecommerce\ValueObjects\Currency as CurrencyVO;
use ArtisanPackUI\Ecommerce\ValueObjects\PriceQuote;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Money\Money;
use RuntimeException;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.4.0
 */
class PriceQuoteService
{
    /**
     * Quotes `$quantity` units of `$product` with `$options` in `$currency`.
     *
     * @since 1.4.0
     *
     * @param  Product               $product   Product.
     * @param  array<string, mixed>  $options   Raw add-to-cart options (with `variant_id` for a variant).
     * @param  int                   $quantity  Units.
     * @param  string                $currency  ISO 4217 code.
     *
     * @return PriceQuote
     */
    public function quote( Product $product, array $options, int $quantity, string $currency ): PriceQuote
    {
        try {
            $currency = CurrencyVO::of( $currency )->code();
        } catch ( Throwable ) {
            return PriceQuote::invalid( 'XXX', max( 0, $quantity ), [ 'currency' => [ __( 'The currency must be a three-letter ISO 4217 code.' ) ] ] );
        }

        if ( $quantity < 1 ) {
            return PriceQuote::invalid( $currency, max( 0, $quantity ), [ 'quantity' => [ __( 'The quantity must be at least 1.' ) ] ] );
        }

        if ( $product->typeIsMissing() ) {
            return PriceQuote::invalid( $currency, $quantity, [ 'options' => [ __( 'That product is not available right now.' ) ] ] );
        }

        $type = $product->productType();

        try {
            $validated = $type->validateCartOptions( $product, $options );
        } catch ( ValidationException $exception ) {
            return PriceQuote::invalid( $currency, $quantity, $this->optionErrors( $exception->errors() ) );
        } catch ( InvalidArgumentException ) {
            return PriceQuote::invalid( $currency, $quantity, [ 'options' => [ __( 'Choose the options this product needs.' ) ] ] );
        }

        if ( ! empty( $validated['variant_id'] ) && ! ProductVariant::query()->whereKey( (int) $validated['variant_id'] )->where( 'product_id', $product->getKey() )->exists() ) {
            return PriceQuote::invalid( $currency, $quantity, [ 'options.variant_id' => [ __( 'That variant is not available for this product.' ) ] ] );
        }

        try {
            $unit  = $type->priceLine( $product, $validated, 1, $currency );
            $total = $type->priceLine( $product, $validated, $quantity, $currency );
        } catch ( RuntimeException | InvalidArgumentException ) {
            return PriceQuote::invalid( $currency, $quantity, [ 'currency' => [ __( 'That product has no price in :currency.', [ 'currency' => $currency ] ) ] ] );
        }

        $quote = new PriceQuote( $unit, $total, $quantity, $this->breakdown( $product, $validated, $quantity, $currency ) );

        $filtered = applyFilters( 'ap.ecommerce.priceQuote.quoted', $quote, $product, $validated );

        return $filtered instanceof PriceQuote ? $filtered : $quote;
    }

    /**
     * The type's breakdown rows, keeping only well-formed rows in
     * `$currency`.
     *
     * @since 1.4.0
     *
     * @param  Product               $product   Product.
     * @param  array<string, mixed>  $options   Sanitised options.
     * @param  int                   $quantity  Units.
     * @param  string                $currency  ISO 4217 code.
     *
     * @return array<int, array{label: string, amount: Money}>
     */
    protected function breakdown( Product $product, array $options, int $quantity, string $currency ): array
    {
        $type = $product->productType();

        if ( ! $type instanceof QuotesPrice ) {
            return [];
        }

        try {
            $rows = $type->quoteBreakdown( $product, $options, $quantity, $currency );
        } catch ( Throwable $exception ) {
            report( $exception );

            return [];
        }

        $clean = [];

        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            if ( is_array( $row ) && is_string( $row['label'] ?? null ) && ( $row['amount'] ?? null ) instanceof Money && $currency === $row['amount']->getCurrency()->getCode() ) {
                $clean[] = [ 'label' => $row['label'], 'amount' => $row['amount'] ];
            }
        }

        return $clean;
    }

    /**
     * Validation errors keyed under `options.`.
     *
     * @since 1.4.0
     *
     * @param  array<string, array<int, string>>  $errors  Errors as the type raised them.
     *
     * @return array<string, array<int, string>>
     */
    protected function optionErrors( array $errors ): array
    {
        $keyed = [];

        foreach ( $errors as $key => $messages ) {
            $key           = (string) $key;
            $key           = 'options' === $key || str_starts_with( $key, 'options.' ) ? $key : 'options.' . $key;
            $keyed[ $key ] = array_merge( $keyed[ $key ] ?? [], array_values( array_map( 'strval', (array) $messages ) ) );
        }

        return [] === $keyed ? [ 'options' => [ __( 'Choose the options this product needs.' ) ] ] : $keyed;
    }
}
