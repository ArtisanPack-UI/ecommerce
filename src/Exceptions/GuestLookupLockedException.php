<?php

/**
 * GuestLookupLockedException.
 *
 * Too many failed guest order lookups for this order number or from this
 * address (#175); try again after `retryAfter` seconds.
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
class GuestLookupLockedException extends EcommerceException
{
    /**
     * @since 1.0.0
     *
     * @param  int  $retryAfter  Seconds until another attempt is allowed.
     */
    public function __construct( public readonly int $retryAfter )
    {
        parent::__construct( __( 'Too many order lookups. Try again later.' ) );
    }
}
