<?php

/**
 * InventoryItem factory.
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
use ArtisanPackUI\Ecommerce\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryItem>
 *
 * @since 1.0.0
 */
class InventoryItemFactory extends Factory
{
    /**
     * @var class-string<InventoryItem>
     */
    protected $model = InventoryItem::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stockable_type'    => Product::class,
            'stockable_id'      => Product::factory(),
            'track_inventory'   => true,
            'quantity_on_hand'  => 10,
            'quantity_reserved' => 0,
            'allow_backorder'   => false,
        ];
    }

    /**
     * State: stock allows backorders.
     *
     * @since 1.0.0
     *
     * @return static
     */
    public function backorderable(): static
    {
        return $this->state( fn () => [ 'allow_backorder' => true ] );
    }

    /**
     * State: inventory tracking disabled (always available).
     *
     * @since 1.0.0
     *
     * @return static
     */
    public function untracked(): static
    {
        return $this->state( fn () => [ 'track_inventory' => false ] );
    }
}
