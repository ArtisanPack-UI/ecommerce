<?php

/**
 * TokenAbilities.
 *
 * The Sanctum token-ability vocabulary the engine checks (parent plan
 * §12.1, engine spec §9 auth column). Three token shapes are supported:
 *
 * - **Admin** tokens carry {@see self::ADMIN} and can reach every admin
 *   endpoint the user's Gate abilities allow.
 * - **Storefront** tokens carry {@see self::STOREFRONT} and can only reach
 *   shopper-scoped surfaces (own orders, own cart, `me`). They never unlock
 *   an admin endpoint, even for a user who is an admin.
 * - **Service** tokens carry per-resource scopes such as
 *   `ecommerce:orders.read` / `ecommerce:orders.write`, so an external
 *   integration only reaches the resources it was issued for.
 *
 * A token's abilities only ever *narrow* access: the user's Gate
 * abilities (or {@see \ArtisanPackUI\Ecommerce\Auth\EcommerceAuthorizer}'s
 * fallback) still decide whether the user may perform the action at all.
 * Cookie-session requests (Sanctum's `TransientToken`) are not narrowed.
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

use Illuminate\Support\Str;
use Laravel\Sanctum\TransientToken;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class TokenAbilities
{
    /**
     * Prefix shared by every ecommerce token ability.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const PREFIX = 'ecommerce:';

    /**
     * Full admin-API access (still bounded by the user's Gate abilities).
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const ADMIN = 'ecommerce:admin';

    /**
     * Shopper-scoped access (own orders, own cart, `me`).
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STOREFRONT = 'ecommerce:storefront';

    /**
     * Policy actions that only read data.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const READ_ACTIONS = [ 'viewAny', 'view' ];

    /**
     * The per-resource scope required for `$action` on `$resource`, e.g.
     * `( 'order', 'viewAny' )` → `ecommerce:orders.read` and
     * `( 'taxRate', 'update' )` → `ecommerce:tax-rates.write`.
     *
     * @since 1.0.0
     *
     * @param  string  $resource  Engine resource name (camelCase, singular).
     * @param  string  $action    Policy action.
     *
     * @return string
     */
    public static function forAction( string $resource, string $action ): string
    {
        return self::scope( $resource, in_array( $action, self::READ_ACTIONS, true ) ? 'read' : 'write' );
    }

    /**
     * Builds a per-resource scope, e.g. `scope( 'order', 'read' )` →
     * `ecommerce:orders.read`. Use this when issuing service tokens.
     *
     * @since 1.0.0
     *
     * @param  string  $resource  Engine resource name (camelCase, singular).
     * @param  string  $access    `read` or `write`.
     *
     * @return string
     */
    public static function scope( string $resource, string $access ): string
    {
        return self::PREFIX . Str::kebab( Str::plural( $resource ) ) . '.' . $access;
    }

    /**
     * Whether the user's current token permits `$action` on `$resource`.
     *
     * Requests without a narrowing token (cookie sessions, `actingAs()`
     * users without Sanctum, non-Sanctum guards) always pass — the Gate
     * alone decides for them.
     *
     * @since 1.0.0
     *
     * @param  object  $user      Authenticated user.
     * @param  string  $resource  Engine resource name.
     * @param  string  $action    Policy action.
     *
     * @return bool
     */
    public static function allowsAction( object $user, string $resource, string $action ): bool
    {
        if ( ! self::isNarrowed( $user ) ) {
            return true;
        }

        return $user->tokenCan( self::ADMIN ) || $user->tokenCan( self::forAction( $resource, $action ) );
    }

    /**
     * Whether the user's current token permits shopper-scoped access.
     *
     * @since 1.0.0
     *
     * @param  object  $user  Authenticated user.
     *
     * @return bool
     */
    public static function allowsStorefront( object $user ): bool
    {
        if ( ! self::isNarrowed( $user ) ) {
            return true;
        }

        return $user->tokenCan( self::STOREFRONT ) || $user->tokenCan( self::ADMIN );
    }

    /**
     * Whether the user authenticated with a token whose abilities narrow
     * what it may do: a real Sanctum personal-access token, or a
     * signed service actor.
     *
     * @since 1.0.0
     *
     * @param  object  $user  Authenticated user.
     *
     * @return bool
     */
    public static function isNarrowed( object $user ): bool
    {
        if ( $user instanceof ServiceActor ) {
            return true;
        }

        if ( ! method_exists( $user, 'currentAccessToken' ) || ! method_exists( $user, 'tokenCan' ) ) {
            return false;
        }

        $token = $user->currentAccessToken();

        return null !== $token && ! $token instanceof TransientToken;
    }
}
