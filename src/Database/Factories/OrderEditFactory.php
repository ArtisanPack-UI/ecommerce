<?php

/**
 * OrderEdit factory.
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
use ArtisanPackUI\Ecommerce\Models\OrderEdit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderEdit>
 *
 * @since 1.0.0
 */
class OrderEditFactory extends Factory
{
    /**
     * @var class-string<OrderEdit>
     */
    protected $model = OrderEdit::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id'          => Order::factory(),
            'actor_user_id'     => null,
            'reason'            => $this->faker->sentence(),
            'diff'              => [ 'fields' => [], 'items' => [], 'totals' => [] ],
            'pre_edit_snapshot' => [ 'order' => [], 'items' => [] ],
        ];
    }
}
