<?php

/**
 * OrderItem model.
 *
 * A single line on an {@see Order}. The `product_snapshot` column captures
 * the product's identity at order-placement time (name, sku, image, type,
 * option labels) and is treated as immutable: once the row is persisted, any
 * attempt to change `product_snapshot` is rejected. This preserves historical
 * accuracy even when the underlying product row is later renamed, restyled,
 * or deleted. Engine spec §3.16.
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

use ArtisanPackUI\Ecommerce\Database\Eloquent\OrderItemBuilder;
use ArtisanPackUI\Ecommerce\Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * OrderItem Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int                  $id
 * @property int                  $order_id
 * @property int|null             $product_id
 * @property int|null             $product_variant_id
 * @property array<string, mixed> $product_snapshot
 * @property int                  $quantity
 * @property int                  $unit_price_amount
 * @property string               $unit_price_currency
 * @property int                  $discount_amount
 * @property string               $discount_currency
 * @property int                  $tax_amount
 * @property string               $tax_currency
 * @property int                  $shipping_amount
 * @property string               $shipping_currency
 * @property int                  $total_amount
 * @property string               $total_currency
 * @property string               $fulfillment_status
 * @property array<string, mixed> $meta
 * @property Order                $order
 * @property Product|null         $product
 * @property ProductVariant|null  $variant
 */
class OrderItem extends Model
{
    use HasFactory;

    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'order_items';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'order_id',
        'product_id',
        'product_variant_id',
        'product_snapshot',
        'quantity',
        'unit_price_amount',
        'unit_price_currency',
        'discount_amount',
        'discount_currency',
        'tax_amount',
        'tax_currency',
        'shipping_amount',
        'shipping_currency',
        'total_amount',
        'total_currency',
        'fulfillment_status',
        'meta',
    ];

    /**
     * @since 1.0.0
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'discount_amount' => 0,
        'tax_amount'      => 0,
        'shipping_amount' => 0,
    ];

    /**
     * The order this line belongs to.
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
     * The product row this line references, if it still exists.
     *
     * `product_id` is nullable at the DB level (see spec §3.16) so a deleted
     * product does not cascade-delete historical orders; the immutable
     * `product_snapshot` remains the source of truth for display.
     *
     * @since 1.0.0
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo( Product::class );
    }

    /**
     * The optional variant chosen on the product.
     *
     * @since 1.0.0
     *
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo( ProductVariant::class, 'product_variant_id' );
    }

    /**
     * Returns a custom Eloquent builder that rejects bulk updates to the
     * immutable `product_snapshot` column.
     *
     * @since 1.0.0
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     *
     * @return OrderItemBuilder<static>
     */
    public function newEloquentBuilder( $query ): Builder
    {
        return new OrderItemBuilder( $query );
    }

    /**
     * Boot the model to enforce the `product_snapshot` immutability invariant.
     *
     * A `product_snapshot` update on an existing row is a data-integrity bug:
     * the snapshot is the historical record of what the customer actually
     * bought. Reject the change at the model layer so bugs surface as loud
     * exceptions rather than silent audit-trail corruption.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected static function boot(): void
    {
        parent::boot();

        static::updating( function ( self $item ): void {
            if ( $item->isDirty( 'product_snapshot' ) ) {
                throw new LogicException(
                    'order_items.product_snapshot is immutable after placement and cannot be modified.',
                );
            }
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
            'order_id'           => 'integer',
            'product_id'         => 'integer',
            'product_variant_id' => 'integer',
            'product_snapshot'   => 'array',
            'quantity'           => 'integer',
            'unit_price_amount'  => 'integer',
            'discount_amount'    => 'integer',
            'tax_amount'         => 'integer',
            'shipping_amount'    => 'integer',
            'total_amount'       => 'integer',
            'meta'               => 'array',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return OrderItemFactory
     */
    protected static function newFactory(): OrderItemFactory
    {
        return OrderItemFactory::new();
    }
}
