<?php

/**
 * KanbanAutomation factory.
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

use ArtisanPackUI\Ecommerce\Models\KanbanAutomation;
use ArtisanPackUI\Ecommerce\Models\KanbanBoard;
use ArtisanPackUI\Ecommerce\Models\KanbanColumn;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KanbanAutomation>
 *
 * @since 1.0.0
 */
class KanbanAutomationFactory extends Factory
{
    /**
     * @var class-string<KanbanAutomation>
     */
    protected $model = KanbanAutomation::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'board_id'       => KanbanBoard::factory(),
            'from_column_id' => null,
            'to_column_id'   => fn ( array $attributes ) => KanbanColumn::factory()->create( [ 'board_id' => $attributes['board_id'] ] )->id,
            'trigger_key'    => 'update-order-field',
            'trigger_config' => [ 'field' => 'meta.kanban_touched', 'value' => true ],
            'conditions'     => [],
            'is_active'      => true,
        ];
    }
}
