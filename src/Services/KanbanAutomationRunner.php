<?php

/**
 * KanbanAutomationRunner.
 *
 * Runs a board's automations after a card move (parent plan §9.4). An
 * automation matches when it is active, its `to_column_id` is the column
 * the card entered, its `from_column_id` is the column the card left (or
 * null, for "from any column"), and its `conditions` tree passes for the
 * order. Each match fires its registered
 * {@see \ArtisanPackUI\Ecommerce\Contracts\KanbanAutomationTrigger}.
 *
 * Automations are independent: an unknown trigger key or a trigger that
 * throws is logged and recorded on the order timeline, and the remaining
 * automations still run. Successful runs fire
 * `ap.ecommerce.kanban.automationFired` and dispatch
 * {@see KanbanAutomationTriggered}.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Services;

use ArtisanPackUI\Ecommerce\Events\KanbanAutomationTriggered;
use ArtisanPackUI\Ecommerce\Events\KanbanCardMoved;
use ArtisanPackUI\Ecommerce\Kanban\OrderConditionEvaluator;
use ArtisanPackUI\Ecommerce\Models\KanbanAutomation;
use ArtisanPackUI\Ecommerce\Models\KanbanBoard;
use ArtisanPackUI\Ecommerce\Models\KanbanColumn;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderTimelineEntry;
use ArtisanPackUI\Ecommerce\Registries\KanbanAutomationRegistry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class KanbanAutomationRunner
{
    /**
     * @since 1.0.0
     *
     * @param  KanbanAutomationRegistry  $triggers    Registered triggers.
     * @param  OrderConditionEvaluator   $conditions  Evaluates automation conditions.
     */
    public function __construct(
        private readonly KanbanAutomationRegistry $triggers,
        private readonly OrderConditionEvaluator $conditions,
    ) {
    }

    /**
     * {@see KanbanCardMoved} listener.
     *
     * @since 1.0.0
     *
     * @param  KanbanCardMoved  $event  Event.
     *
     * @return void
     */
    public function handle( KanbanCardMoved $event ): void
    {
        $this->run( $event->order, $event->from, $event->to, $event->board );
    }

    /**
     * Runs every automation matching a move from `$from` to `$to`.
     *
     * @since 1.0.0
     *
     * @param  Order         $order  Order whose card moved.
     * @param  KanbanColumn  $from   Column the card left.
     * @param  KanbanColumn  $to     Column the card entered.
     * @param  KanbanBoard   $board  Board.
     *
     * @return array<int, KanbanAutomation> The automations that fired successfully.
     */
    public function run( Order $order, KanbanColumn $from, KanbanColumn $to, KanbanBoard $board ): array
    {
        $fired = [];

        foreach ( $this->matching( $from, $to, $board ) as $automation ) {
            if ( [] !== (array) $automation->conditions && ! $this->conditions->passes( (array) $automation->conditions, $order ) ) {
                continue;
            }

            if ( $this->fire( $automation, $order ) ) {
                $fired[] = $automation;
            }
        }

        return $fired;
    }

    /**
     * Active automations on `$board` for a move from `$from` into `$to`.
     *
     * @since 1.0.0
     *
     * @param  KanbanColumn  $from   Column left.
     * @param  KanbanColumn  $to     Column entered.
     * @param  KanbanBoard   $board  Board.
     *
     * @return Collection<int, KanbanAutomation>
     */
    protected function matching( KanbanColumn $from, KanbanColumn $to, KanbanBoard $board ): Collection
    {
        return KanbanAutomation::query()
            ->where( 'board_id', $board->id )
            ->where( 'is_active', true )
            ->where( 'to_column_id', $to->id )
            ->where( function ( $query ) use ( $from ): void {
                $query->whereNull( 'from_column_id' );

                if ( $from->exists ) {
                    $query->orWhere( 'from_column_id', $from->id );
                }
            } )
            ->orderBy( 'id' )
            ->get();
    }

    /**
     * Fires one automation, isolating failures.
     *
     * @since 1.0.0
     *
     * @param  KanbanAutomation  $automation  Automation.
     * @param  Order             $order       Order.
     *
     * @return bool Whether the trigger ran without throwing.
     */
    protected function fire( KanbanAutomation $automation, Order $order ): bool
    {
        $key = (string) $automation->trigger_key;

        if ( ! $this->triggers->has( $key ) ) {
            $this->recordFailure( $automation, $order, sprintf( 'No kanban automation trigger is registered under "%s".', $key ) );

            return false;
        }

        try {
            $this->triggers->get( $key )->fire( $order, $automation, (array) $automation->trigger_config );
        } catch ( Throwable $e ) {
            $this->recordFailure( $automation, $order, $e->getMessage() );

            return false;
        }

        OrderTimelineEntry::query()->create( [
            'order_id'      => $order->id,
            'actor_user_id' => null,
            'event_type'    => 'kanban.automation_fired',
            'payload'       => [ 'automation_id' => $automation->id, 'board_id' => $automation->board_id, 'trigger_key' => $key ],
        ] );

        doAction( 'ap.ecommerce.kanban.automationFired', $automation, $order );
        Event::dispatch( new KanbanAutomationTriggered( $automation, $order ) );

        return true;
    }

    /**
     * Logs a failed automation and notes it on the order timeline.
     *
     * @since 1.0.0
     *
     * @param  KanbanAutomation  $automation  Automation.
     * @param  Order             $order       Order.
     * @param  string            $error       Failure message.
     *
     * @return void
     */
    protected function recordFailure( KanbanAutomation $automation, Order $order, string $error ): void
    {
        Log::channel( 'ecommerce' )->error( 'Kanban automation failed.', [
            'automation_id' => $automation->id,
            'order_id'      => $order->id,
            'trigger_key'   => $automation->trigger_key,
            'error'         => $error,
        ] );

        OrderTimelineEntry::query()->create( [
            'order_id'      => $order->id,
            'actor_user_id' => null,
            'event_type'    => 'kanban.automation_failed',
            'payload'       => [
                'automation_id' => $automation->id,
                'board_id'      => $automation->board_id,
                'trigger_key'   => $automation->trigger_key,
                'error'         => $error,
            ],
        ] );
    }
}
