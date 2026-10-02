<?php

/**
 * ReportPolicy.
 *
 * Engine-resource `report` ability (engine spec §6.18, engine issue #148):
 * `view` for every report in {@see \ArtisanPackUI\Ecommerce\Registries\ReportRegistry}.
 * Registered for {@see \ArtisanPackUI\Ecommerce\Reports\Report}, so
 * `$user->can( 'view', Report::class )` works. Routes through
 * {@see EcommercePolicy::decide()}.
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
class ReportPolicy extends EcommercePolicy
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const RESOURCE = 'report';

    /**
     * `ecommerce.report.view`.
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
}
