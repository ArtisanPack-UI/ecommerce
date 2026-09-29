<?php

/**
 * TaxClass factory.
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

use ArtisanPackUI\Ecommerce\Models\TaxClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaxClass>
 *
 * @since 1.0.0
 */
class TaxClassFactory extends Factory
{
    /**
     * @var class-string<TaxClass>
     */
    protected $model = TaxClass::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key'   => $this->faker->unique()->slug( 2 ),
            'label' => $this->faker->words( 2, true ),
        ];
    }
}
