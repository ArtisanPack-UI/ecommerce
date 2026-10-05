<?php

/**
 * IdempotencyConflictException.
 *
 * Thrown by {@see \ArtisanPackUI\Ecommerce\Support\IdempotentAction} when a
 * key is reused for a different request, or a call with the same key is
 * still running.
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
class IdempotencyConflictException extends EcommerceException
{
}
