<?php

/**
 * CustomerAddressPolicy.
 *
 * Laravel policy for customer addresses: staff with the matching
 * `ecommerce.customer.*` ability, or the shopper the address belongs to
 * (#173).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Policies;

use ArtisanPackUI\Ecommerce\Models\CustomerAddress;
use ArtisanPackUI\Ecommerce\Policies\Concerns\ChecksShopperOwnership;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class CustomerAddressPolicy extends EcommercePolicy
{
    use ChecksShopperOwnership;

    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const RESOURCE = 'customer';

    /**
     * `ecommerce.customer.view`, or the shopper's own address.
     *
     * @since 1.0.0
     *
     * @param  Authenticatable  $user     Acting user.
     * @param  Model            $subject  The CustomerAddress.
     *
     * @return bool
     */
    public function view( Authenticatable $user, Model $subject ): bool
    {
        return $this->decide( $user, 'view', $subject ) || $this->ownsAddress( $user, $subject );
    }

    /**
     * `ecommerce.customer.update`, or the shopper's own address.
     *
     * @since 1.0.0
     *
     * @param  Authenticatable  $user     Acting user.
     * @param  Model            $subject  The CustomerAddress.
     *
     * @return bool
     */
    public function update( Authenticatable $user, Model $subject ): bool
    {
        return $this->decide( $user, 'update', $subject ) || $this->ownsAddress( $user, $subject );
    }

    /**
     * `ecommerce.customer.update`, or the shopper's own address.
     *
     * @since 1.0.0
     *
     * @param  Authenticatable  $user     Acting user.
     * @param  Model            $subject  The CustomerAddress.
     *
     * @return bool
     */
    public function delete( Authenticatable $user, Model $subject ): bool
    {
        return $this->decide( $user, 'update', $subject ) || $this->ownsAddress( $user, $subject );
    }

    /**
     * @since 1.0.0
     *
     * @param  Authenticatable  $user     Acting user.
     * @param  Model            $subject  Address.
     *
     * @return bool
     */
    protected function ownsAddress( Authenticatable $user, Model $subject ): bool
    {
        return $subject instanceof CustomerAddress && $this->ownsCustomer( $user, $subject->customer );
    }
}
