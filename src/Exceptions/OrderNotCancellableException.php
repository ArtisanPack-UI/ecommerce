<?php

/**
 * OrderNotCancellableException.
 *
 * Thrown by {@see \ArtisanPackUI\Ecommerce\Services\OrderCancellationService::cancel()}
 * when the order's `system_status` has no edge to `cancelled` (it is
 * already cancelled, complete, or refunded), or when voiding its pending
 * payment fails.
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
class OrderNotCancellableException extends EcommerceException
{
}
