<?php

/**
 * ShippingZone factory.
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

use ArtisanPackUI\Ecommerce\Models\ShippingZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShippingZone>
 *
 * @since 1.0.0
 */
class ShippingZoneFactory extends Factory
{
    /**
     * @var class-string<ShippingZone>
     */
    protected $model = ShippingZone::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name'            => $this->faker->words( 2, true ),
            'country_codes'   => [ 'US' ],
            'region_codes'    => null,
            'postal_patterns' => null,
            'priority'        => 0,
            'is_active'       => true,
        ];
    }
}
