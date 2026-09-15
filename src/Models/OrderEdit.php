<?php

/**
 * OrderEdit model.
 *
 * Records a post-placement modification to an {@see Order}. Each row carries
 * the diff of what changed plus the full pre-edit snapshot so an audit can
 * rebuild any prior state of the order. Rows are immutable after creation.
 * Engine spec §3.20.
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

use ArtisanPackUI\Ecommerce\Database\Eloquent\AppendOnlyBuilder;
use ArtisanPackUI\Ecommerce\Database\Factories\OrderEditFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * OrderEdit Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int                  $id
 * @property int                  $order_id
 * @property int|null             $actor_user_id
 * @property string|null          $reason
 * @property array<string, mixed> $diff
 * @property array<string, mixed> $pre_edit_snapshot
 * @property Carbon|null          $created_at
 * @property Order                $order
 */
class OrderEdit extends Model
{
    use HasFactory;

    /**
     * Order edits are append-only: no `updated_at`.
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
    protected $table = 'order_edits';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'order_id',
        'actor_user_id',
        'reason',
        'diff',
        'pre_edit_snapshot',
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
     * Returns the append-only builder so bulk update/delete calls are
     * rejected the same way as model-instance saves.
     *
     * @since 1.0.0
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     *
     * @return AppendOnlyBuilder<static>
     */
    public function newEloquentBuilder( $query ): Builder
    {
        return new AppendOnlyBuilder( $query );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating( function ( self $edit ): void {
            if ( null === $edit->created_at ) {
                $edit->created_at = Carbon::now();
            }
        } );

        static::updating( function ( self $edit ): void {
            throw new LogicException(
                'Order edits are append-only and cannot be modified after creation.',
            );
        } );

        static::deleting( function ( self $edit ): void {
            throw new LogicException(
                'Order edits are append-only; delete the owning order to cascade-remove them.',
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
            'order_id'          => 'integer',
            'actor_user_id'     => 'integer',
            'diff'              => 'array',
            'pre_edit_snapshot' => 'array',
            'created_at'        => 'datetime',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return OrderEditFactory
     */
    protected static function newFactory(): OrderEditFactory
    {
        return OrderEditFactory::new();
    }
}
