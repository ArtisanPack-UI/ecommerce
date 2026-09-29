<?php

/**
 * LicenseKeyPolicy.
 *
 * Engine-resource `licenseKey` abilities (engine spec §6.18): `view`, `revoke`.
 * Each routes through {@see EcommercePolicy::decide()}.
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
class LicenseKeyPolicy extends EcommercePolicy
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const RESOURCE = 'licenseKey';

    /**
     * `ecommerce.licenseKey.view`.
     *
     * @since 1.0.0
     *
     * @param  Authenticatable  $user     Acting user.
     * @param  Model            $subject  The LicenseKey being acted on.
     *
     * @return bool
     */
    public function view( Authenticatable $user, Model $subject ): bool
    {
        return $this->decide( $user, 'view', $subject );
    }

    /**
     * `ecommerce.licenseKey.revoke`.
     *
     * @since 1.0.0
     *
     * @param  Authenticatable  $user     Acting user.
     * @param  Model            $subject  The LicenseKey being acted on.
     *
     * @return bool
     */
    public function revoke( Authenticatable $user, Model $subject ): bool
    {
        return $this->decide( $user, 'revoke', $subject );
    }
}
