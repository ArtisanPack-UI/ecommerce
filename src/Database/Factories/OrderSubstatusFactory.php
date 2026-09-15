<?php

/**
 * OrderSubstatus factory.
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

use ArtisanPackUI\Ecommerce\Models\OrderSubstatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OrderSubstatus>
 *
 * @since 1.0.0
 */
class OrderSubstatusFactory extends Factory
{
    /**
     * @var class-string<OrderSubstatus>
     */
    protected $model = OrderSubstatus::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'system_status' => 'processing',
            'key'           => Str::slug( $this->faker->unique()->words( 2, true ) ),
            'label'         => ucfirst( $this->faker->words( 2, true ) ),
            'color'         => null,
            'icon'          => null,
            'position'      => 0,
            'is_terminal'   => false,
        ];
    }

    /**
     * State: sub-status belongs to the given system status.
     *
     * @since 1.0.0
     *
     * @param  string  $systemStatus
     *
     * @return static
     */
    public function forSystemStatus( string $systemStatus ): static
    {
        return $this->state( fn () => [ 'system_status' => $systemStatus ] );
    }

    /**
     * State: sub-status is terminal within its system status.
     *
     * @since 1.0.0
     *
     * @return static
     */
    public function terminal(): static
    {
        return $this->state( fn () => [ 'is_terminal' => true ] );
    }
}
