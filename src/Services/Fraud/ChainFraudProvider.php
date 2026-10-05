<?php

/**
 * ChainFraudProvider.
 *
 * Composite {@see \ArtisanPackUI\Ecommerce\Contracts\FraudProvider}
 * that runs every wrapped provider in sequence and folds the individual
 * verdicts into a single most-conservative decision:
 *
 *     block  >  challenge  >  approve
 *
 * The composite is created by
 * {@see \ArtisanPackUI\Ecommerce\Services\PaymentOrchestrator::resolveFraudProvider()}
 * when `artisanpack.ecommerce.fraud.provider` is a comma-separated list
 * of registered provider keys (engine plan §5, §6.1, §8.4). Reasons and
 * provider references from every underlying provider are preserved so
 * downstream policy can see exactly which satellite drove the verdict.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Services\Fraud;

use ArtisanPackUI\Ecommerce\Contracts\FraudProvider;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\ValueObjects\Address;
use ArtisanPackUI\Ecommerce\ValueObjects\FraudDecision;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;
use InvalidArgumentException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class ChainFraudProvider implements FraudProvider
{
    /**
     * @since 1.0.0
     */
    public const KEY = 'chain';

    /**
     * Verdict weight for folding — higher wins.
     *
     * @since 1.0.0
     *
     * @var array<string, int>
     */
    private const VERDICT_WEIGHT = [
        FraudDecision::VERDICT_APPROVE   => 0,
        FraudDecision::VERDICT_CHALLENGE => 1,
        FraudDecision::VERDICT_BLOCK     => 2,
    ];

    /**
     * @since 1.0.0
     *
     * @var array<int, FraudProvider>
     */
    private readonly array $providers;

    /**
     * @since 1.0.0
     *
     * @param  array<int, FraudProvider>  $providers  Two or more providers to compose.
     *
     * @throws InvalidArgumentException When fewer than two providers are given.
     */
    public function __construct( array $providers )
    {
        if ( count( $providers ) < 2 ) {
            throw new InvalidArgumentException(
                'ChainFraudProvider requires at least two wrapped providers; use the underlying provider directly for single-provider mode.',
            );
        }

        foreach ( $providers as $provider ) {
            if ( ! $provider instanceof FraudProvider ) {
                throw new InvalidArgumentException( sprintf(
                    'ChainFraudProvider entries must implement %s.',
                    FraudProvider::class,
                ) );
            }
        }

        $this->providers = array_values( $providers );
    }

    /**
     * @since 1.0.0
     *
     * @return string
     */
    public function key(): string
    {
        return self::KEY;
    }

    /**
     * @since 1.0.0
     *
     * @return string
     */
    public function label(): string
    {
        return __( 'Chain (most conservative wins)' );
    }

    /**
     * Runs every wrapped provider and folds the verdicts using the
     * most-conservative rule.
     *
     * @since 1.0.0
     *
     * @param  Cart            $cart
     * @param  Address         $shipping
     * @param  PaymentSession  $session
     *
     * @return FraudDecision
     */
    public function assess( Cart $cart, Address $shipping, PaymentSession $session ): FraudDecision
    {
        $winner   = null;
        $reasons  = [];
        $refs     = [];
        $maxScore = 0;

        foreach ( $this->providers as $provider ) {
            $decision = $provider->assess( $cart, $shipping, $session );

            foreach ( $decision->reasons as $reason ) {
                $reasons[] = $provider->key() . ':' . $reason;
            }

            if ( null !== $decision->providerReference ) {
                $refs[ $provider->key() ] = $decision->providerReference;
            }

            if ( $decision->score > $maxScore ) {
                $maxScore = $decision->score;
            }

            if ( null === $winner
                || self::VERDICT_WEIGHT[ $decision->verdict ] > self::VERDICT_WEIGHT[ $winner->verdict ]
            ) {
                $winner = $decision;
            }
        }

        $reference = [] === $refs
            ? null
            : implode( ',', array_map(
                static fn ( string $key, string $ref ): string => $key . '=' . $ref,
                array_keys( $refs ),
                array_values( $refs ),
            ) );

        return new FraudDecision(
            $winner->verdict,
            $maxScore,
            array_values( array_unique( $reasons ) ),
            $reference,
        );
    }
}
