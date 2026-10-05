<?php

/**
 * KanbanColumn model.
 *
 * One column on a {@see KanbanBoard}, 1:1 with an {@see OrderSubstatus}: a
 * card is in this column when its assignment's `substatus_id` equals the
 * column's. Display overrides fall back to the sub-status's own label,
 * color, and icon. Engine spec §3.28, parent plan §9.1.
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

use ArtisanPackUI\Ecommerce\Database\Factories\KanbanColumnFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * KanbanColumn Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int                 $id
 * @property int                 $board_id
 * @property int                 $substatus_id
 * @property string|null         $label_override
 * @property string|null         $color_override
 * @property string|null         $icon_override
 * @property int                 $position
 * @property int|null            $wip_limit
 * @property array<int, string>  $card_widgets
 * @property KanbanBoard         $board
 * @property OrderSubstatus      $substatus
 */
class KanbanColumn extends Model
{
    use HasFactory;

    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'ecommerce_kanban_columns';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'board_id',
        'substatus_id',
        'label_override',
        'color_override',
        'icon_override',
        'position',
        'wip_limit',
        'card_widgets',
    ];

    /**
     * @since 1.0.0
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'position'     => 0,
        'card_widgets' => '[]',
    ];

    /**
     * @since 1.0.0
     *
     * @return BelongsTo<KanbanBoard, $this>
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo( KanbanBoard::class, 'board_id' );
    }

    /**
     * @since 1.0.0
     *
     * @return BelongsTo<OrderSubstatus, $this>
     */
    public function substatus(): BelongsTo
    {
        return $this->belongsTo( OrderSubstatus::class, 'substatus_id' );
    }

    /**
     * Number of cards in the column right now.
     *
     * @since 1.0.0
     *
     * @return int
     */
    public function cardCount(): int
    {
        return OrderBoardAssignment::query()
            ->where( 'board_id', $this->board_id )
            ->where( 'substatus_id', $this->substatus_id )
            ->whereNull( 'removed_at' )
            ->count();
    }

    /**
     * Column label: the override, else the sub-status label.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function displayLabel(): string
    {
        return (string) ( $this->label_override ?? $this->substatus?->label ?? '' );
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'board_id'     => 'integer',
            'substatus_id' => 'integer',
            'position'     => 'integer',
            'wip_limit'    => 'integer',
            'card_widgets' => 'array',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return KanbanColumnFactory
     */
    protected static function newFactory(): KanbanColumnFactory
    {
        return KanbanColumnFactory::new();
    }
}
