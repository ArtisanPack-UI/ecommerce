<?php

/**
 * WebhookResult value object.
 *
 * Return type of {@see \ArtisanPackUI\Ecommerce\Contracts\PaymentGateway::handleWebhook()}.
 *
 * Carries the normalized outcome of an inbound provider webhook: whether the
 * signature verified, the provider-side event type / id (for idempotency
 * lookups against the {@see \ArtisanPackUI\Ecommerce\Models\IdempotencyRecord}
 * table), the raw payload, and — on failure — the reason the webhook was
 * rejected.
 *
 * The controller layer uses `$verified` alone to decide the HTTP status:
 * an unverified webhook responds `400` regardless of what payload was
 * carried, so a spoofed request never triggers downstream events.
 *
 * Engine spec §4.2.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\ValueObjects;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class WebhookResult
{
    /**
     * @since 1.0.0
     *
     * @param  bool                  $verified      Whether the request signature was cryptographically verified.
     * @param  string|null           $eventType     Provider-side event type (e.g. `payment_intent.succeeded`) when verified.
     * @param  string|null           $eventId       Provider-side event id, used for idempotent replay protection.
     * @param  array<string, mixed>  $payload       Decoded event payload — MUST NOT be trusted when `$verified` is false.
     * @param  string|null           $errorCode     Machine-readable rejection reason when `$verified` is false.
     * @param  string|null           $errorMessage  Human-readable rejection reason when `$verified` is false.
     */
    public function __construct(
        public readonly bool $verified,
        public readonly ?string $eventType = null,
        public readonly ?string $eventId = null,
        public readonly array $payload = [],
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
    ) {
    }

    /**
     * Convenience constructor for a verified webhook.
     *
     * @since 1.0.0
     *
     * @param  string                $eventType  Provider-side event type.
     * @param  string                $eventId    Provider-side event id.
     * @param  array<string, mixed>  $payload    Decoded event payload.
     *
     * @return self
     */
    public static function verified( string $eventType, string $eventId, array $payload = [] ): self
    {
        return new self( true, $eventType, $eventId, $payload );
    }

    /**
     * Convenience constructor for a webhook whose signature failed verification.
     *
     * @since 1.0.0
     *
     * @param  string       $errorCode     Machine-readable rejection code (e.g. `signature_mismatch`).
     * @param  string|null  $errorMessage  Human-readable rejection reason.
     *
     * @return self
     */
    public static function unverified( string $errorCode = 'signature_mismatch', ?string $errorMessage = null ): self
    {
        return new self( false, null, null, [], $errorCode, $errorMessage );
    }
}
