<?php

/**
 * KanbanOperationException.
 *
 * Thrown by the kanban services when a card move or board assignment is
 * refused for a reason the caller can act on: the order isn't on the
 * board, the target column is at its WIP limit, a
 * `ap.ecommerce.kanban.cardMoving` filter aborted the move, and so on.
 * The REST layer renders it as a 422 `problem+json` using
 * {@see self::$errorCode} as the problem slug.
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
class KanbanOperationException extends EcommerceException
{
    /**
     * @since 1.0.0
     *
     * @param  string  $errorCode  Machine-readable reason (`not-on-board`, `wip-limit-reached`, …).
     * @param  string  $message    Human-readable explanation.
     */
    public function __construct( public readonly string $errorCode, string $message )
    {
        parent::__construct( $message, [ 'error_code' => $errorCode ] );
    }
}
