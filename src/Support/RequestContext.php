<?php

/**
 * RequestContext.
 *
 * Process-wide holder for the current request correlation ID. Set by
 * {@see \ArtisanPackUI\Ecommerce\Http\Middleware\RequestIdMiddleware}
 * on every inbound request and read by downstream code that needs to
 * propagate the ID across process boundaries — outbound webhook
 * headers, payment-gateway idempotency keys, queued job payloads.
 *
 * Engine plan §16.3.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Support;

use Illuminate\Support\Str;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class RequestContext
{
    /**
     * The active request ID, or `null` when no request is in flight.
     *
     * @since 1.0.0
     *
     * @var  string|null
     */
    protected static ?string $requestId = null;

    /**
     * Sets the active request ID. Called by
     * {@see \ArtisanPackUI\Ecommerce\Http\Middleware\RequestIdMiddleware}.
     *
     * @since 1.0.0
     *
     * @param  string|null  $requestId  The ID to hold, or `null` to clear.
     *
     * @return void
     */
    public static function setRequestId( ?string $requestId ): void
    {
        static::$requestId = $requestId;
    }

    /**
     * Returns the active request ID, or `null` when none is set.
     *
     * @since 1.0.0
     *
     * @return string|null
     */
    public static function requestId(): ?string
    {
        return static::$requestId;
    }

    /**
     * Returns the active request ID, generating (and holding) a fresh UUID
     * when none is set. Use this from code that runs outside the HTTP
     * request lifecycle (queued jobs, scheduled commands) so an outbound
     * webhook or gateway call still carries a correlation ID.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public static function requestIdOrGenerate(): string
    {
        if ( null === static::$requestId ) {
            static::$requestId = (string) Str::uuid();
        }

        return static::$requestId;
    }

    /**
     * Clears the active request ID. Primarily intended for tests.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public static function reset(): void
    {
        static::$requestId = null;
    }
}
