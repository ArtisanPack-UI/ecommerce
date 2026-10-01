<?php

/**
 * ProductChild factory.
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
use ArtisanPackUI\Ecommerce\Models\ProductChild;
use ArtisanPackUI\Ecommerce\ProductTypes\BundledProductType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductChild>
 *
 * @since 1.0.0
 */
class ProductChildFactory extends Factory
{
    /**
     * @var class-string<ProductChild>
     */
    protected $model = ProductChild::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'parent_product_id' => Product::factory()->state( [ 'type' => BundledProductType::KEY ] ),
            'child_product_id'  => Product::factory(),
            'child_variant_id'  => null,
            'quantity'          => 1,
            'position'          => 0,
        ];
    }
}
