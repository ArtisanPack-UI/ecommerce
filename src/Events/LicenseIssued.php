<?php

/**
 * LicenseIssued event.
 *
 * Dispatched by {@see \ArtisanPackUI\Ecommerce\Services\LicenseService::issue()}
 * after a license key is created. Engine spec §7 event #34.
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
use ArtisanPackUI\Ecommerce\Models\OrderItem;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class LicenseIssued
{
    /**
     * @since 1.0.0
     *
     * @param  LicenseKey  $key   The new key.
     * @param  OrderItem   $item  The order line it was issued for.
     */
    public function __construct(
        public readonly LicenseKey $key,
        public readonly OrderItem $item,
    ) {
    }
}
