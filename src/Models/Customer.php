<?php

/**
 * Customer model.
 *
 * Represents a shopper — either a registered user (with `user_id` set) or a
 * guest snapshot keyed by email until a matching verified registration
 * back-fills the link. Engine spec §3.22.
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

use ArtisanPackUI\Ecommerce\Database\Factories\CustomerFactory;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * Customer Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int                                                                     $id
 * @property int|null                                                                $user_id
 * @property string                                                                  $email
 * @property string|null                                                             $first_name
 * @property string|null                                                             $last_name
 * @property string|null                                                             $phone
 * @property bool                                                                    $accepts_marketing
 * @property Carbon|null                                                             $accepts_marketing_at
 * @property int                                                                     $total_spent_amount
 * @property string|null                                                             $total_spent_currency
 * @property int                                                                     $orders_count
 * @property Carbon|null                                                             $last_ordered_at
 * @property array<string, mixed>                                                    $meta
 * @property \Illuminate\Database\Eloquent\Collection<int, CustomerAddress>          $addresses
 * @property \Illuminate\Database\Eloquent\Collection<int, CustomerClaimAttempt>     $claimAttempts
 * @property \Illuminate\Database\Eloquent\Collection<int, CustomerNotificationPreference>  $notificationPreferences
 * @property \Illuminate\Database\Eloquent\Collection<int, CustomerNote>             $notes
 */
class Customer extends Model
{
    use HasFactory;
    use Notifiable;

    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'ecommerce_customers';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'email',
        'first_name',
        'last_name',
        'phone',
        'accepts_marketing',
        'accepts_marketing_at',
        'total_spent_amount',
        'total_spent_currency',
        'orders_count',
        'last_ordered_at',
        'meta',
    ];

    /**
     * @since 1.0.0
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'accepts_marketing'  => false,
        'total_spent_amount' => 0,
        'orders_count'       => 0,
    ];

    /**
     * The customer record linked to a signed-in user, if any.
     *
     * @since 1.0.0
     *
     * @param  Authenticatable|null  $user  User.
     *
     * @return self|null
     */
    public static function forUser( ?Authenticatable $user ): ?self
    {
        $id = $user?->getAuthIdentifier();

        return is_numeric( $id ) ? self::query()->where( 'user_id', (int) $id )->first() : null;
    }

    /**
     * Orders that belong to this customer.
     *
     * @since 1.0.0
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany( Order::class );
    }

    /**
     * Addresses saved for this customer.
     *
     * @since 1.0.0
     *
     * @return HasMany<CustomerAddress, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany( CustomerAddress::class );
    }

    /**
     * Claim attempts made against this customer's email.
     *
     * @since 1.0.0
     *
     * @return HasMany<CustomerClaimAttempt, $this>
     */
    public function claimAttempts(): HasMany
    {
        return $this->hasMany( CustomerClaimAttempt::class );
    }

    /**
     * Per-channel, per-category notification opt-ins.
     *
     * @since 1.0.0
     *
     * @return HasMany<CustomerNotificationPreference, $this>
     */
    public function notificationPreferences(): HasMany
    {
        return $this->hasMany( CustomerNotificationPreference::class );
    }

    /**
     * Internal staff notes on this customer.
     *
     * @since 1.0.0
     *
     * @return HasMany<CustomerNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany( CustomerNote::class );
    }

    /**
     * Stores the email trimmed and lowercased, so lookups can use the
     * index with a plain comparison.
     *
     * @since 1.0.0
     *
     * @return Attribute<string|null, string|null>
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: static fn ( ?string $value ): ?string => null === $value ? null : mb_strtolower( trim( $value ) ),
        );
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id'              => 'integer',
            'accepts_marketing'    => 'boolean',
            'accepts_marketing_at' => 'datetime',
            'total_spent_amount'   => 'integer',
            'orders_count'         => 'integer',
            'last_ordered_at'      => 'datetime',
            'meta'                 => 'array',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return CustomerFactory
     */
    protected static function newFactory(): CustomerFactory
    {
        return CustomerFactory::new();
    }
}
