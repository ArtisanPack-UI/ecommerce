<?php

/**
 * SettingsPolicy.
 *
 * Engine-resource `settings` abilities (engine spec §6.18, engine issue #148):
 * `view` and `update` for the store settings in
 * {@see \ArtisanPackUI\Ecommerce\Settings\SettingsRepository}. Registered for
 * {@see \ArtisanPackUI\Ecommerce\Models\EcommerceSetting}, so
 * `$user->can( 'update', EcommerceSetting::class )` works. Each method routes
 * through {@see EcommercePolicy::decide()}.
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

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class SettingsPolicy extends EcommercePolicy
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const RESOURCE = 'settings';

    /**
     * `ecommerce.settings.view`.
     *
     * @since 1.0.0
     *
     * @param  Authenticatable  $user     Acting user.
     * @param  mixed            $subject  The model acted on, if any.
     *
     * @return bool
     */
    public function view( Authenticatable $user, mixed $subject = null ): bool
    {
        return $this->decide( $user, 'view', $subject );
    }

    /**
     * `ecommerce.settings.update`.
     *
     * @since 1.0.0
     *
     * @param  Authenticatable  $user     Acting user.
     * @param  mixed            $subject  The model acted on, if any.
     *
     * @return bool
     */
    public function update( Authenticatable $user, mixed $subject = null ): bool
    {
        return $this->decide( $user, 'update', $subject );
    }
}
