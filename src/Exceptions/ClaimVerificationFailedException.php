<?php

/**
 * ClaimVerificationFailedException.
 *
 * Thrown when a guest-order claim's verification inputs (order number +
 * shipping postal code) do not match any order under the customer's email.
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
use RuntimeException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class ClaimVerificationFailedException extends RuntimeException
{
    /**
     * @since 1.0.0
     *
     * @param  Customer  $customer     The customer attempting the claim.
     * @param  string    $orderNumber  The order number the customer supplied.
     */
    public function __construct(
        public readonly Customer $customer,
        public readonly string $orderNumber,
    ) {
        parent::__construct(
            sprintf(
                'Claim verification failed for customer #%d and order "%s".',
                $customer->id ?? 0,
                $orderNumber,
            ),
        );
    }
}
