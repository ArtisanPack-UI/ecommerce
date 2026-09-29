<?php

/**
 * PromotionCondition factory.
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
use ArtisanPackUI\Ecommerce\Models\PromotionCondition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PromotionCondition>
 *
 * @since 1.0.0
 */
class PromotionConditionFactory extends Factory
{
    /**
     * @var class-string<PromotionCondition>
     */
    protected $model = PromotionCondition::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'promotion_id' => Promotion::factory(),
            'type'         => 'min-subtotal',
            'config'       => [ 'amount' => 1_000 ],
        ];
    }
}
