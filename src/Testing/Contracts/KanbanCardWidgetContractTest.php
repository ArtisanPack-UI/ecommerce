<?php

/**
 * KanbanCardWidgetContractTest.
 *
 * Abstract suite satellites extend to prove their
 * {@see \ArtisanPackUI\Ecommerce\Contracts\KanbanCardWidget} honours:
 *
 * 1. **Key format.** `key()` follows engine spec §2.5; `label()` is set.
 * 2. **Payload shape.** `render()` returns string `label` + `value`, a
 *    known `tone` (if any), and string `icon` / `tooltip` / `href` (if
 *    any) — never HTML.
 * 3. **Bare orders.** Rendering an order with no lines, customer, or
 *    addresses does not throw.
 * 4. **No mutation** of the order, in storage or in memory.
 * 5. **Refresh channel.** `refreshSubscription()` is `null` or a
 *    non-empty channel name.
 *
 * Engine spec §4.12, parent plan §9.3 / §15.2.
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

use ArtisanPackUI\Ecommerce\Contracts\KanbanCardWidget;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Testing\Contracts\Concerns\InteractsWithKanban;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase;

/**
 * Contract test for {@see KanbanCardWidget} implementations.
 *
 * Satellites implement {@see self::widget()} and may override
 * {@see self::order()} to render a richer fixture.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
abstract class KanbanCardWidgetContractTest extends TestCase
{
    use InteractsWithKanban;
    use RefreshDatabase;

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    private const TONES = [ 'neutral', 'success', 'warning', 'danger', 'info' ];

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_key_follows_the_registry_key_format(): void
    {
        $this->assertMatchesRegularExpression( $this->registryKeyPattern, $this->widget()->key() );
        $this->assertNotSame( '', trim( $this->widget()->label() ) );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_render_returns_a_framework_agnostic_payload(): void
    {
        $order = $this->order();

        $this->assertValidPayload( $this->widget()->render( $order, $this->makeKanbanColumnFor( $order ) ) );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_render_handles_a_bare_order(): void
    {
        $order = $this->makeKanbanOrder( [ 'customer_id' => null, 'billing_address' => null, 'shipping_address' => null, 'shipping_method_key' => null ], 0 );

        $this->assertValidPayload( $this->widget()->render( $order, $this->makeKanbanColumnFor( $order ) ) );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_render_does_not_mutate_the_order(): void
    {
        $order  = $this->order();
        $column = $this->makeKanbanColumnFor( $order );
        $stored = array_diff_key( $order->fresh()->getAttributes(), [ 'updated_at' => true ] );
        $memory = $order->getAttributes();

        $this->widget()->render( $order, $column );

        $this->assertSame( $stored, array_diff_key( $order->fresh()->getAttributes(), [ 'updated_at' => true ] ), 'The order must not be changed in storage.' );
        $this->assertSame( $memory, $order->getAttributes(), 'The in-memory order must not be changed.' );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_refresh_subscription_is_null_or_a_channel_name(): void
    {
        $channel = $this->widget()->refreshSubscription( $this->order() );

        $this->assertTrue( null === $channel || '' !== trim( $channel ), 'refreshSubscription() must return null or a non-empty channel name.' );
    }

    /**
     * The widget under test.
     *
     * @since 1.0.0
     *
     * @return KanbanCardWidget
     */
    abstract protected function widget(): KanbanCardWidget;

    /**
     * The order rendered by the payload tests.
     *
     * @since 1.0.0
     *
     * @return Order
     */
    protected function order(): Order
    {
        return $this->makeKanbanOrder( [
            'billing_address' => [ 'first_name' => 'Ada', 'last_name' => 'Lovelace' ],
            'meta'            => [ 'tags' => [ 'rush' ] ],
        ] );
    }

    /**
     * Asserts `$payload` has the contract shape.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $payload  Render output.
     *
     * @return void
     */
    protected function assertValidPayload( array $payload ): void
    {
        $this->assertIsString( $payload['label'] ?? null, 'Payload must have a string "label".' );
        $this->assertIsString( $payload['value'] ?? null, 'Payload must have a string "value".' );

        if ( array_key_exists( 'tone', $payload ) ) {
            $this->assertContains( $payload['tone'], self::TONES, 'Payload "tone" must be a known tone.' );
        }

        foreach ( [ 'icon', 'tooltip', 'href' ] as $optional ) {
            if ( array_key_exists( $optional, $payload ) ) {
                $this->assertIsString( $payload[ $optional ], sprintf( 'Payload "%s" must be a string when present.', $optional ) );
            }
        }

        foreach ( [ 'label', 'value', 'tooltip' ] as $text ) {
            if ( isset( $payload[ $text ] ) ) {
                $this->assertSame( strip_tags( (string) $payload[ $text ] ), $payload[ $text ], sprintf( 'Payload "%s" must not contain HTML.', $text ) );
            }
        }
    }
}
