<?php

/**
 * PaymentInProgressException.
 *
 * Thrown by {@see \ArtisanPackUI\Ecommerce\Services\PaymentOrchestrator} when
 * another finalize of the same order is still running (its claim lease has not run out).
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
class PaymentInProgressException extends EcommerceException
{
}
