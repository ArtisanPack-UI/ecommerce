<?php

/**
 * EcommercePolicy.
 *
 * Base for the engine's Laravel policies (engine spec §6.18). Every policy
 * method defers to {@see EcommerceAuthorizer}, so `$user->can( 'refund', $order )`,
 * the REST `ecommerce.can:order,refund` middleware, and the GraphQL
 * resolvers reach the same decision: host Gate ability
 * `ecommerce.{resource}.{action}` → umbrella `ecommerce.admin` → deny,
 * then the `ap.ecommerce.abilities.{resource}.{action}` filter, then the
 * caller's token abilities.
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

use ArtisanPackUI\Ecommerce\Auth\EcommerceAuthorizer;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
abstract class EcommercePolicy
{
    /**
     * Engine resource name used in ability strings (camelCase).
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const RESOURCE = '';

    /**
     * @since 1.0.0
     *
     * @param  EcommerceAuthorizer  $authorizer  Shared ability decision.
     */
    public function __construct( protected EcommerceAuthorizer $authorizer )
    {
    }

    /**
     * Runs the shared decision for `$action` on this policy's resource.
     *
     * @since 1.0.0
     *
     * @param  Authenticatable  $user     Acting user.
     * @param  string           $action   Action name.
     * @param  mixed            $subject  Model being acted on, if any.
     *
     * @return bool
     */
    protected function decide( Authenticatable $user, string $action, mixed $subject = null ): bool
    {
        return $this->authorizer->allows( $user, static::RESOURCE, $action, $subject );
    }
}
