<?php

/**
 * ShipmentItem model.
 *
 * Quantity of one {@see OrderItem} carried by a {@see Shipment}.
 *
 * Engine spec §3.25.
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

use ArtisanPackUI\Ecommerce\Database\Factories\ShipmentItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ShipmentItem Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int       $id
 * @property int       $shipment_id
 * @property int       $order_item_id
 * @property int       $quantity
 * @property Shipment  $shipment
 * @property OrderItem $orderItem
 */
class ShipmentItem extends Model
{
    use HasFactory;

    /**
     * The table has no timestamps (engine spec §3.25).
     *
     * @since 1.0.0
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'shipment_items';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'shipment_id',
        'order_item_id',
        'quantity',
    ];

    /**
     * @since 1.0.0
     *
     * @return BelongsTo<Shipment, $this>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo( Shipment::class );
    }

    /**
     * @since 1.0.0
     *
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo( OrderItem::class );
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'shipment_id'   => 'integer',
            'order_item_id' => 'integer',
            'quantity'      => 'integer',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return ShipmentItemFactory
     */
    protected static function newFactory(): ShipmentItemFactory
    {
        return ShipmentItemFactory::new();
    }
}
