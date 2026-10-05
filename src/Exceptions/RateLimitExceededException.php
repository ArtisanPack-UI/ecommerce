<?php

/**
 * RateLimitExceededException.
 *
 * Thrown by {@see \ArtisanPackUI\Ecommerce\RateLimiting\EcommerceRateLimiter::attempt()}
 * when an in-process call is over one of a policy's limits. `$retryAfter`
 * is the number of seconds until it may be tried again.
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
class RateLimitExceededException extends EcommerceException
{
    /**
     * @since 1.0.0
     *
     * @param  string  $policy      Policy that refused.
     * @param  int     $retryAfter  Seconds until it may be retried.
     */
    public function __construct( public readonly string $policy, public readonly int $retryAfter )
    {
        parent::__construct(
            __( 'Too many attempts. Please try again in :seconds seconds.', [ 'seconds' => $retryAfter ] ),
            [ 'policy' => $policy, 'retry_after' => $retryAfter ],
        );
    }
}
