<?php

/**
 * KanbanBoard model.
 *
 * A kanban board over orders. An order can sit on any number of boards at
 * once (see {@see OrderBoardAssignment}); `routing_rules` decides which
 * boards catch a newly-placed order. Engine spec §3.28, parent plan §9.1.
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

use ArtisanPackUI\Ecommerce\Database\Factories\KanbanBoardFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * KanbanBoard Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int                                                                    $id
 * @property string                                                                 $key
 * @property string                                                                 $name
 * @property string|null                                                            $description
 * @property array<string, mixed>                                                   $routing_rules
 * @property bool                                                                   $is_default
 * @property bool                                                                   $is_active
 * @property int                                                                    $position
 * @property array<string, mixed>                                                   $settings
 * @property \Illuminate\Database\Eloquent\Collection<int, KanbanColumn>            $columns
 * @property \Illuminate\Database\Eloquent\Collection<int, KanbanAutomation>        $automations
 * @property \Illuminate\Database\Eloquent\Collection<int, OrderBoardAssignment>    $assignments
 */
class KanbanBoard extends Model
{
    use HasFactory;

    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'ecommerce_kanban_boards';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'key',
        'name',
        'description',
        'routing_rules',
        'is_default',
        'is_active',
        'position',
        'settings',
    ];

    /**
     * @since 1.0.0
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'routing_rules' => '{}',
        'is_default'    => false,
        'is_active'     => true,
        'position'      => 0,
        'settings'      => '{}',
    ];

    /**
     * Columns on this board, left to right.
     *
     * @since 1.0.0
     *
     * @return HasMany<KanbanColumn, $this>
     */
    public function columns(): HasMany
    {
        return $this->hasMany( KanbanColumn::class, 'board_id' )->orderBy( 'position' )->orderBy( 'id' );
    }

    /**
     * Automations attached to this board.
     *
     * @since 1.0.0
     *
     * @return HasMany<KanbanAutomation, $this>
     */
    public function automations(): HasMany
    {
        return $this->hasMany( KanbanAutomation::class, 'board_id' );
    }

    /**
     * Every assignment ever made to this board, including removed ones.
     *
     * @since 1.0.0
     *
     * @return HasMany<OrderBoardAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany( OrderBoardAssignment::class, 'board_id' );
    }

    /**
     * Assignments currently on the board (the board's cards).
     *
     * @since 1.0.0
     *
     * @return HasMany<OrderBoardAssignment, $this>
     */
    public function activeAssignments(): HasMany
    {
        return $this->assignments()->whereNull( 'removed_at' );
    }

    /**
     * Whether the board has no routing rules (and so catches every order).
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function hasRoutingRules(): bool
    {
        return [] !== array_filter( (array) $this->routing_rules );
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'routing_rules' => 'array',
            'is_default'    => 'boolean',
            'is_active'     => 'boolean',
            'position'      => 'integer',
            'settings'      => 'array',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return KanbanBoardFactory
     */
    protected static function newFactory(): KanbanBoardFactory
    {
        return KanbanBoardFactory::new();
    }
}
