<?php

/**
 * PaymentNotAllowedException.
 *
 * Thrown by {@see \ArtisanPackUI\Ecommerce\Services\PaymentOrchestrator} when
 * the order's state does not allow a payment to be finalized (not pending, already voided, nothing to resume).
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
class PaymentNotAllowedException extends EcommerceException
{
}
