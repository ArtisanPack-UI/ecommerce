<?php

/**
 * ReportRangeException.
 *
 * A report range that can't be used (audit H4): the message is translated
 * and `field` names the request parameter at fault (`from`, `to`, or
 * `interval`), so the API can point at it.
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

use InvalidArgumentException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class ReportRangeException extends InvalidArgumentException
{
    /**
     * @since 1.0.0
     *
     * @param  string  $field    Parameter at fault.
     * @param  string  $message  Translated message.
     */
    public function __construct( public readonly string $field, string $message )
    {
        parent::__construct( $message );
    }
}
