<?php

/**
 * EcommerceAuthorizer.
 *
 * The single decision point for every engine ability (engine spec §6.18),
 * shared by the REST `ecommerce.can` middleware, the policy set under
 * `ArtisanPackUI\Ecommerce\Policies`, and the GraphQL resolvers so the
 * three surfaces can never disagree. The decision is **default-deny**:
 *
 * 1. A {@see ServiceActor} is decided by its configured abilities alone —
 *    host Gate callbacks are written for user rows, not services.
 * 2. Otherwise, if the host app defines the Gate ability
 *    `ecommerce.{resource}.{action}`, it decides.
 * 3. Otherwise, if it defines the umbrella ability `ecommerce.admin`
 *    (the `bookings.admin`-style fallback for stores without cms-framework
 *    roles), that decides.
 * 4. Otherwise the action is denied.
 *
 * The result then runs through `ap.ecommerce.abilities.{resource}.{action}`
 * so role satellites can grant or revoke without a Gate definition, and is
 * finally narrowed by the caller's token abilities ({@see TokenAbilities}):
 * a filter can't widen what a scoped token was issued for.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class EcommerceAuthorizer
{
    /**
     * Whether `$user` may perform `$action` on `$resource`.
     *
     * @since 1.0.0
     *
     * @param  Authenticatable|null  $user       Acting user (null → denied).
     * @param  string                $resource   Engine resource name (e.g. `order`).
     * @param  string                $action     Action name (e.g. `viewAny`, `edit-fulfilled`).
     * @param  mixed                 $subject    What is being acted on: a model (policies,
     *                                           GraphQL) or the request (REST middleware).
     *                                           Passed to the Gate callback and the filter.
     * @param  Request|null          $request    Current request, for the filter.
     *
     * @return bool
     */
    public function allows( ?Authenticatable $user, string $resource, string $action, mixed $subject = null, ?Request $request = null ): bool
    {
        if ( null === $user ) {
            return false;
        }

        $allowed = (bool) applyFilters(
            sprintf( 'ap.ecommerce.abilities.%s.%s', $resource, $action ),
            $this->gateAllows( $user, $resource, $action, $subject ),
            $user,
            $request ?? request(),
            $subject,
        );

        return $allowed && TokenAbilities::allowsAction( $user, $resource, $action );
    }

    /**
     * The Gate step of {@see self::allows()}, before filters and token scopes.
     *
     * @since 1.0.0
     *
     * @param  Authenticatable  $user      Acting user.
     * @param  string           $resource  Resource name.
     * @param  string           $action    Action name.
     * @param  mixed            $subject   Gate argument.
     *
     * @return bool
     */
    protected function gateAllows( Authenticatable $user, string $resource, string $action, mixed $subject ): bool
    {
        if ( $user instanceof ServiceActor ) {
            return TokenAbilities::allowsAction( $user, $resource, $action );
        }

        $ability   = sprintf( 'ecommerce.%s.%s', $resource, $action );
        $gate      = Gate::forUser( $user );
        $arguments = null === $subject ? [] : [ $subject ];

        return match ( true ) {
            Gate::has( $ability )          => $gate->allows( $ability, $arguments ),
            Gate::has( 'ecommerce.admin' ) => $gate->allows( 'ecommerce.admin' ),
            default                        => false,
        };
    }
}
