<?php

/**
 * DigitalDownloadTokenIssued event.
 *
 * Dispatched by {@see \ArtisanPackUI\Ecommerce\Services\DigitalDownloadService::issue()}
 * after a download entitlement is created. The download carries its plain
 * token in `$plainToken` for synchronous listeners only — it is never
 * serialized onto a queue. Engine spec §7 event #32.
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

use ArtisanPackUI\Ecommerce\Models\DigitalDownload;
use ArtisanPackUI\Ecommerce\Models\OrderItem;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class DigitalDownloadTokenIssued
{
    /**
     * @since 1.0.0
     *
     * @param  DigitalDownload  $download  The new entitlement.
     * @param  OrderItem        $item      The order line it was issued for.
     */
    public function __construct(
        public readonly DigitalDownload $download,
        public readonly OrderItem $item,
    ) {
    }
}
