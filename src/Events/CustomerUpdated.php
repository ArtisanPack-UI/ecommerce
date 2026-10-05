<?php

/**
 * CustomerUpdated event.
 *
 * A customer's profile changed (webhook `customer.updated`). Stats the engine keeps up to date (order count, total spent, last ordered) don't count as changes.
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
class CustomerUpdated implements ShouldDispatchAfterCommit
{
    /**
     * @since 1.0.0
     *
     * @param  Customer            $customer  The customer.
     * @param  array<int, string>  $changes   Names of the changed fields.
     */
    public function __construct(
        public readonly Customer $customer,
        public readonly array $changes,
    ) {
    }
}
