<?php

/**
 * Coupon factory.
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

use ArtisanPackUI\Ecommerce\Models\Coupon;
use ArtisanPackUI\Ecommerce\Models\Promotion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Coupon>
 *
 * @since 1.0.0
 */
class CouponFactory extends Factory
{
    /**
     * @var class-string<Coupon>
     */
    protected $model = Coupon::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'promotion_id' => Promotion::factory()->coupon(),
            'code'         => strtoupper( $this->faker->unique()->bothify( 'SAVE-####??' ) ),
        ];
    }
}
