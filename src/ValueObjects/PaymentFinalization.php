<?php

/**
 * PaymentFinalization value object.
 *
 * Return type of
 * {@see \ArtisanPackUI\Ecommerce\Services\PaymentOrchestrator::finalize()}.
 * Encodes which of the three terminal outcomes the authorize → assess →
 * capture sequence reached: `captured`, `challenged`, or `blocked`.
 *
 * Callers can discriminate on {@see self::$status} or use the
 * {@see self::isCaptured()}, {@see self::isChallenged()}, {@see self::isBlocked()}
 * helpers. The relevant related state — the persisted order, the payment
 * result, the fraud decision, and (on challenge) the step-up token — is
 * exposed as immutable readonly properties.
 *
 * Engine plan §8.4.
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

use ArtisanPackUI\Ecommerce\Models\Order;
use InvalidArgumentException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class PaymentFinalization
{
    public const STATUS_CAPTURED   = 'captured';
    public const STATUS_CHALLENGED = 'challenged';
    public const STATUS_BLOCKED    = 'blocked';
    public const STATUS_FAILED     = 'failed';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    private const ALLOWED_STATUSES = [
        self::STATUS_CAPTURED,
        self::STATUS_CHALLENGED,
        self::STATUS_BLOCKED,
        self::STATUS_FAILED,
    ];

    /**
     * @since 1.0.0
     *
     * @param  string              $status         Terminal outcome — one of `captured`, `challenged`, `blocked`, `failed`.
     * @param  Order               $order          The order the finalize call ran against.
     * @param  PaymentSession      $session        The authorized session the sequence produced.
     * @param  FraudDecision|null  $fraud          The fraud decision, when one was reached.
     * @param  PaymentResult|null  $payment        The capture result, when capture was attempted.
     * @param  string|null         $stepUpToken    Provider-side step-up token surfaced to the client on `challenged`.
     */
    public function __construct(
        public readonly string $status,
        public readonly Order $order,
        public readonly PaymentSession $session,
        public readonly ?FraudDecision $fraud = null,
        public readonly ?PaymentResult $payment = null,
        public readonly ?string $stepUpToken = null,
    ) {
        if ( ! in_array( $this->status, self::ALLOWED_STATUSES, true ) ) {
            throw new InvalidArgumentException( sprintf(
                'PaymentFinalization status must be one of %s; "%s" given.',
                implode( ', ', self::ALLOWED_STATUSES ),
                $this->status,
            ) );
        }
    }

    /**
     * @since 1.0.0
     *
     * @return bool
     */
    public function isCaptured(): bool
    {
        return self::STATUS_CAPTURED === $this->status;
    }

    /**
     * @since 1.0.0
     *
     * @return bool
     */
    public function isChallenged(): bool
    {
        return self::STATUS_CHALLENGED === $this->status;
    }

    /**
     * @since 1.0.0
     *
     * @return bool
     */
    public function isBlocked(): bool
    {
        return self::STATUS_BLOCKED === $this->status;
    }

    /**
     * @since 1.0.0
     *
     * @return bool
     */
    public function isFailed(): bool
    {
        return self::STATUS_FAILED === $this->status;
    }
}
