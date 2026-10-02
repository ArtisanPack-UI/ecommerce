<?php

/**
 * CustomerWriteException.
 *
 * Thrown by {@see \ArtisanPackUI\Ecommerce\Services\CustomerAddressService}
 * when an address write breaks a rule: a missing street, city, or country,
 * an unknown country code, or a value that is too long. It carries
 * field-level errors in the engine's `{ field, code, message }` shape so
 * REST and admin forms can point at the offending input.
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
class CustomerWriteException extends EcommerceException
{
    /**
     * @since 1.0.0
     *
     * @param  array<int, array{field: string|null, code: string, message: string}>  $errors  Field-level errors.
     */
    public function __construct( public readonly array $errors )
    {
        parent::__construct( (string) ( $errors[0]['message'] ?? __( 'The customer write was refused.' ) ), [ 'errors' => $errors ] );
    }

    /**
     * One error against one field.
     *
     * @since 1.0.0
     *
     * @param  string|null  $field    Offending input, or null for the whole write.
     * @param  string       $code     Machine-readable code.
     * @param  string       $message  Translated message.
     *
     * @return self
     */
    public static function field( ?string $field, string $code, string $message ): self
    {
        return new self( [ [ 'field' => $field, 'code' => $code, 'message' => $message ] ] );
    }
}
