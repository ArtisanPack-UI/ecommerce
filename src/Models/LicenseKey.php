<?php

/**
 * LicenseKey model.
 *
 * A software license key issued for an {@see OrderItem}. A key is valid
 * while it is not revoked and not expired; each distinct machine
 * fingerprint that validates it records a {@see LicenseActivation}, up to
 * `activations_limit` (null = unlimited). Engine spec §3.27.
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

use ArtisanPackUI\Ecommerce\Database\Factories\LicenseKeyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * LicenseKey Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int                                                                   $id
 * @property int                                                                   $order_item_id
 * @property int|null                                                              $digital_file_id
 * @property string                                                                $key
 * @property int|null                                                              $activations_limit
 * @property int                                                                   $activations_count
 * @property Carbon|null                                                           $expires_at
 * @property bool                                                                  $is_revoked
 * @property Carbon|null                                                           $revoked_at
 * @property array<string, mixed>                                                  $meta
 * @property Carbon|null                                                           $created_at
 * @property Carbon|null                                                           $updated_at
 * @property OrderItem                                                             $orderItem
 * @property DigitalFile|null                                                      $file
 * @property \Illuminate\Database\Eloquent\Collection<int, LicenseActivation>      $activations
 */
class LicenseKey extends Model
{
    use HasFactory;

    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'license_keys';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'order_item_id',
        'digital_file_id',
        'key',
        'activations_limit',
        'activations_count',
        'expires_at',
        'is_revoked',
        'revoked_at',
        'meta',
    ];

    /**
     * @since 1.0.0
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'activations_count' => 0,
        'is_revoked'        => false,
        'meta'              => '{}',
    ];

    /**
     * Normalizes a key as typed by a customer: trimmed and upper-cased.
     *
     * @since 1.0.0
     *
     * @param  string  $key  Key.
     *
     * @return string
     */
    public static function normalize( string $key ): string
    {
        return strtoupper( trim( $key ) );
    }

    /**
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
     * @return BelongsTo<DigitalFile, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo( DigitalFile::class, 'digital_file_id' );
    }

    /**
     * @since 1.0.0
     *
     * @return HasMany<LicenseActivation, $this>
     */
    public function activations(): HasMany
    {
        return $this->hasMany( LicenseActivation::class );
    }

    /**
     * Whether the key has passed its expiry.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function isExpired(): bool
    {
        return null !== $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Whether another machine may be activated.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function hasActivationsRemaining(): bool
    {
        return null === $this->activations_limit || $this->activations_count < $this->activations_limit;
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order_item_id'     => 'integer',
            'digital_file_id'   => 'integer',
            'activations_limit' => 'integer',
            'activations_count' => 'integer',
            'expires_at'        => 'datetime',
            'is_revoked'        => 'boolean',
            'revoked_at'        => 'datetime',
            'meta'              => 'array',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return LicenseKeyFactory
     */
    protected static function newFactory(): LicenseKeyFactory
    {
        return LicenseKeyFactory::new();
    }
}
