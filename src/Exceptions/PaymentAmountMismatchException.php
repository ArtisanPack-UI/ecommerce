<?php

/**
 * PaymentAmountMismatchException.
 *
 * Thrown by {@see \ArtisanPackUI\Ecommerce\Services\PaymentOrchestrator} when
 * the provider session or capture does not match the order total and currency.
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
class PaymentAmountMismatchException extends EcommerceException
{
}
