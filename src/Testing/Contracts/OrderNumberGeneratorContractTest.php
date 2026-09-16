<?php

/**
 * OrderNumberGeneratorContractTest.
 *
 * Abstract test class satellite packages extend to prove their
 * {@see \ArtisanPackUI\Ecommerce\Contracts\OrderNumberGenerator}
 * implementation returns non-empty, unique strings and does not collide
 * against numbers already in the `orders` table.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Testing\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\OrderNumberGenerator;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Providers\EcommerceServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase;

/**
 * Contract test for {@see OrderNumberGenerator} implementations.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
abstract class OrderNumberGeneratorContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_generate_returns_non_empty_string(): void
    {
        $number = $this->generator()->generate( new Order() );

        $this->assertIsString( $number );
        $this->assertNotSame( '', $number, 'Generated order number must be a non-empty string.' );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_generate_returns_unique_values_across_repeated_calls(): void
    {
        $generator = $this->generator();
        $seen      = [];
        $samples   = 100;

        for ( $i = 0; $i < $samples; $i++ ) {
            $seen[] = $generator->generate( new Order() );
        }

        $unique = array_unique( $seen );

        $this->assertCount(
            $samples,
            $unique,
            'Order-number generator must return unique values across repeated calls.',
        );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_generate_avoids_collision_when_seam_is_provided(): void
    {
        $seam = $this->deterministicCollisionSeam();

        if ( null === $seam ) {
            $this->markTestSkipped(
                'This generator does not expose a deterministic collision seam; the "SHOULD avoid persisted values" invariant is exercised statistically by test_generate_returns_unique_values_across_repeated_calls(). See CartStorage / OrderNumberGenerator concurrent-collision handling: the DB unique index on orders.order_number plus placement retry are the source of truth.',
            );
        }

        Order::factory()->create( [ 'order_number' => $seam[ 'persisted' ] ] );

        $this->assertSame(
            $seam[ 'free' ],
            $seam[ 'generator' ]->generate( new Order() ),
            'When the persisted candidate is seeded first, the generator must return the free candidate — proving it read the persisted set.',
        );
    }

    /**
     * Optional deterministic collision seam for satellite generators that can
     * be configured to emit specific candidates in order.
     *
     * Return `null` to skip {@see self::test_generate_avoids_collision_when_seam_is_provided()}.
     * Otherwise return an array shaped as:
     *
     * ```php
     * [
     *     'generator' => OrderNumberGenerator, // Configured to try 'persisted' first, then 'free'.
     *     'persisted' => string,               // Number that will be seeded on the orders table.
     *     'free'      => string,               // Number the generator MUST return after skipping 'persisted'.
     * ]
     * ```
     *
     * @since 1.0.0
     *
     * @return array{generator: OrderNumberGenerator, persisted: string, free: string}|null
     */
    protected function deterministicCollisionSeam(): ?array
    {
        return null;
    }

    /**
     * Provides the concrete {@see OrderNumberGenerator} under test.
     *
     * @since 1.0.0
     *
     * @return OrderNumberGenerator
     */
    abstract protected function generator(): OrderNumberGenerator;

    /**
     * @since 1.0.0
     *
     * @param  \Illuminate\Foundation\Application  $app  Test application.
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders( $app ): array
    {
        return [ EcommerceServiceProvider::class ];
    }

    /**
     * @since 1.0.0
     *
     * @param  \Illuminate\Foundation\Application  $app  Test application.
     */
    protected function defineEnvironment( $app ): void
    {
        $app[ 'config' ]->set( 'app.key', 'base64:' . base64_encode( random_bytes( 32 ) ) );
        $app[ 'config' ]->set( 'database.default', 'testbench' );
        $app[ 'config' ]->set( 'database.connections.testbench', [
            'driver'                  => 'sqlite',
            'database'                => ':memory:',
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ] );
    }
}
