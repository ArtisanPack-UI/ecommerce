<?php

/**
 * ReportRegistry.
 *
 * Registered {@see Report}s, keyed by the slug used in
 * `GET admin/reports/{report}` and the admin's `/reports/{report}` route
 * (engine issue #146). The engine registers `sales`, `top-products`,
 * `revenue-by-category`, `tax`, `inventory`, `low-stock`, and `summary`;
 * satellites (`ecommerce-reports-pro`) add their own from `boot()`:
 *
 * ```php
 * app( ReportRegistry::class )->register( 'cohorts', CohortReport::class, [ 'label' => __( 'Cohorts' ) ] );
 * ```
 *
 * Meta: `label` (translated) and `position` (sort order in an admin's
 * report list).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 *
 * @extends AbstractContractRegistry<Report>
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Registries;

use ArtisanPackUI\Ecommerce\Reports\Report;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @extends AbstractContractRegistry<Report>
 */
class ReportRegistry extends AbstractContractRegistry
{
    /**
     * Keys ordered by `position` meta, then registration order.
     *
     * @since 1.0.0
     *
     * @return array<int, string>
     */
    public function ordered(): array
    {
        $keys = $this->keys();

        usort( $keys, fn ( string $a, string $b ): int => [ (int) ( $this->meta( $a )['position'] ?? 100 ), array_search( $a, $this->keys(), true ) ] <=> [ (int) ( $this->meta( $b )['position'] ?? 100 ), array_search( $b, $this->keys(), true ) ] );

        return $keys;
    }

    /**
     * @since 1.0.0
     *
     * @return class-string<Report>
     */
    protected function contract(): string
    {
        return Report::class;
    }
}
