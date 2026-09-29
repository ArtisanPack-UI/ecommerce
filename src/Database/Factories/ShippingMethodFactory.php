<?php

/**
 * ShippingMethod factory.
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

use ArtisanPackUI\Ecommerce\Models\ShippingMethod;
use ArtisanPackUI\Ecommerce\Models\ShippingZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShippingMethod>
 *
 * @since 1.0.0
 */
class ShippingMethodFactory extends Factory
{
    /**
     * @var class-string<ShippingMethod>
     */
    protected $model = ShippingMethod::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'zone_id'       => ShippingZone::factory(),
            'key'           => 'flat-rate',
            'label'         => 'Standard shipping',
            'config'        => [ 'amount' => 500 ],
            'tax_class_key' => null,
            'is_active'     => true,
            'position'      => 0,
        ];
    }
}
