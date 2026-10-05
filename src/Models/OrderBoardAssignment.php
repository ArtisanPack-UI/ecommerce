<?php

/**
 * OrderBoardAssignment model.
 *
 * Places an {@see Order} on a {@see KanbanBoard} — one card. An order may be
 * on any number of boards at once; each assignment carries its own
 * `substatus_id` (the card's column), so an order can be at "Printing" on a
 * production board and "Awaiting label" on a shipping board at the same
 * instant. Removing an order from a board stamps `removed_at` rather than
 * deleting the row. Engine spec §3.28, parent plan §9.2.
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

use ArtisanPackUI\Ecommerce\Database\Factories\OrderBoardAssignmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * OrderBoardAssignment Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int             $id
 * @property int             $order_id
 * @property int             $board_id
 * @property int             $substatus_id
 * @property Carbon          $assigned_at
 * @property Carbon|null     $moved_at
 * @property Carbon|null     $removed_at
 * @property Order           $order
 * @property KanbanBoard     $board
 * @property OrderSubstatus  $substatus
 *
 * @method static Builder<static> active()
 */
class OrderBoardAssignment extends Model
{
    use HasFactory;

    /**
     * Lifecycle is tracked by `assigned_at` / `moved_at` / `removed_at`.
     *
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
    protected $table = 'ecommerce_order_board_assignments';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'order_id',
        'board_id',
        'substatus_id',
        'assigned_at',
        'moved_at',
        'removed_at',
    ];

    /**
     * @since 1.0.0
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo( Order::class );
    }

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
     * The sub-status this card sits on (its column's sub-status).
     *
     * @since 1.0.0
     *
     * @return BelongsTo<OrderSubstatus, $this>
     */
    public function substatus(): BelongsTo
    {
        return $this->belongsTo( OrderSubstatus::class, 'substatus_id' );
    }

    /**
     * The column the card is in on its board, if the board still has a
     * column for the assignment's sub-status.
     *
     * @since 1.0.0
     *
     * @return KanbanColumn|null
     */
    public function currentColumn(): ?KanbanColumn
    {
        return KanbanColumn::query()
            ->where( 'board_id', $this->board_id )
            ->where( 'substatus_id', $this->substatus_id )
            ->first();
    }

    /**
     * Whether the assignment is currently on its board.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return null === $this->removed_at;
    }

    /**
     * Scope: assignments currently on their board.
     *
     * @since 1.0.0
     *
     * @param  Builder<static>  $query  Query.
     *
     * @return void
     */
    public function scopeActive( Builder $query ): void
    {
        $query->whereNull( $this->qualifyColumn( 'removed_at' ) );
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order_id'     => 'integer',
            'board_id'     => 'integer',
            'substatus_id' => 'integer',
            'assigned_at'  => 'datetime',
            'moved_at'     => 'datetime',
            'removed_at'   => 'datetime',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return OrderBoardAssignmentFactory
     */
    protected static function newFactory(): OrderBoardAssignmentFactory
    {
        return OrderBoardAssignmentFactory::new();
    }
}
