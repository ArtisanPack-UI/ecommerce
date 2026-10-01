<?php

/**
 * ProductImage factory.
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
use ArtisanPackUI\Ecommerce\Models\ProductImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductImage>
 *
 * @since 1.0.0
 */
class ProductImageFactory extends Factory
{
    /**
     * @var class-string<ProductImage>
     */
    protected $model = ProductImage::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'media_id'   => null,
            'image_url'  => $this->faker->imageUrl(),
            'alt_text'   => $this->faker->sentence( 4 ),
            'position'   => 0,
        ];
    }
}
