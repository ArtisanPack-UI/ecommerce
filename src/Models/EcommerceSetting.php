<?php

/**
 * EcommerceSetting model.
 *
 * One stored store setting (engine issue #145). Rows are written only
 * through {@see \ArtisanPackUI\Ecommerce\Settings\SettingsRepository},
 * which validates the key against the allow-list and keeps the cache in
 * step. The model also anchors {@see \ArtisanPackUI\Ecommerce\Policies\SettingsPolicy},
 * so `$user->can( 'update', EcommerceSetting::class )` works.
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

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * EcommerceSetting Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int         $id
 * @property string      $key
 * @property mixed       $value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EcommerceSetting extends Model
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'ecommerce_settings';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'key',
        'value',
    ];

    /**
     * The stored value, decoded from JSON. Scalars and `null` round-trip
     * as themselves rather than through an array cast.
     *
     * @since 1.0.0
     *
     * @param  string|null  $value  Raw column value.
     *
     * @return mixed
     */
    public function getValueAttribute( ?string $value ): mixed
    {
        return null === $value ? null : json_decode( $value, true );
    }

    /**
     * Stores the value as JSON.
     *
     * @since 1.0.0
     *
     * @param  mixed  $value  Value to store.
     *
     * @return void
     */
    public function setValueAttribute( mixed $value ): void
    {
        $this->attributes['value'] = json_encode( $value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION );
    }
}
