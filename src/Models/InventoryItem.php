<?php

/**
 * InventoryItem model.
 *
 * Stock-tracking record for a Product or ProductVariant. Effective available
 * quantity is `quantity_on_hand - quantity_reserved`. Reservations against
 * this row live on {@see InventoryReservation}.
 *
 * Engine spec §3.11.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Models;

use ArtisanPackUI\Ecommerce\Database\Factories\InventoryItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * InventoryItem Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int                                                                    $id
 * @property string                                                                 $stockable_type
 * @property int                                                                    $stockable_id
 * @property bool                                                                   $track_inventory
 * @property int                                                                    $quantity_on_hand
 * @property int                                                                    $quantity_reserved
 * @property bool                                                                   $allow_backorder
 * @property int|null                                                               $low_stock_threshold
 * @property int|null                                                               $warehouse_id
 * @property \Illuminate\Database\Eloquent\Collection<int, InventoryReservation>    $reservations
 */
class InventoryItem extends Model
{
    use HasFactory;

    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'inventory_items';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'stockable_type',
        'stockable_id',
        'track_inventory',
        'quantity_on_hand',
        'quantity_reserved',
        'allow_backorder',
        'low_stock_threshold',
        'warehouse_id',
    ];

    /**
     * @since 1.0.0
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'track_inventory'   => true,
        'quantity_on_hand'  => 0,
        'quantity_reserved' => 0,
        'allow_backorder'   => false,
    ];

    /**
     * The polymorphic stockable (Product or ProductVariant).
     *
     * @since 1.0.0
     *
     * @return MorphTo<Model, $this>
     */
    public function stockable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Reservations held against this inventory row.
     *
     * @since 1.0.0
     *
     * @return HasMany<InventoryReservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany( InventoryReservation::class );
    }

    /**
     * Effective available quantity: on-hand minus reserved.
     *
     * @since 1.0.0
     *
     * @return int
     */
    public function availableQuantity(): int
    {
        return $this->quantity_on_hand - $this->quantity_reserved;
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'track_inventory'     => 'boolean',
            'quantity_on_hand'    => 'integer',
            'quantity_reserved'   => 'integer',
            'allow_backorder'     => 'boolean',
            'low_stock_threshold' => 'integer',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return InventoryItemFactory
     */
    protected static function newFactory(): InventoryItemFactory
    {
        return InventoryItemFactory::new();
    }
}
