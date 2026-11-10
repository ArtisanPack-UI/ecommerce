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
 * @property string                                                         $status
 * @property Carbon|null                                                    $created_at
 * @property Carbon|null                                                    $updated_at
 * @property Order                                                          $order
 * @property \Illuminate\Database\Eloquent\Collection<int, RefundItem>      $items
 */
class Refund extends Model
{
    use HasFactory;

    /**
     * Written before the gateway is called; not yet money moved.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STATUS_PENDING = 'pending';

    /**
     * The gateway refunded it.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STATUS_SUCCEEDED = 'succeeded';

    /**
     * The gateway declined or errored; no money moved.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STATUS_FAILED = 'failed';

    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'ecommerce_refunds';

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
        'status',
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
     * Settles a pending refund once the gateway answered — the one change a
     * pending refund row allows. Only `status` (and, on success, the
     * provider's `gateway_reference`) are written, and only while the row is
     * still pending, so a settled refund can never be changed back. The one
     * exception is {@see self::settleFailedAsSucceeded()}, for a failed
     * refund the provider later reports it completed.
     *
     * @since 1.0.0
     *
     * @param  string       $status             {@see self::STATUS_SUCCEEDED} or {@see self::STATUS_FAILED}.
     * @param  string|null  $gatewayReference   The provider's refund id.
     *
     * @throws LogicException When the status is not a settled one or the row is no longer pending.
     *
     * @return void
     */
    public function settle( string $status, ?string $gatewayReference = null ): void
    {
        if ( ! in_array( $status, [ self::STATUS_SUCCEEDED, self::STATUS_FAILED ], true ) ) {
            throw new LogicException( sprintf( 'A refund settles as succeeded or failed, not "%s".', $status ) );
        }

        $values = [ 'status' => $status, 'updated_at' => Carbon::now() ];

        if ( null !== $gatewayReference ) {
            $values['gateway_reference'] = $gatewayReference;
        }

        // The base query builder, not the append-only Eloquent one.
        $updated = static::query()->toBase()
            ->where( 'id', $this->getKey() )
            ->where( 'status', self::STATUS_PENDING )
            ->update( $values );

        if ( 1 !== $updated ) {
            throw new LogicException( sprintf( 'Refund %d is not pending and cannot be settled again.', $this->getKey() ) );
        }

        $this->forceFill( $values )->syncOriginal();
    }

    /**
     * Settles a refund that was marked failed as succeeded, because the
     * provider later reported that it made the refund after all (such as
     * after a gateway timeout). This is the only change a failed refund
     * allows: `status` becomes succeeded and, when given, the provider's
     * `gateway_reference` is written, and only while the row is still failed.
     *
     * Unlike {@see self::settle()}, the change stays in the model's
     * `changes`, so callers can tell a settled refund from an untouched one
     * with `wasChanged( 'status' )`.
     *
     * @since 1.3.0
     *
     * @param  string|null  $gatewayReference  The provider's refund id.
     *
     * @throws LogicException When the row is no longer failed.
     *
     * @return void
     */
    public function settleFailedAsSucceeded( ?string $gatewayReference = null ): void
    {
        $values = [ 'status' => self::STATUS_SUCCEEDED, 'updated_at' => Carbon::now() ];

        if ( null !== $gatewayReference ) {
            $values['gateway_reference'] = $gatewayReference;
        }

        // The base query builder, not the append-only Eloquent one.
        $updated = static::query()->toBase()
            ->where( 'id', $this->getKey() )
            ->where( 'status', self::STATUS_FAILED )
            ->update( $values );

        if ( 1 !== $updated ) {
            throw new LogicException( sprintf( 'Refund %d is not failed and cannot be settled as succeeded.', $this->getKey() ) );
        }

        $this->forceFill( $values );
        $this->syncChanges();
        $this->syncOriginal();
    }

    /**
     * Scope: refunds that moved money.
     *
     * @since 1.0.0
     *
     * @param  Builder<Refund>  $query  Query.
     *
     * @return Builder<Refund>
     */
    public function scopeSucceeded( Builder $query ): Builder
    {
        return $query->where( 'status', self::STATUS_SUCCEEDED );
    }

    /**
     * Scope: refunds that count against what can still be refunded (moved
     * money, or may be moving it right now).
     *
     * @since 1.0.0
     *
     * @param  Builder<Refund>  $query  Query.
     *
     * @return Builder<Refund>
     */
    public function scopeCounting( Builder $query ): Builder
    {
        return $query->whereIn( 'status', [ self::STATUS_PENDING, self::STATUS_SUCCEEDED ] );
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
