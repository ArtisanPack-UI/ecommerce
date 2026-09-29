<?php

/**
 * PromotionAction factory.
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
use ArtisanPackUI\Ecommerce\Models\PromotionAction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PromotionAction>
 *
 * @since 1.0.0
 */
class PromotionActionFactory extends Factory
{
    /**
     * @var class-string<PromotionAction>
     */
    protected $model = PromotionAction::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'promotion_id' => Promotion::factory(),
            'type'         => 'percent-off-cart',
            'config'       => [ 'percent' => 10 ],
        ];
    }
}
