<?php

/**
 * LicenseActivated event.
 *
 * Dispatched by {@see \ArtisanPackUI\Ecommerce\Services\LicenseService::validate()}
 * when a license key is validated from a machine fingerprint it has not
 * seen before and a new activation is recorded.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Events;

use ArtisanPackUI\Ecommerce\Models\LicenseActivation;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class LicenseActivated implements ShouldDispatchAfterCommit
{
    /**
     * @since 1.0.0
     *
     * @param  LicenseActivation  $activation  The new activation.
     */
    public function __construct(
        public readonly LicenseActivation $activation,
    ) {
    }
}
