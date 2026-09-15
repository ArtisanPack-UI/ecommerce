<?php

/**
 * OrderTimelineEntry factory.
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
use ArtisanPackUI\Ecommerce\Models\OrderTimelineEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderTimelineEntry>
 *
 * @since 1.0.0
 */
class OrderTimelineEntryFactory extends Factory
{
    /**
     * @var class-string<OrderTimelineEntry>
     */
    protected $model = OrderTimelineEntry::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id'      => Order::factory(),
            'actor_user_id' => null,
            'event_type'    => 'order.placed',
            'payload'       => [],
        ];
    }
}
