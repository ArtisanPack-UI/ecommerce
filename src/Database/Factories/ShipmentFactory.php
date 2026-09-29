<?php

/**
 * Shipment factory.
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
use ArtisanPackUI\Ecommerce\Models\Shipment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shipment>
 *
 * @since 1.0.0
 */
class ShipmentFactory extends Factory
{
    /**
     * @var class-string<Shipment>
     */
    protected $model = Shipment::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id'   => Order::factory(),
            'method_key' => 'flat-rate',
            'status'     => Shipment::STATUS_PENDING,
            'meta'       => [],
        ];
    }
}
