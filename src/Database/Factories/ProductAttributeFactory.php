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
        $key = $this->faker->unique()->randomElement( [ 'size', 'color', 'material', 'style', 'finish' ] );

        return [
            'product_id'   => Product::factory(),
            'key'          => $key,
            'label'        => ucfirst( $key ),
            'position'     => 0,
            'is_variation' => true,
        ];
    }
}
