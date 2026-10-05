<?php

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\OrderNumberGenerator;
use ArtisanPackUI\Ecommerce\Services\RandomEightCharGenerator;
use ArtisanPackUI\Ecommerce\Testing\Contracts\OrderNumberGeneratorContractTest;
use Closure;

/**
 * Verifies the reference {@see RandomEightCharGenerator} satisfies the
 * shared {@see OrderNumberGeneratorContractTest} suite.
 *
 * @since 1.0.0
 */
final class RandomEightCharGeneratorContractTest extends OrderNumberGeneratorContractTest
{
    /**
     * @since 1.0.0
     *
     * @return OrderNumberGenerator
     */
    protected function generator(): OrderNumberGenerator
    {
        return new RandomEightCharGenerator();
    }

    /**
     * Feeds a fixed candidate stream through the generator's test-only
     * `candidateFactory` hook so the shared collision test can prove the
     * generator actually reads the `orders` table before returning.
     *
     * @since 1.0.0
     *
     * @return array{generator: OrderNumberGenerator, persisted: string, free: string}
     */
    protected function deterministicCollisionSeam(): ?array
    {
        $persisted = 'CONTRACT';
        $free      = 'FREEROLL';
        $stream    = [ $persisted, $free ];

        $factory = Closure::fromCallable( function () use ( &$stream ): string {
            return array_shift( $stream ) ?? 'FALLBACK';
        } );

        return [
            'generator' => new RandomEightCharGenerator( $factory ),
            'persisted' => $persisted,
            'free'      => $free,
        ];
    }
}
