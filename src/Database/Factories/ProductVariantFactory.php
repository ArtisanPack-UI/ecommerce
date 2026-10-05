<?php

/**
 * ProductVariant factory.
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
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductVariant>
 *
 * @since 1.0.0
 */
class ProductVariantFactory extends Factory
{
    /**
     * @var class-string<ProductVariant>
     */
    protected $model = ProductVariant::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'sku'        => strtoupper( Str::random( 10 ) ),
            'name'       => $this->faker->words( 2, true ),
            'position'   => 0,
            'meta'       => [],
        ];
    }
}
