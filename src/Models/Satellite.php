<?php

/**
 * Satellite model.
 *
 * One satellite package known to the engine (engine spec §3.32, parent
 * plan §16.6). Rows are written by
 * {@see \ArtisanPackUI\Ecommerce\Registries\SatelliteRegistry::sync()} and
 * flipped between installed / uninstalled by the
 * `ecommerce:satellite:uninstall` and `ecommerce:satellite:reinstall`
 * commands. Rows are never deleted, so the audit can still see what a
 * removed satellite left behind.
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

use ArtisanPackUI\Ecommerce\Database\Factories\SatelliteFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Satellite Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int                                $id
 * @property string                             $package_name
 * @property string|null                        $label
 * @property string                             $version
 * @property array<int, string>|null            $migrations_namespace
 * @property array<int, string>                 $config_keys
 * @property array<int, string>                 $meta_namespaces
 * @property array<int, string>                 $owned_tables
 * @property array<string, array<int, string>>  $owned_columns
 * @property array<int, string>                 $product_types
 * @property string|null                        $uninstaller
 * @property string|null                        $verified_report_hash
 * @property Carbon                             $registered_at
 * @property Carbon|null                        $uninstalled_at
 */
class Satellite extends Model
{
    use HasFactory;

    /**
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
    protected $table = 'ecommerce_satellites';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'package_name',
        'label',
        'version',
        'migrations_namespace',
        'config_keys',
        'meta_namespaces',
        'owned_tables',
        'owned_columns',
        'product_types',
        'uninstaller',
        'verified_report_hash',
        'registered_at',
        'uninstalled_at',
    ];

    /**
     * @since 1.0.0
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'config_keys'     => '[]',
        'meta_namespaces' => '[]',
        'owned_tables'    => '[]',
        'owned_columns'   => '{}',
        'product_types'   => '[]',
    ];

    /**
     * Whether `ecommerce:satellite:uninstall` has deregistered this satellite.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function isUninstalled(): bool
    {
        return null !== $this->uninstalled_at;
    }

    /**
     * Satellites that have been uninstalled.
     *
     * @since 1.0.0
     *
     * @param  Builder<Satellite>  $query  Query.
     *
     * @return void
     */
    public function scopeUninstalled( Builder $query ): void
    {
        $query->whereNotNull( $this->qualifyColumn( 'uninstalled_at' ) );
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'migrations_namespace' => 'array',
            'config_keys'          => 'array',
            'meta_namespaces'      => 'array',
            'owned_tables'         => 'array',
            'owned_columns'        => 'array',
            'product_types'        => 'array',
            'registered_at'        => 'datetime',
            'uninstalled_at'       => 'datetime',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return SatelliteFactory
     */
    protected static function newFactory(): SatelliteFactory
    {
        return SatelliteFactory::new();
    }
}
