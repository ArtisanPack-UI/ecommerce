<?php

/**
 * PayloadError.
 *
 * A failure a mutation reports in its payload's `errors` list (a UserError
 * with `field`, `code`, and `message`) rather than as a top-level GraphQL
 * error — e.g. a missing `Idempotency-Key` on a money-moving mutation.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\GraphQL;

use RuntimeException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class PayloadError extends RuntimeException
{
    /**
     * @since 1.0.0
     *
     * @param  string|null  $field      Input field, if any.
     * @param  string       $errorCode  Stable code.
     * @param  string       $message    Translated message.
     */
    public function __construct(
        public readonly ?string $field,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct( $message );
    }
}
