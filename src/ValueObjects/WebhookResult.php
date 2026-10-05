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
 * A verified result may also carry a normalized payment `$outcome`
 * (`OUTCOME_*`) and the `$sessionReference` it is about (the
 * {@see PaymentSession::$reference}), which the engine uses to settle the
 * matching checkout without knowing the provider's event names.
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
     * The payment the session belongs to succeeded (captured or authorized).
     *
     * @since 1.0.0
     */
    public const OUTCOME_SUCCEEDED = 'succeeded';

    /**
     * The payment attempt failed.
     *
     * @since 1.0.0
     */
    public const OUTCOME_FAILED = 'failed';

    /**
     * The shopper must complete a step-up first.
     *
     * @since 1.0.0
     */
    public const OUTCOME_REQUIRES_ACTION = 'requires_action';

    /**
     * Money was refunded against the session.
     *
     * @since 1.0.0
     */
    public const OUTCOME_REFUNDED = 'refunded';

    /**
     * @since 1.0.0
     *
     * @param  bool                  $verified          Whether the request signature was cryptographically verified.
     * @param  string|null           $eventType         Provider-side event type (e.g. `payment_intent.succeeded`) when verified.
     * @param  string|null           $eventId           Provider-side event id, used for idempotent replay protection.
     * @param  array<string, mixed>  $payload           Decoded event payload — MUST NOT be trusted when `$verified` is false.
     * @param  string|null           $errorCode         Machine-readable rejection reason when `$verified` is false.
     * @param  string|null           $errorMessage      Human-readable rejection reason when `$verified` is false.
     * @param  string|null           $outcome           Normalized payment outcome (`OUTCOME_*`), when the event is about a payment.
     * @param  string|null           $sessionReference  The payment session the outcome is about.
     * @param  string|null           $refundReference   For `refunded`: the provider's refund id.
     */
    public function __construct(
        public readonly bool $verified,
        public readonly ?string $eventType = null,
        public readonly ?string $eventId = null,
        public readonly array $payload = [],
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly ?string $outcome = null,
        public readonly ?string $sessionReference = null,
        public readonly ?string $refundReference = null,
    ) {
    }

    /**
     * Convenience constructor for a verified webhook.
     *
     * @since 1.0.0
     *
     * @param  string                $eventType         Provider-side event type.
     * @param  string                $eventId           Provider-side event id.
     * @param  array<string, mixed>  $payload           Decoded event payload.
     * @param  string|null           $outcome           Normalized payment outcome (`OUTCOME_*`), if any.
     * @param  string|null           $sessionReference  The payment session the outcome is about.
     * @param  string|null           $refundReference   For `refunded`: the provider's refund id.
     *
     * @return self
     */
    public static function verified(
        string $eventType,
        string $eventId,
        array $payload = [],
        ?string $outcome = null,
        ?string $sessionReference = null,
        ?string $refundReference = null,
    ): self {
        return new self( true, $eventType, $eventId, $payload, null, null, $outcome, $sessionReference, $refundReference );
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

    /**
     * Whether the webhook reports a payment outcome the engine can act on.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function hasPaymentOutcome(): bool
    {
        return $this->verified && null !== $this->outcome && null !== $this->sessionReference && '' !== $this->sessionReference;
    }
}
