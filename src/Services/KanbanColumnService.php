<?php

/**
 * KanbanColumnService.
 *
 * Column writes for every kanban UI — REST, the admin, and the board-native
 * editors (ArtisanPack-UI/ecommerce#203). The rules live here once: one
 * column per sub-status per board, `#RRGGBB` colors, a positive WIP limit,
 * and the widget list shape from {@see KanbanWidgetList}.
 *
 * Each write fires an `ap.ecommerce.kanban.column{Created,Updated,Reordered,Deleted}`
 * action after commit and dispatches {@see KanbanBoardConfigChanged}, which
 * the board broadcast relays to connected UIs.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.2.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Services;

use ArtisanPackUI\Ecommerce\Events\KanbanBoardConfigChanged;
use ArtisanPackUI\Ecommerce\Exceptions\KanbanColumnNotEmptyException;
use ArtisanPackUI\Ecommerce\Exceptions\KanbanOperationException;
use ArtisanPackUI\Ecommerce\Kanban\KanbanWidgetList;
use ArtisanPackUI\Ecommerce\Models\KanbanBoard;
use ArtisanPackUI\Ecommerce\Models\KanbanColumn;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderBoardAssignment;
use ArtisanPackUI\Ecommerce\Models\OrderSubstatus;
use ArtisanPackUI\Ecommerce\Support\AfterCommit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.2.0
 */
class KanbanColumnService
{
    /**
     * Column attributes a write may set.
     *
     * @since 1.2.0
     *
     * @var array<int, string>
     */
    public const ATTRIBUTES = [ 'substatus_id', 'label_override', 'color_override', 'icon_override', 'position', 'wip_limit', 'card_widgets' ];

    /**
     * Most cards a delete moves to another column. Each is a real move (status
     * roll-up, automations, broadcasts) inside one transaction, so a fuller
     * column is refused (`too-many-cards`) and its cards must be moved first.
     *
     * @since 1.2.0
     *
     * @var int
     */
    public const MAX_CARDS_MOVED_ON_DELETE = 200;

    /**
     * @since 1.2.0
     *
     * @param  KanbanBoardService  $cards  Moves cards out of a column being deleted.
     */
    public function __construct( private readonly KanbanBoardService $cards )
    {
    }

    /**
     * Validation rules for a column write on board `$boardId`. With
     * `$column` (an update) every rule is optional; without it `substatus_id`
     * is required.
     *
     * @since 1.2.0
     *
     * @param  int|null              $boardId  Board the column is (or will be) on.
     * @param  KanbanColumn|null     $column   Column being updated; null on create.
     * @param  array<string, mixed>  $input    Submitted data (the widget list rules depend on it).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules( ?int $boardId, ?KanbanColumn $column, array $input = [] ): array
    {
        $rules = [
            'substatus_id'   => [
                null === $column ? 'required' : 'sometimes',
                'integer',
                Rule::exists( OrderSubstatus::class, 'id' ),
                Rule::unique( KanbanColumn::class, 'substatus_id' )
                    ->where( 'board_id', $boardId )
                    ->ignore( $column?->id ),
            ],
            'label_override' => [ 'sometimes', 'nullable', 'string', 'max:120' ],
            'color_override' => [ 'sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/' ],
            'icon_override'  => [ 'sometimes', 'nullable', 'string', 'max:80' ],
            'position'       => [ 'sometimes', 'integer', 'min:0' ],
            'wip_limit'      => [ 'sometimes', 'nullable', 'integer', 'min:1' ],
        ];

        $widgets                 = KanbanWidgetList::rules( 'card_widgets', $input['card_widgets'] ?? null );
        $widgets['card_widgets'] = [ 'sometimes', ...$widgets['card_widgets'] ];

        return $rules + $widgets;
    }

    /**
     * Readable attribute names for the column rules' messages.
     *
     * @since 1.2.0
     *
     * @param  array<string, mixed>  $input  Submitted data.
     *
     * @return array<string, string>
     */
    public static function attributes( array $input = [] ): array
    {
        return [
            'substatus_id'   => __( 'sub-status' ),
            'label_override' => __( 'label' ),
            'color_override' => __( 'color' ),
            'icon_override'  => __( 'icon' ),
            'position'       => __( 'position' ),
            'wip_limit'      => __( 'WIP limit' ),
            'card_widgets'   => __( 'card widgets' ),
        ] + KanbanWidgetList::attributes( 'card_widgets', $input['card_widgets'] ?? null );
    }

    /**
     * Adds a column to `$board`. Without a `position` it goes last.
     *
     * @since 1.2.0
     *
     * @param  KanbanBoard           $board        Board.
     * @param  array<string, mixed>  $data         Column attributes.
     * @param  int|null              $actorUserId  Acting user, if any.
     *
     * @throws ValidationException When the data breaks a column rule.
     *
     * @return KanbanColumn
     */
    public function create( KanbanBoard $board, array $data, ?int $actorUserId = null ): KanbanColumn
    {
        $data = $this->validated( (int) $board->id, null, $data );

        $column = DB::transaction( function () use ( $board, $data ): KanbanColumn {
            $data['position'] ??= (int) KanbanColumn::query()->where( 'board_id', $board->id )->max( 'position' ) + 1;

            return $board->columns()->create( $data );
        } );

        $this->changed( $board, KanbanBoardConfigChanged::COLUMN_CREATED, (int) $column->id, $actorUserId );
        AfterCommit::action( 'ap.ecommerce.kanban.columnCreated', $column, $actorUserId );

        return $column->load( 'substatus' );
    }

    /**
     * Updates `$column`. Its sub-status can only change while it is empty.
     *
     * @since 1.2.0
     *
     * @param  KanbanColumn          $column       Column.
     * @param  array<string, mixed>  $data         Attributes to change.
     * @param  int|null              $actorUserId  Acting user, if any.
     *
     * @throws ValidationException When the data breaks a column rule.
     * @throws KanbanOperationException When the sub-status changes on a column with cards (`column-not-empty`).
     *
     * @return KanbanColumn The column as saved (a fresh instance).
     */
    public function update( KanbanColumn $column, array $data, ?int $actorUserId = null ): KanbanColumn
    {
        $data = $this->validated( (int) $column->board_id, $column, $data );

        [ $column, $changes ] = DB::transaction( function () use ( $column, $data ): array {
            // Work on the locked row, not the caller's instance, so stale
            // attributes or changes on it can't leak into this write.
            $locked = KanbanColumn::query()->whereKey( $column->id )->lockForUpdate()->firstOrFail();

            if ( array_key_exists( 'substatus_id', $data ) && (int) $data['substatus_id'] !== (int) $locked->substatus_id && $locked->cardCount() > 0 ) {
                throw new KanbanOperationException( 'column-not-empty', __( 'Move the cards out of this column before changing its sub-status.' ) );
            }

            $locked->fill( $data );

            if ( ! $locked->isDirty() ) {
                return [ $locked, [] ];
            }

            $locked->save();

            return [ $locked, $locked->getChanges() ];
        } );

        if ( [] !== $changes ) {
            $this->changed( $column->board, KanbanBoardConfigChanged::COLUMN_UPDATED, (int) $column->id, $actorUserId );
            AfterCommit::action( 'ap.ecommerce.kanban.columnUpdated', $column, $changes, $actorUserId );
        }

        return $column->load( 'substatus' );
    }

    /**
     * Puts `$board`'s columns in the order of `$columnIds`, writing every
     * position in one transaction. The list must name each of the board's
     * columns exactly once.
     *
     * @since 1.2.0
     *
     * @param  KanbanBoard      $board        Board.
     * @param  array<int, int>  $columnIds    Column ids, first to last.
     * @param  int|null         $actorUserId  Acting user, if any.
     *
     * @throws KanbanOperationException When the list isn't exactly the board's columns (`invalid-column-order`).
     *
     * @return Collection<int, KanbanColumn> The columns in their new order.
     */
    public function reorder( KanbanBoard $board, array $columnIds, ?int $actorUserId = null ): Collection
    {
        $ids = array_map( 'intval', array_values( array_filter( $columnIds, 'is_numeric' ) ) );

        $columns = DB::transaction( function () use ( $board, $ids, $columnIds ): Collection {
            $columns = KanbanColumn::query()->where( 'board_id', $board->id )->orderBy( 'id' )->lockForUpdate()->get()->keyBy( 'id' );
            $current = array_map( 'intval', $columns->keys()->all() );
            $sorted  = $ids;

            sort( $sorted );
            sort( $current );

            if ( count( $ids ) !== count( $columnIds ) || $sorted !== $current ) {
                throw new KanbanOperationException( 'invalid-column-order', __( 'The column order must list each of board ":board"\'s columns exactly once.', [ 'board' => $board->name ] ) );
            }

            foreach ( $ids as $position => $id ) {
                $column = $columns->get( $id );

                if ( (int) $column->position !== $position ) {
                    $column->position = $position;
                    $column->save();
                }
            }

            return new Collection( array_map( static fn ( int $id ): KanbanColumn => $columns->get( $id ), $ids ) );
        } );

        $this->changed( $board, KanbanBoardConfigChanged::COLUMNS_REORDERED, null, $actorUserId );
        AfterCommit::action( 'ap.ecommerce.kanban.columnReordered', $board, $ids, $actorUserId );

        return $columns->load( 'substatus' );
    }

    /**
     * Deletes `$column` and the automations that target it. A column with
     * cards is refused unless `$moveTo` names another column on the same
     * board; its cards are then moved there first (through
     * {@see KanbanBoardService::move()}, so status roll-ups, WIP limits, and
     * the target column's automations apply) and the whole delete rolls back
     * if any move is refused. At most {@see self::MAX_CARDS_MOVED_ON_DELETE}
     * cards are moved this way. Callers check `kanbanCard.move` before
     * passing `$moveTo`.
     *
     * @since 1.2.0
     *
     * @param  KanbanColumn       $column       Column to delete.
     * @param  KanbanColumn|null  $moveTo       Column to move remaining cards to.
     * @param  int|null           $actorUserId  Acting user, if any.
     *
     * @throws KanbanColumnNotEmptyException When the column has cards and `$moveTo` is null.
     * @throws KanbanOperationException When `$moveTo` is the column itself or on another board, there are too many cards to move, or a card move is refused.
     *
     * @return KanbanColumn The deleted column.
     */
    public function delete( KanbanColumn $column, ?KanbanColumn $moveTo = null, ?int $actorUserId = null ): KanbanColumn
    {
        $board = $column->board;

        DB::transaction( function () use ( $column, $moveTo, $actorUserId, $board ): void {
            KanbanColumn::query()->whereKey( $column->id )->lockForUpdate()->first();

            $count = $column->cardCount();

            if ( $count > 0 && null === $moveTo ) {
                throw new KanbanColumnNotEmptyException( $count, trans_choice(
                    'Column ":column" still has :count card. Move it out first.|Column ":column" still has :count cards. Move them out first.',
                    $count,
                    [ 'column' => $column->displayLabel(), 'count' => $count ],
                ) );
            }

            if ( $count > self::MAX_CARDS_MOVED_ON_DELETE ) {
                throw new KanbanOperationException( 'too-many-cards', __( 'Column ":column" has :count cards, more than can be moved while deleting it (:max). Move them out first.', [
                    'column' => $column->displayLabel(),
                    'count'  => $count,
                    'max'    => self::MAX_CARDS_MOVED_ON_DELETE,
                ] ) );
            }

            if ( $count > 0 && ( (int) $moveTo->board_id !== (int) $column->board_id || (int) $moveTo->id === (int) $column->id ) ) {
                throw new KanbanOperationException( 'column-not-on-board', __( 'Pick another column on board ":board" for the cards.', [ 'board' => $board->name ] ) );
            }

            if ( $count > 0 ) {
                $this->moveCards( $column, $moveTo, $actorUserId );
            }

            $column->load( 'substatus' )->delete();
        } );

        $this->changed( $board, KanbanBoardConfigChanged::COLUMN_DELETED, (int) $column->id, $actorUserId );
        AfterCommit::action( 'ap.ecommerce.kanban.columnDeleted', $column, $actorUserId );

        return $column;
    }

    /**
     * Validates column data and keeps only column attributes.
     *
     * @since 1.2.0
     *
     * @param  int                   $boardId  Board.
     * @param  KanbanColumn|null     $column   Column being updated.
     * @param  array<string, mixed>  $data     Submitted data.
     *
     * @throws ValidationException When the data breaks a rule.
     *
     * @return array<string, mixed>
     */
    protected function validated( int $boardId, ?KanbanColumn $column, array $data ): array
    {
        $data = array_intersect_key( $data, array_flip( self::ATTRIBUTES ) );

        Validator::make( $data, self::rules( $boardId, $column, $data ), [], self::attributes( $data ) )->validate();

        return $data;
    }

    /**
     * Moves every card in `$from` into `$to`, oldest card first.
     *
     * @since 1.2.0
     *
     * @param  KanbanColumn  $from         Column being emptied.
     * @param  KanbanColumn  $to           Destination.
     * @param  int|null      $actorUserId  Acting user.
     *
     * @return void
     */
    protected function moveCards( KanbanColumn $from, KanbanColumn $to, ?int $actorUserId ): void
    {
        $orderIds = OrderBoardAssignment::query()
            ->active()
            ->where( 'board_id', $from->board_id )
            ->where( 'substatus_id', $from->substatus_id )
            ->orderBy( 'position' )
            ->orderBy( 'id' )
            ->pluck( 'order_id' );

        $orders = Order::query()->whereKey( $orderIds )->get()->keyBy( 'id' );

        foreach ( $orderIds as $orderId ) {
            $this->cards->move( $orders->get( $orderId ), $to, $actorUserId, __( 'Column ":column" was deleted', [ 'column' => $from->displayLabel() ] ) );
        }
    }

    /**
     * Dispatches {@see KanbanBoardConfigChanged}.
     *
     * @since 1.2.0
     *
     * @param  KanbanBoard  $board        Board.
     * @param  string       $change       What changed.
     * @param  int|null     $columnId     Column, if any.
     * @param  int|null     $actorUserId  Acting user.
     *
     * @return void
     */
    protected function changed( KanbanBoard $board, string $change, ?int $columnId, ?int $actorUserId ): void
    {
        Event::dispatch( new KanbanBoardConfigChanged( $board, $change, $columnId, $actorUserId ) );
    }
}
