<?php

/**
 * ProductWriteException.
 *
 * Thrown by the catalog write services ({@see \ArtisanPackUI\Ecommerce\Services\ProductService},
 * {@see \ArtisanPackUI\Ecommerce\Services\ProductCategoryService},
 * {@see \ArtisanPackUI\Ecommerce\Services\ProductTagService}) when a write
 * breaks a catalog rule: a taken slug or SKU, an unknown type or tax class,
 * a bundle that would contain itself, and so on. It carries field-level
 * errors in the engine's `{ field, code, message }` shape so REST, GraphQL,
 * and admin forms can all point at the offending input.
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
class ProductWriteException extends EcommerceException
{
    /**
     * @since 1.0.0
     *
     * @param  array<int, array{field: string|null, code: string, message: string}>  $errors  Field-level errors.
     */
    public function __construct( public readonly array $errors )
    {
        parent::__construct( (string) ( $errors[0]['message'] ?? 'The catalog write was refused.' ), [ 'errors' => $errors ] );
    }

    /**
     * One error on one field.
     *
     * @since 1.0.0
     *
     * @param  string|null  $field    Input field (dotted for nested rows).
     * @param  string       $code     Machine-readable code.
     * @param  string       $message  Human-readable, translated message.
     *
     * @return self
     */
    public static function field( ?string $field, string $code, string $message ): self
    {
        return new self( [ [ 'field' => $field, 'code' => $code, 'message' => $message ] ] );
    }
}
