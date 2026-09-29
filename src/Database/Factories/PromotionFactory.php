<?php

/**
 * Promotion factory.
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

use ArtisanPackUI\Ecommerce\Models\Promotion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Promotion>
 *
 * @since 1.0.0
 */
class PromotionFactory extends Factory
{
    /**
     * @var class-string<Promotion>
     */
    protected $model = Promotion::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key'          => $this->faker->unique()->slug( 3 ),
            'name'         => $this->faker->words( 3, true ),
            'description'  => null,
            'source_type'  => Promotion::SOURCE_AUTOMATIC,
            'is_exclusive' => false,
            'priority'     => 0,
            'is_active'    => true,
        ];
    }

    /**
     * Unlocked by a coupon code.
     *
     * @since 1.0.0
     *
     * @return static
     */
    public function coupon(): static
    {
        return $this->state( [ 'source_type' => Promotion::SOURCE_COUPON ] );
    }

    /**
     * Blocks stacking.
     *
     * @since 1.0.0
     *
     * @return static
     */
    public function exclusive(): static
    {
        return $this->state( [ 'is_exclusive' => true ] );
    }
}
