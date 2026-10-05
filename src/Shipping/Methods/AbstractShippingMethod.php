<?php

/**
 * AbstractShippingMethod.
 *
 * Shared helpers for the core {@see ShippingMethodType} drivers: cart
 * subtotal, cart weight, and currency-aware reading of configured amounts
 * (see {@see CurrencyConverter::fromConfigured()}).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Shipping\Methods;

use ArtisanPackUI\Ecommerce\Contracts\DescribesConfig;
use ArtisanPackUI\Ecommerce\Contracts\ShippingMethodType;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\Ecommerce\Services\CurrencyConverter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Money\Currency;
use Money\Money;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
abstract class AbstractShippingMethod implements ShippingMethodType, DescribesConfig
{
    /**
     * Grams per supported weight unit.
     *
     * @since 1.0.0
     *
     * @var array<string, float>
     */
    public const GRAMS_PER_UNIT = [
        'g'  => 1.0,
        'kg' => 1000.0,
        'oz' => 28.349523125,
        'lb' => 453.59237,
    ];

    /**
     * @since 1.0.0
     *
     * @param  CurrencyConverter  $converter  Currency converter for configured amounts.
     */
    public function __construct( protected readonly CurrencyConverter $converter )
    {
    }

    /**
     * Cart lines, loading the relation if needed.
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart.
     *
     * @return Collection<int, CartItem>
     */
    protected function items( Cart $cart ): Collection
    {
        return $cart->relationLoaded( 'items' ) ? $cart->items : $cart->items()->get();
    }

    /**
     * Sum of line totals less the cart discount, in the cart currency.
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart.
     *
     * @return Money
     */
    protected function subtotal( Cart $cart ): Money
    {
        // After discounts: a coupon that brings the cart under a free-shipping
        // threshold takes free shipping away (audit D17).
        return new Money(
            max( 0, (int) $this->items( $cart )->sum( 'line_total_amount' ) - max( 0, (int) $cart->discount_amount ) ),
            new Currency( strtoupper( (string) $cart->currency ) ),
        );
    }

    /**
     * Configured amount resolved into the cart currency.
     *
     * @since 1.0.0
     *
     * @param  mixed  $configured  Scalar (base currency) or currency map.
     * @param  Cart   $cart        Cart.
     *
     * @return Money|null
     */
    protected function configuredAmount( mixed $configured, Cart $cart ): ?Money
    {
        return $this->converter->fromConfigured( $configured, (string) $cart->currency );
    }

    /**
     * Total cart weight in grams. Variant weight wins over product weight;
     * lines with no weight count as zero.
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart.
     *
     * @return float
     */
    protected function weightInGrams( Cart $cart ): float
    {
        $items = $this->items( $cart );
        $items->loadMissing( [ 'product', 'variant' ] );

        $grams = 0.0;

        foreach ( $items as $item ) {
            $source = ( null !== $item->variant && null !== $item->variant->weight ) ? $item->variant : $item->product;

            if ( null === $source || null === $source->weight ) {
                continue;
            }

            // A variant that overrides the weight but not the unit inherits
            // the product's unit (a 5 lb product's variant weighing "5" is
            // 5 lb, not 5 g).
            $unit   = $source->weight_unit ?? $item->product?->weight_unit ?? 'g';
            $grams += (float) $source->weight * self::gramsPer( (string) $unit ) * (int) $item->quantity;
        }

        return $grams;
    }

    /**
     * Grams in one `$unit`. Case-insensitive; accepts common plurals
     * (`lbs`, `kgs`). An unknown unit is logged and treated as grams.
     *
     * @since 1.0.0
     *
     * @param  string  $unit  Weight unit.
     *
     * @return float
     */
    protected static function gramsPer( string $unit ): float
    {
        $normalized = rtrim( strtolower( trim( $unit ) ), 's' );
        $normalized = 'gram' === $normalized ? 'g' : $normalized;

        if ( ! isset( self::GRAMS_PER_UNIT[ $normalized ] ) ) {
            Log::channel( 'ecommerce' )->warning( 'Unknown weight unit; treating as grams.', [ 'unit' => $unit ] );

            return 1.0;
        }

        return self::GRAMS_PER_UNIT[ $normalized ];
    }

    /**
     * Zero in the cart currency.
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart.
     *
     * @return Money
     */
    protected function zero( Cart $cart ): Money
    {
        return new Money( 0, new Currency( strtoupper( (string) $cart->currency ) ) );
    }
}
