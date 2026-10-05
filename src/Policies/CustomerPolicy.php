<?php

/**
 * CustomerPolicy.
 *
 * Laravel policy for customers (engine spec §6.18 resource `customer`).
 * Every method defers to {@see EcommercePolicy::decide()}.
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

use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Policies\Concerns\ChecksShopperOwnership;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class CustomerPolicy extends EcommercePolicy
{
    use ChecksShopperOwnership;

    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const RESOURCE = 'customer';

    /**
     * `ecommerce.customer.viewAny`.
     *
     * @since 1.0.0
     *
     * @param  Authenticatable  $user  Acting user.
     *
     * @return bool
     */
    public function viewAny( Authenticatable $user ): bool
    {
        return $this->decide( $user, 'viewAny' );
    }

    /**
     * `ecommerce.customer.view`, or the shopper's own record.
     *
     * @since 1.0.0
     *
     * @param  Authenticatable  $user     Acting user.
     * @param  Model            $subject  The Customer being acted on.
     *
     * @return bool
     */
    public function view( Authenticatable $user, Model $subject ): bool
    {
        return $this->decide( $user, 'view', $subject ) || ( $subject instanceof Customer && $this->ownsCustomer( $user, $subject ) );
    }

    /**
     * `ecommerce.customer.update`, or the shopper's own record.
     *
     * @since 1.0.0
     *
     * @param  Authenticatable  $user     Acting user.
     * @param  Model            $subject  The Customer being acted on.
     *
     * @return bool
     */
    public function update( Authenticatable $user, Model $subject ): bool
    {
        return $this->decide( $user, 'update', $subject ) || ( $subject instanceof Customer && $this->ownsCustomer( $user, $subject ) );
    }

    /**
     * `ecommerce.customer.delete`.
     *
     * @since 1.0.0
     *
     * @param  Authenticatable  $user     Acting user.
     * @param  Model            $subject  The Customer being acted on.
     *
     * @return bool
     */
    public function delete( Authenticatable $user, Model $subject ): bool
    {
        return $this->decide( $user, 'delete', $subject );
    }
}
