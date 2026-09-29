<?php

/**
 * ShipmentItem factory.
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

use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\Shipment;
use ArtisanPackUI\Ecommerce\Models\ShipmentItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShipmentItem>
 *
 * @since 1.0.0
 */
class ShipmentItemFactory extends Factory
{
    /**
     * @var class-string<ShipmentItem>
     */
    protected $model = ShipmentItem::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shipment_id'   => Shipment::factory(),
            'order_item_id' => OrderItem::factory(),
            'quantity'      => 1,
        ];
    }
}
