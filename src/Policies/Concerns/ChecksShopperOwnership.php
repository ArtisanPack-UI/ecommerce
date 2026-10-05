<?php

/**
 * ChecksShopperOwnership.
 *
 * Whether the acting user is the shopper a customer record belongs to, and
 * their token allows storefront access — the owner check behind a shopper
 * reading or changing their own data (#173). Service actors never own
 * customer data.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Policies\Concerns;

use ArtisanPackUI\Ecommerce\Auth\ServiceActor;
use ArtisanPackUI\Ecommerce\Auth\TokenAbilities;
use ArtisanPackUI\Ecommerce\Models\Customer;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
trait ChecksShopperOwnership
{
    /**
     * Whether `$customer` is the acting shopper's own record.
     *
     * @since 1.0.0
     *
     * @param  Authenticatable  $user      Acting user.
     * @param  Customer|null    $customer  Customer record.
     *
     * @return bool
     */
    protected function ownsCustomer( Authenticatable $user, ?Customer $customer ): bool
    {
        return ! $user instanceof ServiceActor
            && null !== $customer
            && null !== $customer->user_id
            && (string) $customer->user_id === (string) $user->getAuthIdentifier()
            && TokenAbilities::allowsStorefront( $user );
    }
}
