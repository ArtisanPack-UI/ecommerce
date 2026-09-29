<?php

/**
 * DigitalProductUpdated event.
 *
 * Dispatched by {@see \ArtisanPackUI\Ecommerce\Services\DigitalFileService::update()}
 * when a digital file's `version` changes, so customers who bought it can
 * be told a new version is available. Engine spec §7 event #33.
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

use ArtisanPackUI\Ecommerce\Models\DigitalFile;
use ArtisanPackUI\Ecommerce\Models\Product;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class DigitalProductUpdated
{
    /**
     * @since 1.0.0
     *
     * @param  Product      $product  The product the file belongs to.
     * @param  DigitalFile  $file     The updated file.
     */
    public function __construct(
        public readonly Product $product,
        public readonly DigitalFile $file,
    ) {
    }
}
