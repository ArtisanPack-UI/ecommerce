<?php

/**
 * ProductAttributeValue factory.
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

use ArtisanPackUI\Ecommerce\Models\ProductAttribute;
use ArtisanPackUI\Ecommerce\Models\ProductAttributeValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductAttributeValue>
 *
 * @since 1.0.0
 */
class ProductAttributeValueFactory extends Factory
{
    /**
     * @var class-string<ProductAttributeValue>
     */
    protected $model = ProductAttributeValue::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $value = $this->faker->unique()->word();

        return [
            'product_attribute_id' => ProductAttribute::factory(),
            'value'                => $value,
            'label'                => ucfirst( $value ),
            'swatch'               => null,
            'position'             => 0,
        ];
    }
}
