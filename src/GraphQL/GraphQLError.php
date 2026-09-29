<?php

/**
 * GraphQLError.
 *
 * A client-safe GraphQL error with a stable machine-readable code in
 * `extensions.code` — `UNAUTHENTICATED`, `FORBIDDEN`, `NOT_FOUND`,
 * `RATE_LIMITED`, `BAD_USER_INPUT` — mirroring the REST problem types
 * (engine spec §11.5) for failures that abort a whole field. Expected,
 * recoverable mutation failures are returned in the payload's `errors`
 * list instead (engine spec §10.3).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\GraphQL;

use Exception;
use GraphQL\Error\ClientAware;
use GraphQL\Error\ProvidesExtensions;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class GraphQLError extends Exception implements ClientAware, ProvidesExtensions
{
    /**
     * @since 1.0.0
     *
     * @param  string                $message     Message.
     * @param  string                $errorCode   `extensions.code`.
     * @param  array<string, mixed>  $extensions  Extra extensions.
     */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        private readonly array $extensions = [],
    ) {
        parent::__construct( $message );
    }

    /**
     * @since 1.0.0
     *
     * @return self
     */
    public static function unauthenticated(): self
    {
        return new self( __( 'Authentication is required.' ), 'UNAUTHENTICATED' );
    }

    /**
     * @since 1.0.0
     *
     * @param  string  $ability  Missing ability.
     *
     * @return self
     */
    public static function forbidden( string $ability ): self
    {
        return new self( __( 'Missing ability :ability.', [ 'ability' => $ability ] ), 'FORBIDDEN' );
    }

    /**
     * @since 1.0.0
     *
     * @return self
     */
    public static function notFound(): self
    {
        return new self( __( 'Not found.' ), 'NOT_FOUND' );
    }

    /**
     * @since 1.0.0
     *
     * @param  int  $retryAfter  Seconds until the caller may retry.
     *
     * @return self
     */
    public static function rateLimited( int $retryAfter ): self
    {
        return new self( __( 'Too many requests.' ), 'RATE_LIMITED', [ 'retry_after' => $retryAfter ] );
    }

    /**
     * @since 1.0.0
     *
     * @return bool
     */
    public function isClientSafe(): bool
    {
        return true;
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function getExtensions(): array
    {
        return [ 'code' => $this->errorCode ] + $this->extensions;
    }
}
