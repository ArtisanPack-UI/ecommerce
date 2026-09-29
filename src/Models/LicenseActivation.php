<?php

/**
 * LicenseActivation model.
 *
 * One machine a {@see LicenseKey} is activated on, identified by the
 * fingerprint the customer's app sends to `POST license/validate`.
 * Engine spec §3.27.
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

use ArtisanPackUI\Ecommerce\Database\Factories\LicenseActivationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * LicenseActivation Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int          $id
 * @property int          $license_key_id
 * @property string|null  $machine_fingerprint
 * @property Carbon|null  $activated_at
 * @property Carbon|null  $last_seen_at
 * @property string|null  $ip_address
 * @property LicenseKey   $licenseKey
 */
class LicenseActivation extends Model
{
    use HasFactory;

    /**
     * The table tracks its own `activated_at` / `last_seen_at`.
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
    protected $table = 'license_activations';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'license_key_id',
        'machine_fingerprint',
        'activated_at',
        'last_seen_at',
        'ip_address',
    ];

    /**
     * @since 1.0.0
     *
     * @return BelongsTo<LicenseKey, $this>
     */
    public function licenseKey(): BelongsTo
    {
        return $this->belongsTo( LicenseKey::class );
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'license_key_id' => 'integer',
            'activated_at'   => 'datetime',
            'last_seen_at'   => 'datetime',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return LicenseActivationFactory
     */
    protected static function newFactory(): LicenseActivationFactory
    {
        return LicenseActivationFactory::new();
    }
}
