<?php

/**
 * ServiceActor.
 *
 * The authenticated principal for a service-to-service request verified by
 * {@see \ArtisanPackUI\Ecommerce\Http\Middleware\ServiceSignatureMiddleware}
 * (engine spec §11.4). It is not a user row: its identity is the configured
 * service name and its permissions are exactly the token abilities listed
 * for that service in `artisanpack.ecommerce.api.services`.
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

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class ServiceActor implements Authenticatable
{
    /**
     * @since 1.0.0
     *
     * @param  string              $name       Service name (the signature `keyId`).
     * @param  array<int, string>  $abilities  Token abilities granted to the service.
     */
    public function __construct(
        public readonly string $name,
        public readonly array $abilities = [],
    ) {
    }

    /**
     * Whether the service was granted `$ability` (or the `*` wildcard).
     *
     * @since 1.0.0
     *
     * @param  string  $ability  Token ability.
     *
     * @return bool
     */
    public function tokenCan( string $ability ): bool
    {
        return in_array( '*', $this->abilities, true ) || in_array( $ability, $this->abilities, true );
    }

    /**
     * Service actors never carry a Sanctum token.
     *
     * @since 1.0.0
     *
     * @return null
     */
    public function currentAccessToken(): mixed
    {
        return null;
    }

    /**
     * @since 1.0.0
     *
     * @return string
     */
    public function getAuthIdentifierName(): string
    {
        return 'service';
    }

    /**
     * @since 1.0.0
     *
     * @return string
     */
    public function getAuthIdentifier(): string
    {
        return 'service:' . $this->name;
    }

    /**
     * @since 1.0.0
     *
     * @return string
     */
    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    /**
     * @since 1.0.0
     *
     * @return string
     */
    public function getAuthPassword(): string
    {
        return '';
    }

    /**
     * @since 1.0.0
     *
     * @return string|null
     */
    public function getRememberToken(): ?string
    {
        return null;
    }

    /**
     * Service actors have no remember token.
     *
     * @since 1.0.0
     *
     * @param  string  $value  Ignored.
     *
     * @return void
     */
    public function setRememberToken( $value ): void
    {
    }

    /**
     * @since 1.0.0
     *
     * @return string|null
     */
    public function getRememberTokenName(): ?string
    {
        return null;
    }
}
