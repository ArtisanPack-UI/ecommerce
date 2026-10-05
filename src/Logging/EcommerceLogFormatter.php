<?php

/**
 * EcommerceLogFormatter.
 *
 * Monolog "tap" invoked by Laravel when the `ecommerce` log channel is
 * built. Swaps the default line formatter on every handler for
 * {@see EcommerceJsonFormatter} so the channel emits structured JSON
 * regardless of which underlying handler (single, daily, stderr, syslog)
 * is bound to it.
 *
 * Engine plan §16.3.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Logging;

use Illuminate\Log\Logger;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class EcommerceLogFormatter
{
    /**
     * Swaps the formatter on every handler of the supplied logger.
     *
     * @since 1.0.0
     *
     * @param  Logger  $logger  The logger being tapped.
     *
     * @return void
     */
    public function __invoke( Logger $logger ): void
    {
        $formatter = new EcommerceJsonFormatter();

        foreach ( $logger->getHandlers() as $handler ) {
            if ( method_exists( $handler, 'setFormatter' ) ) {
                $handler->setFormatter( $formatter );
            }
        }
    }
}
