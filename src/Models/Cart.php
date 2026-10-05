<?php

/**
 * Cart model.
 *
 * A DB-backed shopping cart. Guest carts are anchored to an opaque `token`
 * stored in the session; authenticated carts additionally reference a
 * `customer_id`. Every cart carries its own transaction currency — the
 * engine never silently re-prices on FX drift or on guest→user merge.
 *
 * Engine spec §3.13.
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

use ArtisanPackUI\Ecommerce\Database\Factories\CartFactory;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Cart Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int                                                              $id
 * @property string                                                           $token
 * @property int|null                                                         $customer_id
 * @property string                                                           $currency
 * @property string|null                                                      $email
 * @property int                                                              $subtotal_amount
 * @property string                                                           $subtotal_currency
 * @property int                                                              $discount_amount
 * @property string                                                           $discount_currency
 * @property int                                                              $tax_amount
 * @property string                                                           $tax_currency
 * @property int                                                              $shipping_amount
 * @property string                                                           $shipping_currency
 * @property int                                                              $total_amount
 * @property string                                                           $total_currency
 * @property Carbon|null                                                      $checkout_started_at
 * @property string                                                           $checkout_state
 * @property array<string, mixed>|null                                        $shipping_address
 * @property array<string, mixed>|null                                        $billing_address
 * @property string|null                                                      $payment_gateway_key
 * @property string|null                                                      $payment_reference
 * @property string|null                                                      $locale
 * @property Carbon|null                                                      $abandoned_at
 * @property int|null                                                         $completed_order_id
 * @property array<string, mixed>                                             $meta
 * @property Carbon|null                                                      $expires_at
 * @property \Illuminate\Database\Eloquent\Collection<int, CartItem>          $items
 * @property Customer|null                                                    $customer
 */
class Cart extends Model
{
    use HasFactory;

    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'ecommerce_carts';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'token',
        'customer_id',
        'currency',
        'email',
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
        'checkout_started_at',
        'checkout_state',
        'shipping_address',
        'billing_address',
        'payment_gateway_key',
        'payment_reference',
        'locale',
        'abandoned_at',
        'completed_order_id',
        'meta',
        'expires_at',
    ];

    /**
     * @since 1.0.0
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'subtotal_amount' => 0,
        'discount_amount' => 0,
        'tax_amount'      => 0,
        'shipping_amount' => 0,
        'total_amount'    => 0,
        'checkout_state'  => 'not_started',
    ];

    /**
     * Line items on this cart.
     *
     * @since 1.0.0
     *
     * @return HasMany<CartItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany( CartItem::class );
    }

    /**
     * Customer this cart is anchored to, if any.
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
     * Scope: carts that can still be shopped with (not converted, not expired).
     *
     * @since 1.0.0
     *
     * @param  Builder<Cart>  $query  Query.
     *
     * @return Builder<Cart>
     */
    public function scopeOpen( Builder $query ): Builder
    {
        return $query->whereNull( 'completed_order_id' )
            ->where( static fn ( Builder $open ) => $open->whereNull( 'expires_at' )->orWhere( 'expires_at', '>', Carbon::now() ) );
    }

    /**
     * Whether `$user` may use this cart by its token: a guest cart (no
     * customer) is open to whoever holds the token; an account's cart only
     * to that account's signed-in user.
     *
     * @since 1.0.0
     *
     * @param  Authenticatable|null  $user  Signed-in user.
     *
     * @return bool
     */
    public function isAccessibleBy( ?Authenticatable $user ): bool
    {
        if ( null === $this->customer_id ) {
            return true;
        }

        return null !== $user && (int) Customer::forUser( $user )?->id === (int) $this->customer_id;
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'customer_id'         => 'integer',
            'subtotal_amount'     => 'integer',
            'discount_amount'     => 'integer',
            'tax_amount'          => 'integer',
            'shipping_amount'     => 'integer',
            'total_amount'        => 'integer',
            'checkout_started_at' => 'datetime',
            'shipping_address'    => 'array',
            'billing_address'     => 'array',
            'abandoned_at'        => 'datetime',
            'completed_order_id'  => 'integer',
            'meta'                => 'array',
            'expires_at'          => 'datetime',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return CartFactory
     */
    protected static function newFactory(): CartFactory
    {
        return CartFactory::new();
    }
}
