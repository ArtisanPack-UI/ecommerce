<?php

/**
 * CartItem factory.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Database\Factories;

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CartItem>
 *
 * @since 1.0.0
 */
class CartItemFactory extends Factory
{
    /**
     * @var class-string<CartItem>
     */
    protected $model = CartItem::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $currency = 'USD';
        $unit     = 1_000;
        $quantity = 1;

        return [
            'cart_id'                => Cart::factory(),
            'product_id'             => Product::factory(),
            'product_variant_id'     => null,
            'quantity'               => $quantity,
            'unit_price_amount'      => $unit,
            'unit_price_currency'    => $currency,
            'line_subtotal_amount'   => $unit * $quantity,
            'line_subtotal_currency' => $currency,
            'line_total_amount'      => $unit * $quantity,
            'line_total_currency'    => $currency,
            'options'                => [],
            'meta'                   => [],
            'options_hash'           => CartItem::hashOptions( [] ),
        ];
    }
}
