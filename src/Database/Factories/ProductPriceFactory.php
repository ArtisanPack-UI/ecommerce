<?php

/**
 * ProductPrice factory.
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

use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductPrice>
 *
 * @since 1.0.0
 */
class ProductPriceFactory extends Factory
{
    /**
     * @var class-string<ProductPrice>
     */
    protected $model = ProductPrice::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'priceable_type'    => ( new Product() )->getMorphClass(),
            'priceable_id'      => Product::factory(),
            'currency'          => 'USD',
            'price_amount'      => $this->faker->numberBetween( 500, 25000 ),
            'compare_at_amount' => null,
            'cost_amount'       => null,
            'starts_at'         => null,
            'ends_at'           => null,
        ];
    }

    /**
     * State: attach to a specific priceable (Product or ProductVariant).
     *
     * @since 1.0.0
     *
     * @param  \Illuminate\Database\Eloquent\Model  $priceable  The row this price attaches to.
     *
     * @return static
     */
    public function forPriceable( \Illuminate\Database\Eloquent\Model $priceable ): static
    {
        return $this->state( fn () => [
            'priceable_type' => $priceable->getMorphClass(),
            'priceable_id'   => $priceable->getKey(),
        ] );
    }
}
