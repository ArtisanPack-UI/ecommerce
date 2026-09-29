<?php

/**
 * OrderBoardAssignment factory.
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
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderBoardAssignment;
use ArtisanPackUI\Ecommerce\Models\OrderSubstatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderBoardAssignment>
 *
 * @since 1.0.0
 */
class OrderBoardAssignmentFactory extends Factory
{
    /**
     * @var class-string<OrderBoardAssignment>
     */
    protected $model = OrderBoardAssignment::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id'     => Order::factory(),
            'board_id'     => KanbanBoard::factory(),
            'substatus_id' => fn (): int => (int) OrderSubstatus::query()->where( 'system_status', 'pending' )->value( 'id' ),
            'assigned_at'  => now(),
            'moved_at'     => now(),
            'removed_at'   => null,
        ];
    }

    /**
     * State: assignment was removed from its board.
     *
     * @since 1.0.0
     *
     * @return static
     */
    public function removed(): static
    {
        return $this->state( fn () => [ 'removed_at' => now() ] );
    }
}
