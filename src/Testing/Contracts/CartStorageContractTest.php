<?php

/**
 * CartStorageContractTest.
 *
 * Abstract test class satellite packages extend to prove their
 * {@see \ArtisanPackUI\Ecommerce\Contracts\CartStorage} implementation
 * round-trips cart identity, ownership, meta, and item state — and that
 * {@see \ArtisanPackUI\Ecommerce\Contracts\CartStorage::delete()} truly
 * evicts the cart from the storage seam.
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

use ArtisanPackUI\Ecommerce\Contracts\CartStorage;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Providers\EcommerceServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Orchestra\Testbench\TestCase;

/**
 * Contract test for {@see CartStorage} implementations.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
abstract class CartStorageContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_find_returns_null_for_unknown_token(): void
    {
        $this->assertNull( $this->storage()->find( 'no-such-token' ) );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_find_for_customer_returns_null_when_customer_has_no_cart(): void
    {
        $this->assertNull( $this->storage()->findForCustomer( 999_999 ) );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_persist_then_find_by_token_returns_equivalent_cart(): void
    {
        $storage = $this->storage();
        $cart    = $this->makeCart( token: 'contract-token', meta: [ 'campaign' => 'contract-test' ] );

        $storage->persist( $cart );

        $found = $storage->find( 'contract-token' );

        $this->assertNotNull( $found, 'Storage must return the persisted cart by its token.' );
        $this->assertSame( 'contract-token', $found->token );
        $this->assertSame( 'USD', $found->currency );
        $this->assertSame( [ 'campaign' => 'contract-test' ], $found->meta );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_persist_round_trips_cart_items(): void
    {
        $storage = $this->storage();

        $product = Product::factory()->simple()->create();

        $cart                         = $this->makeCart( token: 'items-token' );
        $item                         = new CartItem();
        $item->product_id             = (int) $product->getKey();
        $item->quantity               = 2;
        $item->unit_price_amount      = 500;
        $item->unit_price_currency    = 'USD';
        $item->line_subtotal_amount   = 1000;
        $item->line_subtotal_currency = 'USD';
        $item->line_total_amount      = 1000;
        $item->line_total_currency    = 'USD';
        $item->options                = [ 'variant_id' => 7 ];
        $item->options_hash           = hash( 'sha256', 'variant_id=7' );

        $cart->setRelation( 'items', collect( [ $item ] ) );

        $storage->persist( $cart );

        $found = $storage->find( 'items-token' );
        $this->assertNotNull( $found );

        $items = $found->items()->get();
        $this->assertCount( 1, $items, 'Persisted cart must round-trip its items.' );
        $this->assertSame( 2, (int) $items->first()->quantity );
        $this->assertSame( [ 'variant_id' => 7 ], $items->first()->options );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_find_for_customer_returns_persisted_cart(): void
    {
        $storage = $this->storage();
        $cart    = $this->makeCart( token: 'customer-token', customerId: 42 );

        $storage->persist( $cart );

        $found = $storage->findForCustomer( 42 );

        $this->assertNotNull( $found );
        $this->assertSame( 42, (int) $found->customer_id );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_delete_evicts_cart_from_storage(): void
    {
        $storage = $this->storage();
        $cart    = $this->makeCart( token: 'delete-token' );

        $storage->persist( $cart );
        $this->assertNotNull( $storage->find( 'delete-token' ) );

        $storage->delete( $cart );

        $this->assertNull( $storage->find( 'delete-token' ), 'Cart must be gone after delete().' );
    }

    /**
     * Provides the concrete {@see CartStorage} under test.
     *
     * @since 1.0.0
     *
     * @return CartStorage
     */
    abstract protected function storage(): CartStorage;

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

    /**
     * Builds an unpersisted {@see Cart} carrying enough attributes to satisfy
     * NOT NULL constraints on the standard `carts` schema.
     *
     * @since 1.0.0
     *
     * @param  string                    $token       Opaque cart token.
     * @param  int|null                  $customerId  Optional owning customer.
     * @param  array<string, mixed>|null $meta        Cart meta payload.
     *
     * @return Cart
     */
    protected function makeCart( string $token, ?int $customerId = null, ?array $meta = null ): Cart
    {
        $cart                    = new Cart();
        $cart->token             = $token ?: (string) Str::uuid();
        $cart->customer_id       = $customerId;
        $cart->currency          = 'USD';
        $cart->subtotal_currency = 'USD';
        $cart->discount_currency = 'USD';
        $cart->tax_currency      = 'USD';
        $cart->shipping_currency = 'USD';
        $cart->total_currency    = 'USD';
        $cart->meta              = $meta ?? [];

        return $cart;
    }
}
