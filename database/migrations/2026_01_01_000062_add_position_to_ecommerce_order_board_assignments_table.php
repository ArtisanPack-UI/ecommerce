<?php

/**
 * Adds a manual `position` to `ecommerce_order_board_assignments`.
 *
 * Cards keep the place staff drop them at, inside a column and across
 * columns (ArtisanPack-UI/ecommerce#198). Positions order the active cards
 * of one (board, sub-status) column; they are not unique, may have gaps,
 * and may be negative (a card placed at the top of a column takes the
 * smallest position minus one). Existing cards are numbered in the order
 * they reached their column.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.2.0
 */

declare( strict_types=1 );

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * @since 1.2.0
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table( 'ecommerce_order_board_assignments', function ( Blueprint $table ): void {
            $table->integer( 'position' )->default( 0 )->after( 'substatus_id' );

            $table->index( [ 'board_id', 'substatus_id', 'position' ], 'ecommerce_order_board_assignments_position_idx' );
        } );

        $this->backfill();
    }

    /**
     * @since 1.2.0
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table( 'ecommerce_order_board_assignments', function ( Blueprint $table ): void {
            $table->dropIndex( 'ecommerce_order_board_assignments_position_idx' );
            $table->dropColumn( 'position' );
        } );
    }

    /**
     * Numbers each column's cards 0, 1, 2… in the order they reached it.
     *
     * @since 1.2.0
     *
     * @return void
     */
    private function backfill(): void
    {
        $column   = null;
        $position = 0;

        DB::table( 'ecommerce_order_board_assignments' )
            ->select( [ 'id', 'board_id', 'substatus_id' ] )
            ->orderBy( 'board_id' )
            ->orderBy( 'substatus_id' )
            ->orderByRaw( 'COALESCE(moved_at, assigned_at)' )
            ->orderBy( 'id' )
            ->lazy( 500 )
            ->each( function ( object $row ) use ( &$column, &$position ): void {
                $key = $row->board_id . ':' . $row->substatus_id;

                if ( $key !== $column ) {
                    $column   = $key;
                    $position = 0;
                }

                DB::table( 'ecommerce_order_board_assignments' )->where( 'id', $row->id )->update( [ 'position' => $position++ ] );
            } );
    }
};
