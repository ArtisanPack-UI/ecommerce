<?php

/**
 * OrderItem factory.
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

use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 *
 * @since 1.0.0
 */
class OrderItemFactory extends Factory
{
    /**
     * @var class-string<OrderItem>
     */
    protected $model = OrderItem::class;

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
        $total    = $unit * $quantity;

        return [
            'order_id'            => Order::factory(),
            'product_id'          => Product::factory(),
            'product_variant_id'  => null,
            'product_snapshot'    => [
                'name'    => $this->faker->words( 3, true ),
                'sku'     => strtoupper( $this->faker->bothify( '???-####' ) ),
                'type'    => 'simple',
                'options' => [],
            ],
            'quantity'            => $quantity,
            'unit_price_amount'   => $unit,
            'unit_price_currency' => $currency,
            'discount_amount'     => 0,
            'discount_currency'   => $currency,
            'tax_amount'          => 0,
            'tax_currency'        => $currency,
            'shipping_amount'     => 0,
            'shipping_currency'   => $currency,
            'total_amount'        => $total,
            'total_currency'      => $currency,
            'fulfillment_status'  => 'unfulfilled',
            'meta'                => [],
        ];
    }
}
