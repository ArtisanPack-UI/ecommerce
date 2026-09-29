<?php

/**
 * ShippingZonePolicy.
 *
 * Laravel policy for shipping zones and their methods (engine spec §6.18 resource `shippingZone`).
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

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class ShippingZonePolicy extends EcommercePolicy
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const RESOURCE = 'shippingZone';

    /**
     * `ecommerce.shippingZone.viewAny`.
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
     * `ecommerce.shippingZone.create`.
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
     * `ecommerce.shippingZone.update`.
     *
     * @since 1.0.0
     *
     * @param  Authenticatable  $user     Acting user.
     * @param  Model            $subject  The ShippingZone being acted on.
     *
     * @return bool
     */
    public function update( Authenticatable $user, Model $subject ): bool
    {
        return $this->decide( $user, 'update', $subject );
    }

    /**
     * `ecommerce.shippingZone.delete`.
     *
     * @since 1.0.0
     *
     * @param  Authenticatable  $user     Acting user.
     * @param  Model            $subject  The ShippingZone being acted on.
     *
     * @return bool
     */
    public function delete( Authenticatable $user, Model $subject ): bool
    {
        return $this->decide( $user, 'delete', $subject );
    }
}
