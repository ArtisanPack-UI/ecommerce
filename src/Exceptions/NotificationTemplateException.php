<?php

/**
 * NotificationTemplateException.
 *
 * Thrown by {@see \ArtisanPackUI\Ecommerce\Notifications\NotificationTemplateRenderer}
 * when a template source can't be compiled or rendered: a syntax error, a
 * tag / filter / function the sandbox forbids, or a reference to a
 * variable the template doesn't declare. {@see self::$errors} carries one
 * entry per problem, shaped like the API's field errors.
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

use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class NotificationTemplateException extends EcommerceException
{
    /**
     * @since 1.0.0
     *
     * @param  array<int, array{field: string, code: string, message: string}>  $errors    Problems found.
     * @param  Throwable|null                                                    $previous  Underlying Twig error.
     */
    public function __construct( public readonly array $errors, ?Throwable $previous = null )
    {
        parent::__construct( (string) ( $errors[0]['message'] ?? __( 'Invalid notification template.' ) ), [ 'errors' => $errors ], 0, $previous );
    }
}
