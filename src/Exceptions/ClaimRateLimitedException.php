<?php

/**
 * ClaimRateLimitedException.
 *
 * Thrown when a customer exceeds the guest-order claim attempt cap
 * (default: 5/hour, engine spec §3.22).
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

use ArtisanPackUI\Ecommerce\Models\Customer;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class ClaimRateLimitedException extends EcommerceException
{
    /**
     * @since 1.0.0
     *
     * @param  Customer  $customer  The customer whose attempts triggered the limit.
     * @param  int       $attempts  Attempts counted inside the window.
     * @param  int       $limit     Maximum attempts allowed in the window.
     */
    public function __construct(
        public readonly Customer $customer,
        public readonly int $attempts,
        public readonly int $limit,
    ) {
        parent::__construct(
            __( 'Customer #:customer has :attempts claim attempts within the rate window (limit: :limit).', [
                'customer' => $customer->id ?? 0,
                'attempts' => $attempts,
                'limit'    => $limit,
            ] ),
        );
    }
}
