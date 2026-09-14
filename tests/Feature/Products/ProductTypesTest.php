<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductPrice;
use ArtisanPackUI\Ecommerce\ProductTypes\DigitalProductType;
use ArtisanPackUI\Ecommerce\ProductTypes\MissingProductType;
use ArtisanPackUI\Ecommerce\ProductTypes\SimpleProductType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses( RefreshDatabase::class );

describe( 'SimpleProductType', function (): void {
    it( 'declares its key, label, and behavioural flags', function (): void {
        $type = new SimpleProductType();

        expect( $type->key() )->toBe( 'simple' );
        expect( $type->label() )->toBe( 'Simple product' );
        expect( $type->requiresFulfillment() )->toBeTrue();
        expect( $type->isInventoryTracked() )->toBeTrue();
    } );

    it( 'strips arbitrary keys from cart options', function (): void {
        $product = Product::factory()->simple()->create();
        $type    = new SimpleProductType();

        $sanitized = $type->validateCartOptions( $product, [
            'variant_id'   => '42',
            'stowaway_key' => 'ignored',
        ] );

        expect( $sanitized )->toBe( [ 'variant_id' => 42 ] );
    } );

    it( 'rejects malformed variant_id values instead of silently coercing them', function ( mixed $value ): void {
        $product = Product::factory()->simple()->create();
        $type    = new SimpleProductType();

        expect( fn () => $type->validateCartOptions( $product, [ 'variant_id' => $value ] ) )
            ->toThrow( InvalidArgumentException::class );
    } )->with( [
        'zero'          => 0,
        'negative int'  => -3,
        'zero string'   => '0',
        'leading zero'  => '007',
        'trailing junk' => '42-abc',
        'decimal'       => '1.9',
        'float'         => 1.9,
        'array'         => [ [ 42 ] ],
    ] );

    it( 'accepts a null variant_id by omitting it entirely', function (): void {
        $product = Product::factory()->simple()->create();
        $type    = new SimpleProductType();

        $sanitized = $type->validateCartOptions( $product, [ 'variant_id' => null ] );

        expect( $sanitized )->toBe( [] );
    } );

    it( 'prices a line by looking up the active per-currency row and multiplying by quantity', function (): void {
        $product = Product::factory()->simple()->create();
        ProductPrice::factory()
            ->forPriceable( $product )
            ->create( [ 'currency' => 'USD', 'price_amount' => 1500 ] );

        $line = ( new SimpleProductType() )->priceLine( $product, [], 3, 'USD' );

        expect( $line->getAmount() )->toBe( '4500' );
        expect( $line->getCurrency()->getCode() )->toBe( 'USD' );
    } );

    it( 'throws when pricing in a currency with no row', function (): void {
        $product = Product::factory()->simple()->create();
        ProductPrice::factory()
            ->forPriceable( $product )
            ->create( [ 'currency' => 'USD', 'price_amount' => 1500 ] );

        expect( fn () => ( new SimpleProductType() )->priceLine( $product, [], 1, 'EUR' ) )
            ->toThrow( RuntimeException::class );
    } );

    it( 'rejects non-positive quantities at the boundary', function (): void {
        $product = Product::factory()->simple()->create();
        ProductPrice::factory()
            ->forPriceable( $product )
            ->create( [ 'currency' => 'USD', 'price_amount' => 1500 ] );

        expect( fn () => ( new SimpleProductType() )->priceLine( $product, [], 0, 'USD' ) )
            ->toThrow( InvalidArgumentException::class );
    } );

    it( 'prefers a scheduled price over the base price during the window', function (): void {
        $product = Product::factory()->simple()->create();

        // Base row.
        ProductPrice::factory()
            ->forPriceable( $product )
            ->create( [ 'currency' => 'USD', 'price_amount' => 2000 ] );

        // Scheduled sale row.
        ProductPrice::factory()
            ->forPriceable( $product )
            ->create( [
                'currency'     => 'USD',
                'price_amount' => 1500,
                'starts_at'    => now()->subDay(),
                'ends_at'      => now()->addDay(),
            ] );

        $line = ( new SimpleProductType() )->priceLine( $product, [], 1, 'USD' );

        expect( $line->getAmount() )->toBe( '1500' );
    } );
} );

describe( 'DigitalProductType', function (): void {
    it( 'declares no fulfillment and no inventory tracking', function (): void {
        $type = new DigitalProductType();

        expect( $type->key() )->toBe( 'digital' );
        expect( $type->requiresFulfillment() )->toBeFalse();
        expect( $type->isInventoryTracked() )->toBeFalse();
    } );

    it( 'keeps license_type in sanitized options', function (): void {
        $product = Product::factory()->digital()->create();
        $type    = new DigitalProductType();

        $sanitized = $type->validateCartOptions( $product, [
            'license_type' => 'personal',
            'evil_field'   => 'noop',
        ] );

        expect( $sanitized )->toBe( [ 'license_type' => 'personal' ] );
    } );

    it( 'includes the cart line options verbatim in the order snapshot', function (): void {
        // Orders/OrderItems tables are Phase 2/3; the snapshot builder is
        // exercised here in isolation.
        $cartItem          = new CartItem();
        $cartItem->options = [ 'variant_id' => 3, 'license_type' => 'personal' ];

        $snapshot = ( new DigitalProductType() )->buildOrderSnapshot( $cartItem );

        expect( $snapshot )->toMatchArray( [
            'type'    => 'digital',
            'options' => [ 'variant_id' => 3, 'license_type' => 'personal' ],
        ] );
    } );
} );

describe( 'MissingProductType', function (): void {
    it( 'throws when asked to validate cart options', function (): void {
        $product = Product::factory()->create();

        expect( fn () => ( new MissingProductType( 'gone' ) )->validateCartOptions( $product, [] ) )
            ->toThrow( RuntimeException::class );
    } );

    it( 'throws when asked to price a line', function (): void {
        $product = Product::factory()->create();

        expect( fn () => ( new MissingProductType( 'gone' ) )->priceLine( $product, [], 1, 'USD' ) )
            ->toThrow( RuntimeException::class );
    } );

    it( 'still produces a snapshot marked as missing', function (): void {
        $cartItem          = new CartItem();
        $cartItem->options = [ 'variant_id' => 4 ];

        $snapshot = ( new MissingProductType( 'gone' ) )->buildOrderSnapshot( $cartItem );

        expect( $snapshot )->toMatchArray( [
            'type'    => 'gone',
            'missing' => true,
            'options' => [ 'variant_id' => 4 ],
        ] );
    } );

    it( 'declares no fulfillment and no inventory tracking', function (): void {
        $type = new MissingProductType( 'gone' );

        expect( $type->requiresFulfillment() )->toBeFalse();
        expect( $type->isInventoryTracked() )->toBeFalse();
    } );
} );
