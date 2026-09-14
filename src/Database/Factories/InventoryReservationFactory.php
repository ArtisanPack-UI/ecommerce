<?php

/**
 * InventoryReservation factory.
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

use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\Models\InventoryReservation;
use ArtisanPackUI\Ecommerce\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<InventoryReservation>
 *
 * @since 1.0.0
 */
class InventoryReservationFactory extends Factory
{
    /**
     * @var class-string<InventoryReservation>
     */
    protected $model = InventoryReservation::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'inventory_item_id' => InventoryItem::factory(),
            'reservable_type'   => Order::class,
            'reservable_id'     => 1,
            'quantity'          => 1,
            'expires_at'        => Carbon::now()->addMinutes( 15 ),
        ];
    }

    /**
     * State: reservation has already expired.
     *
     * @since 1.0.0
     *
     * @return static
     */
    public function expired(): static
    {
        return $this->state( fn () => [
            'expires_at' => Carbon::now()->subMinute(),
        ] );
    }
}
