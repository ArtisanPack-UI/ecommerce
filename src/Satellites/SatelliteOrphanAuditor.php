<?php

/**
 * SatelliteOrphanAuditor.
 *
 * Finds the tables and columns satellites left behind (parent plan §16.6).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Satellites;

use ArtisanPackUI\Ecommerce\Models\Satellite;
use ArtisanPackUI\Ecommerce\Registries\SatelliteRegistry;
use Illuminate\Support\Facades\Schema;

/**
 * Lists orphaned satellite schema.
 *
 * A table or column is orphaned when the satellite that declared it is
 * either marked uninstalled or no longer registers with the engine (its
 * package was removed or replaced), the object still exists in the
 * schema, and no active satellite claims it. Orphans are harmless — the
 * engine never touches unknown columns — but they are dead weight once
 * a satellite is gone for good.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class SatelliteOrphanAuditor
{
    /**
     * @since 1.0.0
     *
     * @param  SatelliteRegistry  $registry  Satellite registry.
     */
    public function __construct( private readonly SatelliteRegistry $registry )
    {
    }

    /**
     * Returns every orphaned table and column.
     *
     * @since 1.0.0
     *
     * @return array<int, array{package: string, reason: string, kind: string, table: string, column: string|null}>
     */
    public function audit(): array
    {
        [ $claimedTables, $claimedColumns ] = $this->activeClaims();

        $orphans = [];

        foreach ( Satellite::query()->orderBy( 'package_name' )->get() as $row ) {
            $reason = $this->reason( $row );

            if ( null === $reason ) {
                continue;
            }

            foreach ( (array) $row->owned_tables as $table ) {
                if ( ! in_array( $table, $claimedTables, true ) && Schema::hasTable( $table ) ) {
                    $orphans[] = [ 'package' => $row->package_name, 'reason' => $reason, 'kind' => 'table', 'table' => $table, 'column' => null ];
                }
            }

            foreach ( (array) $row->owned_columns as $table => $columns ) {
                if ( ! Schema::hasTable( (string) $table ) ) {
                    continue;
                }

                foreach ( (array) $columns as $column ) {
                    if ( ! in_array( $table . '.' . $column, $claimedColumns, true ) && Schema::hasColumn( (string) $table, (string) $column ) ) {
                        $orphans[] = [ 'package' => $row->package_name, 'reason' => $reason, 'kind' => 'column', 'table' => (string) $table, 'column' => (string) $column ];
                    }
                }
            }
        }

        return $orphans;
    }

    /**
     * Why a satellite's schema may be orphaned, or null when it is active.
     *
     * @since 1.0.0
     *
     * @param  Satellite  $row  Persisted satellite.
     *
     * @return string|null `uninstalled`, `not-registered`, or null.
     */
    protected function reason( Satellite $row ): ?string
    {
        if ( $row->isUninstalled() ) {
            return 'uninstalled';
        }

        return $this->registry->has( $row->package_name ) ? null : 'not-registered';
    }

    /**
     * Tables and `table.column` pairs claimed by active satellites.
     *
     * @since 1.0.0
     *
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    protected function activeClaims(): array
    {
        $tables  = [];
        $columns = [];

        foreach ( $this->registry->active() as $descriptor ) {
            array_push( $tables, ...$descriptor->tables );

            foreach ( $descriptor->columns as $table => $owned ) {
                foreach ( $owned as $column ) {
                    $columns[] = $table . '.' . $column;
                }
            }
        }

        return [ $tables, $columns ];
    }
}
