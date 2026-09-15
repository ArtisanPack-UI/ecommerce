<?php

/**
 * RefundItem model.
 *
 * Per-line detail attached to a {@see Refund}. Records how many units of
 * an {@see OrderItem} were refunded, the money value that was allocated to
 * that line, and whether the units were restocked back into inventory.
 *
 * Engine spec §3.21. Refund items do not carry timestamps — the parent
 * {@see Refund} row is the source of truth for when the refund was issued.
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
use ArtisanPackUI\Ecommerce\Database\Factories\RefundItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Money\Money;

/**
 * RefundItem Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int        $id
 * @property int        $refund_id
 * @property int        $order_item_id
 * @property int        $quantity
 * @property int        $amount
 * @property string     $currency
 * @property Money      $money
 * @property bool       $restock
 * @property Refund     $refund
 * @property OrderItem  $orderItem
 */
class RefundItem extends Model
{
    use HasFactory;

    /**
     * The parent `refunds` row carries `created_at`; refund items are
     * insert-only line detail with no timestamps of their own.
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
    protected $table = 'refund_items';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'refund_id',
        'order_item_id',
        'quantity',
        'amount',
        'currency',
        'restock',
    ];

    /**
     * @since 1.0.0
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'restock' => false,
    ];

    /**
     * The refund this line belongs to.
     *
     * @since 1.0.0
     *
     * @return BelongsTo<Refund, $this>
     */
    public function refund(): BelongsTo
    {
        return $this->belongsTo( Refund::class );
    }

    /**
     * The order item this line is refunding.
     *
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
            'refund_id'     => 'integer',
            'order_item_id' => 'integer',
            'quantity'      => 'integer',
            'amount'        => 'integer',
            'restock'       => 'boolean',
            'money'         => MoneyCast::class . ':amount,currency',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return RefundItemFactory
     */
    protected static function newFactory(): RefundItemFactory
    {
        return RefundItemFactory::new();
    }
}
