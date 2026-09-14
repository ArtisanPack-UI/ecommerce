<?php

/**
 * Product factory.
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
use ArtisanPackUI\Ecommerce\ProductTypes\DigitalProductType;
use ArtisanPackUI\Ecommerce\ProductTypes\SimpleProductType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 *
 * @since 1.0.0
 */
class ProductFactory extends Factory
{
    /**
     * @var class-string<Product>
     */
    protected $model = Product::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->unique()->words( 3, true );

        return [
            'type'        => SimpleProductType::KEY,
            'name'        => $name,
            'slug'        => Str::slug( $name ) . '-' . $this->faker->unique()->numberBetween( 1000, 999999 ),
            'sku'         => strtoupper( Str::random( 8 ) ),
            'description' => $this->faker->paragraph(),
            'status'      => 'active',
            'is_taxable'  => true,
            'meta'        => [],
        ];
    }

    /**
     * State: `simple` product type.
     *
     * @since 1.0.0
     *
     * @return static
     */
    public function simple(): static
    {
        return $this->state( fn () => [ 'type' => SimpleProductType::KEY ] );
    }

    /**
     * State: `digital` product type.
     *
     * @since 1.0.0
     *
     * @return static
     */
    public function digital(): static
    {
        return $this->state( fn () => [ 'type' => DigitalProductType::KEY ] );
    }

    /**
     * State: `draft` status.
     *
     * @since 1.0.0
     *
     * @return static
     */
    public function draft(): static
    {
        return $this->state( fn () => [ 'status' => 'draft' ] );
    }
}
