<?php

/**
 * CartOperationException.
 *
 * An expected, shopper-facing failure of a storefront cart operation —
 * unknown product, no price in the cart's currency, invalid coupon, … .
 * The REST controllers render it as a 422 problem+json field error and
 * the GraphQL mutations as a `UserError` in the payload's `errors` list,
 * so both surfaces report it the same way.
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
class CartOperationException extends EcommerceException
{
    /**
     * @since 1.0.0
     *
     * @param  string  $field      Input field the error relates to.
     * @param  string  $errorCode  Stable machine-readable code (e.g. `coupon-invalid`).
     * @param  string  $message    Human-readable, translatable message.
     */
    public function __construct(
        public readonly string $field,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct( $message, [ 'field' => $field, 'code' => $errorCode ] );
    }

    /**
     * HTTP status the REST API answers with.
     *
     * @since 1.0.0
     *
     * @return int
     */
    public function httpStatus(): int
    {
        return 422;
    }

    /**
     * Problem title the REST API answers with.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function title(): string
    {
        return __( 'Cart operation failed' );
    }
}
