<?php

/**
 * CustomerRegistered event.
 *
 * A customer record was created (webhook `customer.registered`).
 * Dispatched after the surrounding transaction commits (audit I1).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Events;

use ArtisanPackUI\Ecommerce\Models\Customer;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class CustomerRegistered implements ShouldDispatchAfterCommit
{
    /**
     * @since 1.0.0
     *
     * @param  Customer  $customer  The new customer.
     */
    public function __construct(
        public readonly Customer $customer,
    ) {
    }
}
