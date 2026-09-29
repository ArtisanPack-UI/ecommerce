<?php

/**
 * ShippingMethod model.
 *
 * One shipping option inside a {@see ShippingZone}. `key` is either a
 * {@see \ArtisanPackUI\Ecommerce\Contracts\ShippingMethodType} registry key
 * (`flat-rate`, `free-shipping`, …) priced from `config`, or
 * `provider:{key}` to pull real-time rates from a registered
 * {@see \ArtisanPackUI\Ecommerce\Contracts\ShippingRateProvider}.
 *
 * Engine spec §3.25.
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

use ArtisanPackUI\Ecommerce\Database\Factories\ShippingMethodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * ShippingMethod Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int                  $id
 * @property int                  $zone_id
 * @property string               $key
 * @property string               $label
 * @property array<string, mixed> $config
 * @property string|null          $tax_class_key
 * @property bool                 $is_active
 * @property int                  $position
 * @property Carbon|null          $created_at
 * @property Carbon|null          $updated_at
 * @property ShippingZone         $zone
 */
class ShippingMethod extends Model
{
    use HasFactory;

    /**
     * Key prefix marking a method as backed by a real-time rate provider.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const PROVIDER_PREFIX = 'provider:';

    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'shipping_methods';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'zone_id',
        'key',
        'label',
        'config',
        'tax_class_key',
        'is_active',
        'position',
    ];

    /**
     * @since 1.0.0
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'config'    => '{}',
        'is_active' => true,
        'position'  => 0,
    ];

    /**
     * Zone this method belongs to.
     *
     * @since 1.0.0
     *
     * @return BelongsTo<ShippingZone, $this>
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo( ShippingZone::class, 'zone_id' );
    }

    /**
     * Rate-provider key when this method is `provider:{key}`, else null.
     *
     * @since 1.0.0
     *
     * @return string|null
     */
    public function providerKey(): ?string
    {
        return str_starts_with( $this->key, self::PROVIDER_PREFIX )
            ? substr( $this->key, strlen( self::PROVIDER_PREFIX ) )
            : null;
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'zone_id'   => 'integer',
            'config'    => 'array',
            'is_active' => 'boolean',
            'position'  => 'integer',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return ShippingMethodFactory
     */
    protected static function newFactory(): ShippingMethodFactory
    {
        return ShippingMethodFactory::new();
    }
}
