<?php

/**
 * KanbanColumnNotEmptyException.
 *
 * Thrown by {@see \ArtisanPackUI\Ecommerce\Services\KanbanColumnService::delete()}
 * when the column still holds cards and no column to move them to was
 * named (ArtisanPack-UI/ecommerce#203). Carries the card count so a UI can
 * say how many cards are in the way.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.2.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Exceptions;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.2.0
 */
class KanbanColumnNotEmptyException extends KanbanOperationException
{
    /**
     * @since 1.2.0
     *
     * @param  int     $cardCount  Cards still in the column.
     * @param  string  $message    Human-readable explanation.
     */
    public function __construct( public readonly int $cardCount, string $message )
    {
        parent::__construct( 'column-not-empty', $message );
    }
}
