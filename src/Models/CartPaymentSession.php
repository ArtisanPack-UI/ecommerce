<?php

/**
 * CartPaymentSession.
 *
 * One provider payment session a cart was given, written by
 * {@see \ArtisanPackUI\Ecommerce\Services\CheckoutService::createPaymentSession()}.
 * The cart's `payment_reference` stays its current session; this history
 * lets a session the cart no longer points at still be matched (and
 * settled) when the provider reports it confirmed. See
 * {@see \ArtisanPackUI\Ecommerce\Services\StrandedPaymentService}.
 *
 * Holds only provider references, amounts and statuses, never card data.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.4.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Models;

use ArtisanPackUI\Ecommerce\Database\Factories\CartPaymentSessionFactory;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Money\Currency;
use Money\Money;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.4.0
 *
 * @property int          $id
 * @property int          $cart_id
 * @property string       $gateway_key
 * @property string       $reference
 * @property int          $amount
 * @property string       $currency
 * @property string       $status
 * @property string|null  $provider_status
 * @property string|null  $reason
 * @property int|null     $order_id
 * @property string|null  $resolution_reference
 * @property Carbon|null  $superseded_at
 * @property Carbon|null  $stranded_at
 * @property Carbon|null  $resolved_at
 * @property Carbon|null  $created_at
 * @property Carbon|null  $updated_at
 * @property Cart         $cart
 * @property Order|null   $order
 */
class CartPaymentSession extends Model
{
    use HasFactory;

    /**
     * The cart's current session.
     *
     * @since 1.4.0
     *
     * @var string
     */
    public const STATUS_ACTIVE = 'active';

    /**
     * Replaced by a newer session (or the cart's gateway changed); it may
     * still be confirmed at the provider.
     *
     * @since 1.4.0
     *
     * @var string
     */
    public const STATUS_SUPERSEDED = 'superseded';

    /**
     * Cancelled at the provider, or reported cancelled by it.
     *
     * @since 1.4.0
     *
     * @var string
     */
    public const STATUS_CANCELED = 'canceled';

    /**
     * Confirmed at the provider, but no order could take it and it has not
     * been held, refunded or voided yet.
     *
     * @since 1.4.0
     *
     * @var string
     */
    public const STATUS_STRANDED = 'stranded';

    /**
     * Stranded, and held for staff review on an order (see `order_id`).
     *
     * @since 1.4.0
     *
     * @var string
     */
    public const STATUS_HELD = 'held';

    /**
     * Stranded, and refunded through the gateway.
     *
     * @since 1.4.0
     *
     * @var string
     */
    public const STATUS_REFUNDED = 'refunded';

    /**
     * Stranded while only authorized, and voided through the gateway.
     *
     * @since 1.4.0
     *
     * @var string
     */
    public const STATUS_VOIDED = 'voided';

    /**
     * Paid for its order the usual way.
     *
     * @since 1.4.0
     *
     * @var string
     */
    public const STATUS_COMPLETED = 'completed';

    /**
     * Statuses that close a session: nothing more is done with it.
     *
     * @since 1.4.0
     *
     * @var array<int, string>
     */
    public const RESOLVED_STATUSES = [
        self::STATUS_HELD,
        self::STATUS_REFUNDED,
        self::STATUS_VOIDED,
        self::STATUS_COMPLETED,
    ];

    /**
     * Statuses of sessions that may still move money at the provider, and
     * that `ecommerce:reconcile-payments` keeps asking about.
     *
     * @since 1.4.0
     *
     * @var array<int, string>
     */
    public const OPEN_STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_SUPERSEDED,
        self::STATUS_STRANDED,
    ];

    /**
     * @since 1.4.0
     *
     * @var string
     */
    protected $table = 'ecommerce_cart_payment_sessions';

    /**
     * @since 1.4.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'cart_id',
        'gateway_key',
        'reference',
        'amount',
        'currency',
        'status',
        'provider_status',
        'reason',
        'order_id',
        'resolution_reference',
        'superseded_at',
        'stranded_at',
        'resolved_at',
    ];

    /**
     * @since 1.4.0
     *
     * @return BelongsTo<Cart, $this>
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo( Cart::class );
    }

    /**
     * @since 1.4.0
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo( Order::class );
    }

    /**
     * Marks the cart's still-active session `$reference` superseded, so it is
     * kept for matching after the cart moves on to another session, gateway
     * or currency. Does nothing for a blank reference.
     *
     * @since 1.4.0
     *
     * @param  int          $cartId      Cart id.
     * @param  string|null  $gatewayKey  Gateway the session was made with.
     * @param  string|null  $reference   Session reference.
     *
     * @return void
     */
    public static function supersede( int $cartId, ?string $gatewayKey, ?string $reference ): void
    {
        if ( null === $gatewayKey || null === $reference || '' === $gatewayKey || '' === $reference ) {
            return;
        }

        static::query()
            ->where( 'cart_id', $cartId )
            ->where( 'gateway_key', $gatewayKey )
            ->where( 'reference', $reference )
            ->where( 'status', self::STATUS_ACTIVE )
            ->update( [ 'status' => self::STATUS_SUPERSEDED, 'superseded_at' => Carbon::now(), 'updated_at' => Carbon::now() ] );
    }

    /**
     * Whether nothing more is done with the session.
     *
     * @since 1.4.0
     *
     * @return bool
     */
    public function isResolved(): bool
    {
        return in_array( $this->status, self::RESOLVED_STATUSES, true );
    }

    /**
     * Whether the session was found stranded (confirmed with no order to
     * take it), whatever happened to it next.
     *
     * @since 1.4.0
     *
     * @return bool
     */
    public function wasStranded(): bool
    {
        return null !== $this->stranded_at;
    }

    /**
     * The session as a value object, with its last known provider status.
     *
     * @since 1.4.0
     *
     * @return PaymentSession
     */
    public function toPaymentSession(): PaymentSession
    {
        return new PaymentSession(
            gatewayKey: $this->gateway_key,
            reference: $this->reference,
            amount: new Money( (int) $this->amount, new Currency( strtoupper( $this->currency ) ) ),
            status: $this->provider_status,
        );
    }

    /**
     * Sessions for the reference under any of `$gatewayKeys`.
     *
     * @since 1.4.0
     *
     * @param  Builder<self>       $query        Query.
     * @param  array<int, string>  $gatewayKeys  Gateway keys.
     * @param  string              $reference    Session reference.
     *
     * @return void
     */
    public function scopeForReference( Builder $query, array $gatewayKeys, string $reference ): void
    {
        $query->whereIn( 'gateway_key', $gatewayKeys )->where( 'reference', $reference );
    }

    /**
     * Sessions that may still move money at the provider.
     *
     * @since 1.4.0
     *
     * @param  Builder<self>  $query  Query.
     *
     * @return void
     */
    public function scopeOpen( Builder $query ): void
    {
        $query->whereIn( 'status', self::OPEN_STATUSES );
    }

    /**
     * @since 1.4.0
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cart_id'       => 'integer',
            'amount'        => 'integer',
            'order_id'      => 'integer',
            'superseded_at' => 'datetime',
            'stranded_at'   => 'datetime',
            'resolved_at'   => 'datetime',
        ];
    }

    /**
     * @since 1.4.0
     *
     * @return CartPaymentSessionFactory
     */
    protected static function newFactory(): CartPaymentSessionFactory
    {
        return CartPaymentSessionFactory::new();
    }
}
