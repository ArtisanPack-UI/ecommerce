<?php

/**
 * RefundPolicy.
 *
 * Laravel policy for refunds (engine spec §6.18 resource `refund`).
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
class RefundPolicy extends EcommercePolicy
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const RESOURCE = 'refund';

    /**
     * `ecommerce.refund.view`.
     *
     * @since 1.0.0
     *
     * @param  Authenticatable  $user     Acting user.
     * @param  Model            $subject  The Refund being acted on.
     *
     * @return bool
     */
    public function view( Authenticatable $user, Model $subject ): bool
    {
        return $this->decide( $user, 'view', $subject );
    }

    /**
     * `ecommerce.refund.create`.
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
}
