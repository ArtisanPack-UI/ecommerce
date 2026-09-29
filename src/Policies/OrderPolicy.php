<?php

/**
 * OrderPolicy.
 *
 * Laravel policy for orders (engine spec §6.18 resource `order`). Every
 * method defers to {@see EcommercePolicy::decide()}, except that
 * {@see self::view()} also lets a shopper see their own order when their
 * token permits storefront access (engine spec §9.3 `GET orders/{order}`
 * is `sanctum`, not `admin`).
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

use ArtisanPackUI\Ecommerce\Auth\ServiceActor;
use ArtisanPackUI\Ecommerce\Auth\TokenAbilities;
use ArtisanPackUI\Ecommerce\Models\Order;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class OrderPolicy extends EcommercePolicy
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const RESOURCE = 'order';

    /**
     * `ecommerce.order.viewAny`.
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
     * `ecommerce.order.view`, or the order belongs to the acting shopper.
     *
     * @since 1.0.0
     *
     * @param  Authenticatable  $user     Acting user.
     * @param  Model            $subject  The Order being viewed.
     *
     * @return bool
     */
    public function view( Authenticatable $user, Model $subject ): bool
    {
        return $this->decide( $user, 'view', $subject ) || $this->owns( $user, $subject );
    }

    /**
     * `ecommerce.order.create`.
     *
     * @since 1.0.0
     *
     * @param  Authenticatable  $user  Acting user.
     *
     * @return bool
     */
    public function create( Authenticatable $user ): bool
    {
        return $this->decide( $user, 'create' );
    }

    /**
     * `ecommerce.order.update`.
     *
     * @since 1.0.0
     *
     * @param  Authenticatable  $user     Acting user.
     * @param  Model            $subject  The Order being acted on.
     *
     * @return bool
     */
    public function update( Authenticatable $user, Model $subject ): bool
    {
        return $this->decide( $user, 'update', $subject );
    }

    /**
     * `ecommerce.order.edit-fulfilled`.
     *
     * @since 1.0.0
     *
     * @param  Authenticatable  $user     Acting user.
     * @param  Model            $subject  The Order being acted on.
     *
     * @return bool
     */
    public function editFulfilled( Authenticatable $user, Model $subject ): bool
    {
        return $this->decide( $user, 'edit-fulfilled', $subject );
    }

    /**
     * `ecommerce.order.cancel`.
     *
     * @since 1.0.0
     *
     * @param  Authenticatable  $user     Acting user.
     * @param  Model            $subject  The Order being acted on.
     *
     * @return bool
     */
    public function cancel( Authenticatable $user, Model $subject ): bool
    {
        return $this->decide( $user, 'cancel', $subject );
    }

    /**
     * `ecommerce.order.refund`.
     *
     * @since 1.0.0
     *
     * @param  Authenticatable  $user     Acting user.
     * @param  Model            $subject  The Order being acted on.
     *
     * @return bool
     */
    public function refund( Authenticatable $user, Model $subject ): bool
    {
        return $this->decide( $user, 'refund', $subject );
    }

    /**
     * Whether `$order` belongs to the customer record linked to `$user`,
     * and the caller's token allows storefront access.
     *
     * @since 1.0.0
     *
     * @param  Authenticatable  $user   Acting user.
     * @param  Model            $order  Order.
     *
     * @return bool
     */
    protected function owns( Authenticatable $user, Model $order ): bool
    {
        if ( ! $order instanceof Order || $user instanceof ServiceActor || null === $order->customer_id ) {
            return false;
        }

        $customer = $order->customer;

        return null !== $customer
            && null !== $customer->user_id
            && (string) $customer->user_id === (string) $user->getAuthIdentifier()
            && TokenAbilities::allowsStorefront( $user );
    }
}
