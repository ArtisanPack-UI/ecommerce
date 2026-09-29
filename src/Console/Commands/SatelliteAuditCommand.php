<?php

/**
 * SatelliteAuditCommand.
 *
 * `php artisan ecommerce:satellite:audit` — lists the satellites the
 * engine knows about and the tables / columns removed satellites left
 * behind (parent plan §16.6).
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

use ArtisanPackUI\Ecommerce\Models\Satellite;
use ArtisanPackUI\Ecommerce\Registries\SatelliteRegistry;
use ArtisanPackUI\Ecommerce\Satellites\SatelliteOrphanAuditor;
use Illuminate\Console\Command;

/**
 * Advisory orphan audit. Exits 0 unless `--fail-on-orphans` is given and
 * something is orphaned.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class SatelliteAuditCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ecommerce:satellite:audit
        {--json : Print the report as JSON.}
        {--fail-on-orphans : Exit non-zero when orphaned tables or columns exist.}';

    /**
     * @var string
     */
    protected $description = 'List ecommerce satellites and the orphaned tables/columns removed satellites left behind.';

    /**
     * @since 1.0.0
     *
     * @param  SatelliteRegistry       $registry  Satellite registry.
     * @param  SatelliteOrphanAuditor  $auditor   Orphan auditor.
     *
     * @return int
     */
    public function handle( SatelliteRegistry $registry, SatelliteOrphanAuditor $auditor ): int
    {
        $registry->sync();

        $satellites = Satellite::query()->orderBy( 'package_name' )->get()->map( fn ( Satellite $row ): array => [
            'package'  => $row->package_name,
            'version'  => $row->version,
            'status'   => $row->isUninstalled() ? 'uninstalled' : ( $registry->has( $row->package_name ) ? 'active' : 'not-registered' ),
            'verified' => null !== $row->verified_report_hash,
        ] )->all();

        $orphans = $auditor->audit();

        if ( (bool) $this->option( 'json' ) ) {
            $this->line( (string) json_encode( [ 'satellites' => $satellites, 'orphans' => $orphans ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
        } else {
            $this->render( $satellites, $orphans );
        }

        return [] !== $orphans && (bool) $this->option( 'fail-on-orphans' ) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Prints the human-readable report.
     *
     * @since 1.0.0
     *
     * @param  array<int, array<string, mixed>>  $satellites  Known satellites.
     * @param  array<int, array<string, mixed>>  $orphans     Orphaned schema.
     *
     * @return void
     */
    protected function render( array $satellites, array $orphans ): void
    {
        if ( [] === $satellites ) {
            $this->info( __( 'No satellites have registered with the engine.' ) );
        } else {
            $this->table(
                [ __( 'Package' ), __( 'Version' ), __( 'Status' ), __( 'Verified' ) ],
                array_map( static fn ( array $row ): array => [ $row['package'], $row['version'], $row['status'], $row['verified'] ? __( 'yes' ) : __( 'no' ) ], $satellites ),
            );
        }

        if ( [] === $orphans ) {
            $this->info( __( 'No orphaned satellite tables or columns found.' ) );

            return;
        }

        $this->warn( trans_choice( ':count orphaned table or column found.|:count orphaned tables or columns found.', count( $orphans ), [ 'count' => count( $orphans ) ] ) );

        $this->table(
            [ __( 'Package' ), __( 'Reason' ), __( 'Kind' ), __( 'Table' ), __( 'Column' ) ],
            array_map( static fn ( array $orphan ): array => [ $orphan['package'], $orphan['reason'], $orphan['kind'], $orphan['table'], $orphan['column'] ?? '' ], $orphans ),
        );
    }
}
