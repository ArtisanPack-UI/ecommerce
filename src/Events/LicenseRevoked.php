<?php

/**
 * LicenseRevoked event.
 *
 * Dispatched by {@see \ArtisanPackUI\Ecommerce\Services\LicenseService::revoke()}.
 * Engine spec §7 event #35.
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
class LicenseRevoked implements ShouldDispatchAfterCommit
{
    /**
     * @since 1.0.0
     *
     * @param  LicenseKey  $key     The revoked key.
     * @param  ?string     $reason  Why it was revoked.
     */
    public function __construct(
        public readonly LicenseKey $key,
        public readonly ?string $reason,
    ) {
    }
}
