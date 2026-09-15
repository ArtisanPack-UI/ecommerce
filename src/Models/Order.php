<?php

/**
 * Order model.
 *
 * Represents a placed order — the anchor for order items, notes, timeline
 * entries, edits, refunds, shipments, and downstream satellite state. Guest
 * orders are stored with `is_claimed = false` and no `customer_id` link until
 * the shopper verifies an account on the same email. Engine spec §3.15.
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

use ArtisanPackUI\Ecommerce\Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Order Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int                                                                    $id
 * @property string                                                                 $order_number
 * @property int|null                                                               $customer_id
 * @property string                                                                 $email
 * @property string|null                                                            $phone
 * @property string                                                                 $system_status
 * @property int|null                                                               $substatus_id
 * @property string                                                                 $payment_status
 * @property string                                                                 $fulfillment_status
 * @property string                                                                 $currency
 * @property string                                                                 $base_currency
 * @property int                                                                    $fx_rate_to_base_e8
 * @property int                                                                    $subtotal_amount
 * @property string                                                                 $subtotal_currency
 * @property int                                                                    $discount_amount
 * @property string                                                                 $discount_currency
 * @property int                                                                    $tax_amount
 * @property string                                                                 $tax_currency
 * @property int                                                                    $shipping_amount
 * @property string                                                                 $shipping_currency
 * @property int                                                                    $total_amount
 * @property string                                                                 $total_currency
 * @property int                                                                    $total_refunded_amount
 * @property string                                                                 $total_refunded_currency
 * @property array<string, mixed>|null                                              $shipping_address
 * @property array<string, mixed>|null                                              $billing_address
 * @property string|null                                                            $shipping_method_key
 * @property string|null                                                            $payment_gateway_key
 * @property string|null                                                            $payment_reference
 * @property string|null                                                            $ip_address
 * @property string|null                                                            $user_agent
 * @property string|null                                                            $customer_note
 * @property bool                                                                   $is_claimed
 * @property array<string, mixed>                                                   $meta
 * @property Carbon|null                                                            $placed_at
 * @property Customer|null                                                          $customer
 * @property OrderSubstatus|null                                                    $substatus
 * @property \Illuminate\Database\Eloquent\Collection<int, OrderItem>               $items
 * @property \Illuminate\Database\Eloquent\Collection<int, OrderNote>               $notes
 * @property \Illuminate\Database\Eloquent\Collection<int, OrderTimelineEntry>      $timelineEntries
 * @property \Illuminate\Database\Eloquent\Collection<int, OrderEdit>               $edits
 */
class Order extends Model
{
    use HasFactory;

    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'orders';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'order_number',
        'customer_id',
        'email',
        'phone',
        'system_status',
        'substatus_id',
        'payment_status',
        'fulfillment_status',
        'currency',
        'base_currency',
        'fx_rate_to_base_e8',
        'subtotal_amount',
        'subtotal_currency',
        'discount_amount',
        'discount_currency',
        'tax_amount',
        'tax_currency',
        'shipping_amount',
        'shipping_currency',
        'total_amount',
        'total_currency',
        'total_refunded_amount',
        'total_refunded_currency',
        'shipping_address',
        'billing_address',
        'shipping_method_key',
        'payment_gateway_key',
        'payment_reference',
        'ip_address',
        'user_agent',
        'customer_note',
        'is_claimed',
        'meta',
        'placed_at',
    ];

    /**
     * @since 1.0.0
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'discount_amount'       => 0,
        'tax_amount'            => 0,
        'shipping_amount'       => 0,
        'total_refunded_amount' => 0,
        'is_claimed'            => true,
    ];

    /**
     * The customer this order belongs to, if claimed.
     *
     * @since 1.0.0
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo( Customer::class );
    }

    /**
     * The global default sub-status for the order's current system status.
     *
     * @since 1.0.0
     *
     * @return BelongsTo<OrderSubstatus, $this>
     */
    public function substatus(): BelongsTo
    {
        return $this->belongsTo( OrderSubstatus::class, 'substatus_id' );
    }

    /**
     * Line items on this order.
     *
     * @since 1.0.0
     *
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany( OrderItem::class );
    }

    /**
     * Notes attached to this order.
     *
     * @since 1.0.0
     *
     * @return HasMany<OrderNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany( OrderNote::class );
    }

    /**
     * Append-only activity log entries for this order.
     *
     * @since 1.0.0
     *
     * @return HasMany<OrderTimelineEntry, $this>
     */
    public function timelineEntries(): HasMany
    {
        return $this->hasMany( OrderTimelineEntry::class );
    }

    /**
     * Post-placement edits applied to this order.
     *
     * @since 1.0.0
     *
     * @return HasMany<OrderEdit, $this>
     */
    public function edits(): HasMany
    {
        return $this->hasMany( OrderEdit::class );
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'customer_id'           => 'integer',
            'substatus_id'          => 'integer',
            'fx_rate_to_base_e8'    => 'integer',
            'subtotal_amount'       => 'integer',
            'discount_amount'       => 'integer',
            'tax_amount'            => 'integer',
            'shipping_amount'       => 'integer',
            'total_amount'          => 'integer',
            'total_refunded_amount' => 'integer',
            'shipping_address'      => 'array',
            'billing_address'       => 'array',
            'is_claimed'            => 'boolean',
            'meta'                  => 'array',
            'placed_at'             => 'datetime',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return OrderFactory
     */
    protected static function newFactory(): OrderFactory
    {
        return OrderFactory::new();
    }
}
