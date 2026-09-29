<?php

/**
 * KanbanColumn factory.
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
use ArtisanPackUI\Ecommerce\Models\KanbanColumn;
use ArtisanPackUI\Ecommerce\Models\OrderSubstatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KanbanColumn>
 *
 * @since 1.0.0
 */
class KanbanColumnFactory extends Factory
{
    /**
     * @var class-string<KanbanColumn>
     */
    protected $model = KanbanColumn::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'board_id'       => KanbanBoard::factory(),
            'substatus_id'   => OrderSubstatus::factory(),
            'label_override' => null,
            'color_override' => null,
            'icon_override'  => null,
            'position'       => 0,
            'wip_limit'      => null,
            'card_widgets'   => [],
        ];
    }

    /**
     * State: column for a sub-status of the given system status.
     *
     * @since 1.0.0
     *
     * @param  string  $systemStatus  System status the column's sub-status belongs to.
     *
     * @return static
     */
    public function forSystemStatus( string $systemStatus ): static
    {
        return $this->state( fn () => [ 'substatus_id' => OrderSubstatus::factory()->forSystemStatus( $systemStatus ) ] );
    }
}
