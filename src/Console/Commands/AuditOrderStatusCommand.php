<?php

/**
 * AuditOrderStatusCommand.
 *
 * Nightly drift check between `orders.system_status` and every sub-status the
 * order carries — the global default on `orders.substatus_id`, plus any
 * per-board sub-status on `order_board_assignments` when the satellite table
 * is installed. Every mismatch is reported to the console and fired through
 * `ap.ecommerce.order.statusAuditDrift` so downstream observability (Slack,
 * logs, incidents) can pick it up. Plan §5.7, engine spec §11.6.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Console\Commands;

use ArtisanPackUI\Ecommerce\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class AuditOrderStatusCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ecommerce:audit-order-status';

    /**
     * @var string
     */
    protected $description = 'Report drift between orders.system_status and their sub-statuses (global default + board assignments).';

    /**
     * @since 1.0.0
     *
     * @return int
     */
    public function handle(): int
    {
        $globalDrift = $this->findGlobalDefaultDrift();
        $boardDrift  = $this->findBoardAssignmentDrift();
        $total       = count( $globalDrift ) + count( $boardDrift );

        foreach ( $globalDrift as $row ) {
            $this->line( sprintf(
                'Order %d: system_status="%s" but substatus %d belongs to "%s".',
                $row['order_id'],
                $row['order_system_status'],
                $row['substatus_id'],
                $row['substatus_system_status'],
            ) );

            doAction( 'ap.ecommerce.order.statusAuditDrift', $row );
        }

        foreach ( $boardDrift as $row ) {
            $this->line( sprintf(
                'Order %d on board %d: system_status="%s" but board sub-status %d belongs to "%s".',
                $row['order_id'],
                $row['board_id'],
                $row['order_system_status'],
                $row['substatus_id'],
                $row['substatus_system_status'],
            ) );

            doAction( 'ap.ecommerce.order.statusAuditDrift', $row );
        }

        if ( 0 === $total ) {
            $this->info( 'No order-status drift detected.' );

            return self::SUCCESS;
        }

        $this->warn( sprintf( 'Detected %d drifted assignment(s).', $total ) );

        return self::FAILURE;
    }

    /**
     * Returns rows where `orders.substatus_id` points at a sub-status whose
     * `system_status` disagrees with the order's own `system_status`.
     *
     * @since 1.0.0
     *
     * @return array<int, array<string, mixed>>
     */
    protected function findGlobalDefaultDrift(): array
    {
        $rows = Order::query()
            ->join(
                'order_substatuses',
                'orders.substatus_id',
                '=',
                'order_substatuses.id',
            )
            ->whereColumn( 'orders.system_status', '!=', 'order_substatuses.system_status' )
            ->select( [
                'orders.id as order_id',
                'orders.system_status as order_system_status',
                'order_substatuses.id as substatus_id',
                'order_substatuses.system_status as substatus_system_status',
            ] )
            ->get();

        $drift = [];

        foreach ( $rows as $row ) {
            $drift[] = [
                'order_id'                => (int) $row->order_id,
                'order_system_status'     => (string) $row->order_system_status,
                'substatus_id'            => (int) $row->substatus_id,
                'substatus_system_status' => (string) $row->substatus_system_status,
                'scope'                   => 'global_default',
                'board_id'                => null,
            ];
        }

        return $drift;
    }

    /**
     * Returns rows on `order_board_assignments` where the assignment's
     * sub-status disagrees with the owning order's `system_status`. Returns
     * an empty array when the boards satellite is not installed.
     *
     * @since 1.0.0
     *
     * @return array<int, array<string, mixed>>
     */
    protected function findBoardAssignmentDrift(): array
    {
        if ( ! Schema::hasTable( 'order_board_assignments' ) ) {
            return [];
        }

        $rows = DB::table( 'order_board_assignments' )
            ->join( 'orders', 'order_board_assignments.order_id', '=', 'orders.id' )
            ->join(
                'order_substatuses',
                'order_board_assignments.substatus_id',
                '=',
                'order_substatuses.id',
            )
            ->whereColumn( 'orders.system_status', '!=', 'order_substatuses.system_status' )
            ->select( [
                'orders.id as order_id',
                'orders.system_status as order_system_status',
                'order_board_assignments.board_id as board_id',
                'order_substatuses.id as substatus_id',
                'order_substatuses.system_status as substatus_system_status',
            ] )
            ->get();

        $drift = [];

        foreach ( $rows as $row ) {
            $drift[] = [
                'order_id'                => (int) $row->order_id,
                'order_system_status'     => (string) $row->order_system_status,
                'substatus_id'            => (int) $row->substatus_id,
                'substatus_system_status' => (string) $row->substatus_system_status,
                'scope'                   => 'board_assignment',
                'board_id'                => (int) $row->board_id,
            ];
        }

        return $drift;
    }
}
