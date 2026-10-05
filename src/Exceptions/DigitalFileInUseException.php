<?php

/**
 * DigitalFileInUseException.
 *
 * Thrown by {@see \ArtisanPackUI\Ecommerce\Services\DigitalFileService::delete()}
 * when customers hold download entitlements for the file. Deleting it would
 * take away something they paid for; archive it instead.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Exceptions;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class DigitalFileInUseException extends EcommerceException
{
}
