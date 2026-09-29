<?php

/**
 * Satellite factory.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Database\Factories;

use ArtisanPackUI\Ecommerce\Models\Satellite;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Satellite>
 *
 * @since 1.0.0
 */
class SatelliteFactory extends Factory
{
    /**
     * @var class-string<Satellite>
     */
    protected $model = Satellite::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'package_name'         => 'acme/ecommerce-' . $this->faker->unique()->slug( 2 ),
            'label'                => $this->faker->words( 2, true ),
            'version'              => '1.0.0',
            'migrations_namespace' => [],
            'config_keys'          => [],
            'meta_namespaces'      => [],
            'owned_tables'         => [],
            'owned_columns'        => [],
            'product_types'        => [],
            'uninstaller'          => null,
            'verified_report_hash' => null,
            'registered_at'        => Carbon::now(),
            'uninstalled_at'       => null,
        ];
    }

    /**
     * State: the satellite has been uninstalled.
     *
     * @since 1.0.0
     *
     * @return static
     */
    public function uninstalled(): static
    {
        return $this->state( fn (): array => [ 'uninstalled_at' => Carbon::now() ] );
    }
}
