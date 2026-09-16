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
    public function test_generate_avoids_collision_with_existing_order_numbers(): void
    {
        $generator = $this->generator();
        $existing  = $generator->generate( new Order() );

        Order::factory()->create( [ 'order_number' => $existing ] );

        for ( $i = 0; $i < 25; $i++ ) {
            $this->assertNotSame(
                $existing,
                $generator->generate( new Order() ),
                'Generator must not return an order number that already exists in the orders table.',
            );
        }
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
