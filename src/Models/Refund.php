<?php

/**
 * Refund model.
 *
 * Ledger row written by {@see \ArtisanPackUI\Ecommerce\Services\RefundService::issue()}
 * after a successful gateway refund. Records how much was refunded against
 * a given {@see Order}, why, the provider-side reference, and which admin
 * issued it. Line-level detail — including the optional restock flag —
 * lives on the associated {@see RefundItem} rows.
 *
 * Engine spec §3.21.
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

use ArtisanPackUI\Ecommerce\Casts\MoneyCast;
use ArtisanPackUI\Ecommerce\Database\Eloquent\AppendOnlyBuilder;
use ArtisanPackUI\Ecommerce\Database\Factories\RefundFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;
use Money\Money;

/**
 * Refund Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int                                                            $id
 * @property int                                                            $order_id
 * @property int                                                            $amount
 * @property string                                                         $currency
 * @property Money                                                          $money
 * @property string|null                                                    $reason
 * @property string|null                                                    $gateway_reference
 * @property int|null                                                       $issued_by_user_id
 * @property Carbon|null                                                    $created_at
 * @property Carbon|null                                                    $updated_at
 * @property Order                                                          $order
 * @property \Illuminate\Database\Eloquent\Collection<int, RefundItem>      $items
 */
class Refund extends Model
{
    use HasFactory;

    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'refunds';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'order_id',
        'amount',
        'currency',
        'reason',
        'gateway_reference',
        'issued_by_user_id',
    ];

    /**
     * The order this refund is booked against.
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
     * Per-line detail attached to this refund.
     *
     * @since 1.0.0
     *
     * @return HasMany<RefundItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany( RefundItem::class );
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
     * Refunds are append-only ledger rows: once written they cannot be
     * mutated or deleted through Eloquent. Cascade cleanup on parent order
     * deletion is enforced at the FK layer and bypasses these hooks.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected static function boot(): void
    {
        parent::boot();

        static::updating( function ( self $refund ): void {
            throw new LogicException(
                'Refunds are append-only ledger rows and cannot be modified after creation.',
            );
        } );

        static::deleting( function ( self $refund ): void {
            throw new LogicException(
                'Refunds are append-only; delete the owning order to cascade-remove them.',
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
            'amount'            => 'integer',
            'issued_by_user_id' => 'integer',
            'money'             => MoneyCast::class . ':amount,currency',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return RefundFactory
     */
    protected static function newFactory(): RefundFactory
    {
        return RefundFactory::new();
    }
}
