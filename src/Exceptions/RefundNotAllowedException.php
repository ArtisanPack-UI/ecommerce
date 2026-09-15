<?php

/**
 * RefundNotAllowedException.
 *
 * Thrown by {@see \ArtisanPackUI\Ecommerce\Services\RefundService::issue()} when
 * the order state, request payload, or active gateway does not permit the
 * requested refund. Distinguished from a provider-declined refund (which
 * returns a {@see \ArtisanPackUI\Ecommerce\ValueObjects\RefundResult} whose
 * `$success` is false rather than throwing).
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

use RuntimeException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class RefundNotAllowedException extends RuntimeException
{
}
