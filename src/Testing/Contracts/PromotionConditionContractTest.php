<?php

/**
 * PromotionConditionContractTest.
 *
 * Abstract suite satellites extend to prove their
 * {@see \ArtisanPackUI\Ecommerce\Contracts\PromotionCondition} honours:
 *
 * 1. **Key format.** `key()` follows engine spec §2.5.
 * 2. **Discriminates.** The satisfying fixture passes; the unsatisfying
 *    fixture fails.
 * 3. **Never throws** on an empty cart or malformed config.
 * 4. **No mutation** of the cart or its lines.
 *
 * Engine spec §4.10, parent plan §5.9 / §15.2.
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

use ArtisanPackUI\Ecommerce\Contracts\PromotionCondition;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Testing\Contracts\Concerns\AssertsConfigSchema;
use ArtisanPackUI\Ecommerce\Testing\Contracts\Concerns\InteractsWithEcommerceCarts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase;
use stdClass;

/**
 * Contract test for {@see PromotionCondition} implementations.
 *
 * Satellites implement {@see self::condition()},
 * {@see self::satisfiedCase()}, and {@see self::unsatisfiedCase()}.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
abstract class PromotionConditionContractTest extends TestCase
{
    use AssertsConfigSchema;
    use InteractsWithEcommerceCarts;
    use RefreshDatabase;

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_key_follows_the_registry_key_format(): void
    {
        $this->assertMatchesRegularExpression( $this->registryKeyPattern, $this->condition()->key() );
        $this->assertNotSame( '', trim( $this->condition()->label() ) );
    }

    /**
     * A declared config schema is well-formed and accepts the suite's config fixture (engine issue #149).
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_declared_config_schema_is_well_formed(): void
    {
        [ , $config ] = $this->satisfiedCase();

        $this->assertDeclaredConfigSchemaIsWellFormed( $this->condition(), $config );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_satisfying_fixture_passes_and_unsatisfying_fixture_fails(): void
    {
        [ $cart, $config ] = $this->satisfiedCase();
        $this->assertTrue( $this->condition()->evaluate( $cart, $config ), 'Satisfying fixture must pass.' );

        [ $cart, $config ] = $this->unsatisfiedCase();
        $this->assertFalse( $this->condition()->evaluate( $cart, $config ), 'Unsatisfying fixture must fail.' );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_empty_cart_and_malformed_config_never_throw(): void
    {
        [ , $config ] = $this->satisfiedCase();
        $empty        = $this->makePersistedCart( [] );

        foreach ( [ $config, [], [ 'garbage' => new stdClass() ], array_map( static fn (): string => 'not-a-value', $config ) ] as $candidate ) {
            $this->assertIsBool( $this->condition()->evaluate( $empty, $candidate ) );
            $this->assertIsBool( $this->condition()->evaluate( $this->satisfiedCase()[0], $candidate ) );
        }
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_evaluation_does_not_mutate_the_cart(): void
    {
        [ $cart, $config ] = $this->satisfiedCase();
        $stored            = $this->cartSnapshot( $cart );
        $memory            = $this->memorySnapshot( $cart );

        $this->condition()->evaluate( $cart, $config );

        $this->assertCartUntouched( $cart, $stored, $memory );
    }

    /**
     * The condition under test.
     *
     * @since 1.0.0
     *
     * @return PromotionCondition
     */
    abstract protected function condition(): PromotionCondition;

    /**
     * A cart + config the condition must accept.
     *
     * @since 1.0.0
     *
     * @return array{0: Cart, 1: array<string, mixed>}
     */
    abstract protected function satisfiedCase(): array;

    /**
     * A cart + config the condition must reject.
     *
     * @since 1.0.0
     *
     * @return array{0: Cart, 1: array<string, mixed>}
     */
    abstract protected function unsatisfiedCase(): array;
}
