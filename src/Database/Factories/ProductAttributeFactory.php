<?php

/**
 * ProductAttribute factory.
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
use ArtisanPackUI\Ecommerce\Models\ProductAttribute;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductAttribute>
 *
 * @since 1.0.0
 */
class ProductAttributeFactory extends Factory
{
    /**
     * @var class-string<ProductAttribute>
     */
    protected $model = ProductAttribute::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Compose the key from a random word + a short suffix so the
        // Faker unique pool cannot exhaust after five records the way a
        // fixed enum ([size, color, material, style, finish]) does.
        $word = $this->faker->word();
        $key  = $this->faker->unique()->regexify( '[a-z]{2,10}' ) . '-' . $word;

        return [
            'product_id'   => Product::factory(),
            'key'          => $key,
            'label'        => ucfirst( $word ),
            'position'     => 0,
            'is_variation' => true,
        ];
    }
}
