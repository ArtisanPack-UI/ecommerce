<?php

/**
 * InvalidOrderStatusTransitionException.
 *
 * Thrown by {@see \ArtisanPackUI\Ecommerce\Services\OrderStatusMachine::transition()}
 * when the requested `system_status` transition is not one of the transitions
 * declared allowed by the machine. Plan §5.7.
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
class InvalidOrderStatusTransitionException extends RuntimeException
{
    /**
     * The `system_status` value the order was on before the attempted move.
     *
     * @since 1.0.0
     */
    public readonly string $from;

    /**
     * The `system_status` value the caller tried to move the order to.
     *
     * @since 1.0.0
     */
    public readonly string $to;

    /**
     * @since 1.0.0
     *
     * @param  string  $from  Source `system_status` value.
     * @param  string  $to    Target `system_status` value.
     */
    public function __construct( string $from, string $to )
    {
        $this->from = $from;
        $this->to   = $to;

        parent::__construct( sprintf(
            'Illegal order system_status transition: "%s" → "%s".',
            $from,
            $to,
        ) );
    }
}
