<?php

/**
 * Shipment model.
 *
 * A parcel (or local-pickup handoff) fulfilling some or all of an
 * {@see Order}'s lines. Line quantities live on {@see ShipmentItem}.
 * `label_id` is a soft reference to the `shipping-labels` package.
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

use ArtisanPackUI\Ecommerce\Database\Factories\ShipmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Shipment Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int                                                         $id
 * @property int                                                         $order_id
 * @property string                                                      $method_key
 * @property string|null                                                 $carrier
 * @property string|null                                                 $service
 * @property string|null                                                 $tracking_number
 * @property string|null                                                 $tracking_url
 * @property int|null                                                    $label_id
 * @property string                                                      $status
 * @property Carbon|null                                                 $shipped_at
 * @property Carbon|null                                                 $delivered_at
 * @property array<string, mixed>|null                                   $meta
 * @property Carbon|null                                                 $created_at
 * @property Carbon|null                                                 $updated_at
 * @property Order                                                       $order
 * @property \Illuminate\Database\Eloquent\Collection<int, ShipmentItem> $items
 */
class Shipment extends Model
{
    use HasFactory;

    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const STATUS_PENDING = 'pending';

    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const STATUS_IN_TRANSIT = 'in_transit';

    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const STATUS_DELIVERED = 'delivered';

    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const STATUS_EXCEPTION = 'exception';

    /**
     * Every valid status.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_IN_TRANSIT,
        self::STATUS_DELIVERED,
        self::STATUS_EXCEPTION,
    ];

    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'shipments';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'order_id',
        'method_key',
        'carrier',
        'service',
        'tracking_number',
        'tracking_url',
        'label_id',
        'status',
        'shipped_at',
        'delivered_at',
        'meta',
    ];

    /**
     * @since 1.0.0
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    /**
     * Order this shipment fulfils.
     *
     * @since 1.0.0
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo( Order::class );
    }

    /**
     * Line quantities in this shipment.
     *
     * @since 1.0.0
     *
     * @return HasMany<ShipmentItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany( ShipmentItem::class );
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order_id'     => 'integer',
            'label_id'     => 'integer',
            'shipped_at'   => 'datetime',
            'delivered_at' => 'datetime',
            'meta'         => 'array',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return ShipmentFactory
     */
    protected static function newFactory(): ShipmentFactory
    {
        return ShipmentFactory::new();
    }
}
