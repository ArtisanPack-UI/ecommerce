<?php

/**
 * InventoryReservation model.
 *
 * A reservation held against an {@see InventoryItem} by a Cart or Order.
 * Reservations expire at `expires_at`; the
 * `ecommerce:release-expired-reservations` command sweeps them.
 *
 * Engine spec §3.12.
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

use ArtisanPackUI\Ecommerce\Database\Factories\InventoryReservationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * InventoryReservation Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int                     $id
 * @property int                     $inventory_item_id
 * @property string                  $reservable_type
 * @property int                     $reservable_id
 * @property int                     $quantity
 * @property Carbon|null $expires_at
 * @property InventoryItem           $inventoryItem
 */
class InventoryReservation extends Model
{
    use HasFactory;

    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'inventory_reservations';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'inventory_item_id',
        'reservable_type',
        'reservable_id',
        'quantity',
        'expires_at',
    ];

    /**
     * The inventory row this reservation is held against.
     *
     * @since 1.0.0
     *
     * @return BelongsTo<InventoryItem, $this>
     */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo( InventoryItem::class );
    }

    /**
     * The polymorphic reservable (Cart or Order).
     *
     * @since 1.0.0
     *
     * @return MorphTo<Model, $this>
     */
    public function reservable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope: rows whose `expires_at` is in the past.
     *
     * @since 1.0.0
     *
     * @param  Builder<self>  $query
     *
     * @return Builder<self>
     */
    public function scopeExpired( Builder $query ): Builder
    {
        return $query
            ->whereNotNull( 'expires_at' )
            ->where( 'expires_at', '<=', Carbon::now() );
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity'   => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return InventoryReservationFactory
     */
    protected static function newFactory(): InventoryReservationFactory
    {
        return InventoryReservationFactory::new();
    }
}
