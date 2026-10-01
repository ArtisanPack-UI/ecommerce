<?php

/**
 * ProductCategory factory.
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

use ArtisanPackUI\Ecommerce\Models\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductCategory>
 *
 * @since 1.0.0
 */
class ProductCategoryFactory extends Factory
{
    /**
     * @var class-string<ProductCategory>
     */
    protected $model = ProductCategory::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->unique()->words( 2, true );

        return [
            'parent_id'   => null,
            'name'        => ucwords( $name ),
            'slug'        => Str::slug( $name ) . '-' . $this->faker->unique()->numberBetween( 1000, 999999 ),
            'description' => null,
            'position'    => 0,
        ];
    }
}
