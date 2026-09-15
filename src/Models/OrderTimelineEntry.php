<?php

/**
 * OrderTimelineEntry model.
 *
 * Append-only activity log for an {@see Order}. Every inflection point
 * (placement, payment, status change, note added, edit applied) writes exactly
 * one row here. Rows are immutable after creation. Engine spec §3.19.
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

use ArtisanPackUI\Ecommerce\Database\Factories\OrderTimelineEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * OrderTimelineEntry Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int                  $id
 * @property int                  $order_id
 * @property int|null             $actor_user_id
 * @property string               $event_type
 * @property array<string, mixed> $payload
 * @property Carbon|null          $created_at
 * @property Order                $order
 */
class OrderTimelineEntry extends Model
{
    use HasFactory;

    /**
     * Timeline entries are append-only: no `updated_at`.
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
    protected $table = 'order_timeline_entries';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'order_id',
        'actor_user_id',
        'event_type',
        'payload',
        'created_at',
    ];

    /**
     * @since 1.0.0
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo( Order::class );
    }

    /**
     * Boot the model to stamp `created_at` on insert and enforce append-only
     * semantics on subsequent saves.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating( function ( self $entry ): void {
            if ( null === $entry->created_at ) {
                $entry->created_at = Carbon::now();
            }
        } );

        static::updating( function ( self $entry ): void {
            throw new LogicException(
                'Order timeline entries are append-only and cannot be modified after creation.',
            );
        } );
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order_id'       => 'integer',
            'actor_user_id'  => 'integer',
            'payload'        => 'array',
            'created_at'     => 'datetime',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return OrderTimelineEntryFactory
     */
    protected static function newFactory(): OrderTimelineEntryFactory
    {
        return OrderTimelineEntryFactory::new();
    }
}
