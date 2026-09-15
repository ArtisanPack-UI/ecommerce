<?php

/**
 * Creates the `order_substatuses` table (engine spec §3.17) and seeds the
 * default sub-status row for each system status.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create( 'order_substatuses', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->string( 'system_status', 60 );
            $table->string( 'key', 80 );
            $table->string( 'label', 120 );
            $table->char( 'color', 7 )->nullable();
            $table->string( 'icon', 80 )->nullable();
            $table->unsignedInteger( 'position' )->default( 0 );
            $table->boolean( 'is_terminal' )->default( false );
            $table->timestamps();

            $table->unique( [ 'system_status', 'key' ], 'order_substatuses_system_key_uk' );
            $table->index( 'system_status', 'order_substatuses_system_idx' );
        } );

        $this->seedDefaultSubstatuses();
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists( 'order_substatuses' );
    }

    /**
     * Seeds the default sub-status row for each system status.
     *
     * Every core system status (`pending`, `processing`, `complete`, `cancelled`,
     * `refunded`, `failed`) gets exactly one default row so a freshly-placed
     * order can always land on a non-null `substatus_id` without operator
     * configuration. Terminal statuses are marked `is_terminal = true` so the
     * multi-board completion rollup can key off the flag (§3.17).
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function seedDefaultSubstatuses(): void
    {
        $now = Carbon::now();

        DB::table( 'order_substatuses' )->insert( [
            [
                'system_status' => 'pending',
                'key'           => 'awaiting-payment',
                'label'         => 'Awaiting Payment',
                'color'         => '#F59E0B',
                'icon'          => null,
                'position'      => 0,
                'is_terminal'   => false,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            [
                'system_status' => 'processing',
                'key'           => 'in-progress',
                'label'         => 'In Progress',
                'color'         => '#3B82F6',
                'icon'          => null,
                'position'      => 0,
                'is_terminal'   => false,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            [
                'system_status' => 'complete',
                'key'           => 'completed',
                'label'         => 'Completed',
                'color'         => '#10B981',
                'icon'          => null,
                'position'      => 0,
                'is_terminal'   => true,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            [
                'system_status' => 'cancelled',
                'key'           => 'cancelled',
                'label'         => 'Cancelled',
                'color'         => '#6B7280',
                'icon'          => null,
                'position'      => 0,
                'is_terminal'   => true,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            [
                'system_status' => 'refunded',
                'key'           => 'refunded',
                'label'         => 'Refunded',
                'color'         => '#8B5CF6',
                'icon'          => null,
                'position'      => 0,
                'is_terminal'   => true,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            [
                'system_status' => 'failed',
                'key'           => 'failed',
                'label'         => 'Failed',
                'color'         => '#EF4444',
                'icon'          => null,
                'position'      => 0,
                'is_terminal'   => true,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
        ] );
    }
};
