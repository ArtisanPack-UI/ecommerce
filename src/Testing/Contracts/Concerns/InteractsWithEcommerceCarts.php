<?php

/**
 * InteractsWithEcommerceCarts.
 *
 * Shared plumbing for the database-backed contract test suites: boots the
 * engine service provider against an in-memory SQLite database and builds
 * persisted carts from compact line descriptions. Satellites get this for
 * free by extending any suite that uses it.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Testing\Contracts\Concerns;

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Providers\EcommerceServiceProvider;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
trait InteractsWithEcommerceCarts
{
    /**
     * Registry-key format from engine spec §2.5: lowercase kebab-case,
     * optionally namespaced by a `publisher:` prefix.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected string $registryKeyPattern = '/^(?:[a-z0-9]+(?:-[a-z0-9]+)*:)?[a-z0-9]+(?:-[a-z0-9]+)*$/';

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
     *
     * @return void
     */
    protected function defineEnvironment( $app ): void
    {
        $app['config']->set( 'app.key', 'base64:' . base64_encode( random_bytes( 32 ) ) );
        $app['config']->set( 'database.default', 'testbench' );
        $app['config']->set( 'database.connections.testbench', [
            'driver'                  => 'sqlite',
            'database'                => ':memory:',
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ] );
    }

    /**
     * Builds a persisted cart with `items.product` loaded.
     *
     * Each line: `[ 'unit' => int, 'qty' => int, 'product' => array|Product ]`.
     *
     * @since 1.0.0
     *
     * @param  array<int, array<string, mixed>>  $lines           Line descriptions.
     * @param  string                            $currency        Cart currency.
     * @param  array<string, mixed>              $cartAttributes  Extra cart columns.
     *
     * @return Cart
     */
    protected function makePersistedCart( array $lines, string $currency = 'USD', array $cartAttributes = [] ): Cart
    {
        $cart = Cart::factory()->currency( $currency )->create( $cartAttributes );

        foreach ( $lines as $line ) {
            $product = $line['product'] ?? [];
            $product = $product instanceof Product ? $product : Product::factory()->create( $product );
            $unit    = (int) ( $line['unit'] ?? 1_000 );
            $qty     = (int) ( $line['qty'] ?? 1 );

            CartItem::factory()->create( [
                'cart_id'                => $cart->id,
                'product_id'             => $product->id,
                'quantity'               => $qty,
                'unit_price_amount'      => $unit,
                'unit_price_currency'    => $currency,
                'line_subtotal_amount'   => $unit * $qty,
                'line_subtotal_currency' => $currency,
                'line_total_amount'      => $unit * $qty,
                'line_total_currency'    => $currency,
            ] );
        }

        return $cart->load( 'items.product' );
    }

    /**
     * Snapshot of a cart + its lines as stored, for no-mutation assertions.
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart.
     *
     * @return array<string, mixed>
     */
    protected function cartSnapshot( Cart $cart ): array
    {
        $fresh = $cart->fresh( 'items' );

        return [
            'cart'  => array_diff_key( $fresh->getAttributes(), [ 'updated_at' => true ] ),
            'items' => $fresh->items->map( fn ( CartItem $item ): array => array_diff_key( $item->getAttributes(), [ 'updated_at' => true ] ) )->all(),
        ];
    }

    /**
     * Snapshot of the in-memory cart and its *loaded* lines. Compared with
     * {@see self::cartSnapshot()} this also catches implementations that
     * change `$cart->items` attributes without saving — later pipeline
     * stages would read those mutated values.
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart.
     *
     * @return array<string, mixed>
     */
    protected function memorySnapshot( Cart $cart ): array
    {
        return [
            'cart'  => $cart->getAttributes(),
            'items' => $cart->relationLoaded( 'items' )
                ? $cart->items->map( fn ( CartItem $item ): array => $item->getAttributes() )->all()
                : null,
        ];
    }

    /**
     * Asserts the cart is unchanged both in storage and in memory.
     *
     * @since 1.0.0
     *
     * @param  Cart                  $cart          Cart after the call.
     * @param  array<string, mixed>  $storedBefore  {@see self::cartSnapshot()} taken before.
     * @param  array<string, mixed>  $memoryBefore  {@see self::memorySnapshot()} taken before.
     *
     * @return void
     */
    protected function assertCartUntouched( Cart $cart, array $storedBefore, array $memoryBefore ): void
    {
        $this->assertSame( $storedBefore, $this->cartSnapshot( $cart ), 'The cart and its lines must not be changed in storage.' );
        $this->assertSame( $memoryBefore, $this->memorySnapshot( $cart ), 'The in-memory cart and its loaded lines must not be changed.' );
        $this->assertFalse( $cart->isDirty(), 'The cart must not be left with dirty attributes.' );
    }
}
