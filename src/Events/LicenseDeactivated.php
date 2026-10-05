<?php

/**
 * LicenseDeactivated event.
 *
 * A machine gave up its activation of a license key, freeing the slot
 * (audit I2). Dispatched after the surrounding transaction commits.
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

use ArtisanPackUI\Ecommerce\Models\LicenseKey;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class LicenseDeactivated implements ShouldDispatchAfterCommit
{
    /**
     * @since 1.0.0
     *
     * @param  LicenseKey  $license      The license key.
     * @param  string      $fingerprint  The machine that was deactivated.
     */
    public function __construct(
        public readonly LicenseKey $license,
        public readonly string $fingerprint,
    ) {
    }
}
