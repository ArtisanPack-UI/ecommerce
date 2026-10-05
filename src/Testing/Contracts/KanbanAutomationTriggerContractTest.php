<?php

/**
 * KanbanAutomationTriggerContractTest.
 *
 * Abstract suite satellites extend to prove their
 * {@see \ArtisanPackUI\Ecommerce\Contracts\KanbanAutomationTrigger} honours:
 *
 * 1. **Key format.** `key()` follows engine spec §2.5; `label()` is set.
 * 2. **Fires.** A valid config produces the trigger's side effect.
 * 3. **Rejects bad config** with {@see InvalidArgumentException}.
 * 4. **Fails safely.** Garbage config either works or throws
 *    `InvalidArgumentException` — never a `TypeError` or other crash.
 *
 * Engine spec §4.13, parent plan §9.4 / §15.2.
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

use ArtisanPackUI\Ecommerce\Contracts\KanbanAutomationTrigger;
use ArtisanPackUI\Ecommerce\Models\KanbanAutomation;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Testing\Contracts\Concerns\AssertsConfigSchema;
use ArtisanPackUI\Ecommerce\Testing\Contracts\Concerns\InteractsWithKanban;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Orchestra\Testbench\TestCase;
use stdClass;

/**
 * Contract test for {@see KanbanAutomationTrigger} implementations.
 *
 * Satellites implement {@see self::trigger()}, {@see self::validConfig()},
 * {@see self::invalidConfig()}, and {@see self::assertFired()}. Fake any
 * outbound side effects (Mail, Bus, Http) in `setUp()`.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
abstract class KanbanAutomationTriggerContractTest extends TestCase
{
    use AssertsConfigSchema;
    use InteractsWithKanban;
    use RefreshDatabase;

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_key_follows_the_registry_key_format(): void
    {
        $this->assertMatchesRegularExpression( $this->registryKeyPattern, $this->trigger()->key() );
        $this->assertNotSame( '', trim( $this->trigger()->label() ) );
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
        $this->assertDeclaredConfigSchemaIsWellFormed( $this->trigger(), $this->validConfig() );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_valid_config_fires(): void
    {
        [ $order, $automation ] = $this->fixture( $this->validConfig() );

        $this->trigger()->fire( $order, $automation, $this->validConfig() );

        $this->assertFired( $order, $automation );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_invalid_config_is_rejected(): void
    {
        [ $order, $automation ] = $this->fixture( $this->invalidConfig() );

        $this->expectException( InvalidArgumentException::class );

        $this->trigger()->fire( $order, $automation, $this->invalidConfig() );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_garbage_config_fails_safely(): void
    {
        $garbage = [
            [ 'garbage' => new stdClass() ],
            array_map( static fn (): stdClass => new stdClass(), $this->validConfig() ),
            array_map( static fn (): array => [ 'nested' => [ 1, 2 ] ], $this->validConfig() ),
        ];

        foreach ( $garbage as $config ) {
            [ $order, $automation ] = $this->fixture( [] );

            try {
                $this->trigger()->fire( $order, $automation, $config );
            } catch ( InvalidArgumentException ) {
                // Rejecting bad config is the expected failure mode.
            }
        }

        $this->addToAssertionCount( 1 );
    }

    /**
     * The trigger under test.
     *
     * @since 1.0.0
     *
     * @return KanbanAutomationTrigger
     */
    abstract protected function trigger(): KanbanAutomationTrigger;

    /**
     * A config the trigger must accept.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    abstract protected function validConfig(): array;

    /**
     * A config the trigger must reject with `InvalidArgumentException`.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    abstract protected function invalidConfig(): array;

    /**
     * Asserts the side effect of firing with {@see self::validConfig()}.
     *
     * @since 1.0.0
     *
     * @param  Order             $order       Order the trigger fired for.
     * @param  KanbanAutomation  $automation  Automation row.
     *
     * @return void
     */
    abstract protected function assertFired( Order $order, KanbanAutomation $automation ): void;

    /**
     * An order on a board plus an automation on its column.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $config  Stored trigger config.
     *
     * @return array{0: Order, 1: KanbanAutomation}
     */
    protected function fixture( array $config ): array
    {
        $order  = $this->order();
        $column = $this->makeKanbanColumnFor( $order );

        return [ $order, $this->makeKanbanAutomation( $column, $this->trigger()->key(), array_filter( $config, static fn ( mixed $value ): bool => ! is_object( $value ) ) ) ];
    }

    /**
     * The order the trigger fires for.
     *
     * @since 1.0.0
     *
     * @return Order
     */
    protected function order(): Order
    {
        return $this->makeKanbanOrder();
    }
}
