<?php

/**
 * CheckoutException.
 *
 * An expected, shopper-facing checkout failure — a step taken out of order,
 * a missing address, a payment that is already being processed, … . It is a
 * {@see CartOperationException}, so the REST API renders it as problem+json
 * (422 unless the failure says otherwise) and the GraphQL mutations as a
 * payload error, like every cart failure.
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
class CheckoutException extends CartOperationException
{
    /**
     * @since 1.0.0
     *
     * @param  string  $field      Input field the error relates to.
     * @param  string  $errorCode  Stable machine-readable code (e.g. `address-required`).
     * @param  string  $message    Human-readable, translatable message.
     * @param  int     $status     HTTP status for the REST API.
     */
    public function __construct(
        string $field,
        string $errorCode,
        string $message,
        protected int $status = 422,
    ) {
        parent::__construct( $field, $errorCode, $message );
    }

    /**
     * @since 1.0.0
     *
     * @return int
     */
    public function httpStatus(): int
    {
        return $this->status;
    }

    /**
     * @since 1.0.0
     *
     * @return string
     */
    public function title(): string
    {
        return __( 'Checkout failed' );
    }
}
