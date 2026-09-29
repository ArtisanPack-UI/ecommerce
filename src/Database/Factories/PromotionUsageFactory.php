<?php

/**
 * PromotionUsage factory.
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

use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\Promotion;
use ArtisanPackUI\Ecommerce\Models\PromotionUsage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PromotionUsage>
 *
 * @since 1.0.0
 */
class PromotionUsageFactory extends Factory
{
    /**
     * @var class-string<PromotionUsage>
     */
    protected $model = PromotionUsage::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'promotion_id'      => Promotion::factory(),
            'order_id'          => Order::factory(),
            'customer_id'       => null,
            'amount_discounted' => 500,
            'currency'          => 'USD',
        ];
    }
}
