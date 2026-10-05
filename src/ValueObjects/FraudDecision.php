<?php

/**
 * FraudDecision value object.
 *
 * Return type of {@see \ArtisanPackUI\Ecommerce\Contracts\FraudProvider::assess()}.
 * Carries the provider's verdict, its confidence score, the human-readable
 * reasons behind the verdict (persisted to `orders.meta.fraud_decision`
 * but never shown to customers per engine plan §8.4), and the provider-
 * side reference id for later reconciliation.
 *
 * Engine spec §4.17.
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

use InvalidArgumentException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class FraudDecision
{
    /**
     * The three verdicts a fraud provider may return.
     *
     * @since 1.0.0
     */
    public const VERDICT_APPROVE   = 'approve';
    public const VERDICT_CHALLENGE = 'challenge';
    public const VERDICT_BLOCK     = 'block';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    private const ALLOWED_VERDICTS = [
        self::VERDICT_APPROVE,
        self::VERDICT_CHALLENGE,
        self::VERDICT_BLOCK,
    ];

    /**
     * @since 1.0.0
     *
     * @param  string                $verdict            One of `approve`, `challenge`, `block`.
     * @param  int                   $score              Provider confidence score, 0..100.
     * @param  array<int, string>    $reasons            Machine-readable reason codes (persisted, never shown to customers).
     * @param  string|null           $providerReference  Provider-side identifier for later reconciliation.
     */
    public function __construct(
        public readonly string $verdict,
        public readonly int $score = 0,
        public readonly array $reasons = [],
        public readonly ?string $providerReference = null,
    ) {
        if ( ! in_array( $this->verdict, self::ALLOWED_VERDICTS, true ) ) {
            throw new InvalidArgumentException( sprintf(
                'FraudDecision verdict must be one of %s; "%s" given.',
                implode( ', ', self::ALLOWED_VERDICTS ),
                $this->verdict,
            ) );
        }

        if ( $this->score < 0 || $this->score > 100 ) {
            throw new InvalidArgumentException( sprintf(
                'FraudDecision score must be between 0 and 100; %d given.',
                $this->score,
            ) );
        }
    }

    /**
     * Convenience constructor for an approve verdict.
     *
     * @since 1.0.0
     *
     * @param  int                 $score
     * @param  array<int, string>  $reasons
     * @param  string|null         $providerReference
     *
     * @return self
     */
    public static function approve( int $score = 0, array $reasons = [], ?string $providerReference = null ): self
    {
        return new self( self::VERDICT_APPROVE, $score, $reasons, $providerReference );
    }

    /**
     * Convenience constructor for a challenge verdict (customer must step up, e.g. 3DS).
     *
     * @since 1.0.0
     *
     * @param  int                 $score
     * @param  array<int, string>  $reasons
     * @param  string|null         $providerReference
     *
     * @return self
     */
    public static function challenge( int $score = 0, array $reasons = [], ?string $providerReference = null ): self
    {
        return new self( self::VERDICT_CHALLENGE, $score, $reasons, $providerReference );
    }

    /**
     * Convenience constructor for a block verdict.
     *
     * @since 1.0.0
     *
     * @param  int                 $score
     * @param  array<int, string>  $reasons
     * @param  string|null         $providerReference
     *
     * @return self
     */
    public static function block( int $score = 0, array $reasons = [], ?string $providerReference = null ): self
    {
        return new self( self::VERDICT_BLOCK, $score, $reasons, $providerReference );
    }

    /**
     * @since 1.0.0
     *
     * @return bool
     */
    public function isApprove(): bool
    {
        return self::VERDICT_APPROVE === $this->verdict;
    }

    /**
     * @since 1.0.0
     *
     * @return bool
     */
    public function isChallenge(): bool
    {
        return self::VERDICT_CHALLENGE === $this->verdict;
    }

    /**
     * @since 1.0.0
     *
     * @return bool
     */
    public function isBlock(): bool
    {
        return self::VERDICT_BLOCK === $this->verdict;
    }

    /**
     * Persistence shape for `orders.meta.fraud_decision`.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'verdict'            => $this->verdict,
            'score'              => $this->score,
            'reasons'            => $this->reasons,
            'provider_reference' => $this->providerReference,
        ];
    }
}
