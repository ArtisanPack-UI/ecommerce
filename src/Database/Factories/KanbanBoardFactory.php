<?php

/**
 * KanbanBoard factory.
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

use ArtisanPackUI\Ecommerce\Models\KanbanBoard;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<KanbanBoard>
 *
 * @since 1.0.0
 */
class KanbanBoardFactory extends Factory
{
    /**
     * @var class-string<KanbanBoard>
     */
    protected $model = KanbanBoard::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = ucfirst( $this->faker->unique()->words( 2, true ) );

        return [
            'key'           => Str::slug( $name ) . '-' . Str::lower( Str::random( 4 ) ),
            'name'          => $name,
            'description'   => null,
            'routing_rules' => [],
            'is_default'    => false,
            'is_active'     => true,
            'position'      => 0,
            'settings'      => [],
        ];
    }

    /**
     * State: the fallback board for orders no other board catches.
     *
     * @since 1.0.0
     *
     * @return static
     */
    public function asDefault(): static
    {
        return $this->state( fn () => [ 'is_default' => true ] );
    }

    /**
     * State: board is switched off (routing skips it).
     *
     * @since 1.0.0
     *
     * @return static
     */
    public function inactive(): static
    {
        return $this->state( fn () => [ 'is_active' => false ] );
    }

    /**
     * State: board catches orders matching `$rules`.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $rules  Routing condition tree.
     *
     * @return static
     */
    public function routing( array $rules ): static
    {
        return $this->state( fn () => [ 'routing_rules' => $rules ] );
    }
}
