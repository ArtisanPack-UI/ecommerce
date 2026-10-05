<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Catalog\CatalogQuery;
use ArtisanPackUI\Ecommerce\Ecommerce;
use ArtisanPackUI\Ecommerce\Facades\Ecommerce as EcommerceFacade;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductPrice;
use ArtisanPackUI\Ecommerce\Orders\OrderServices;
use ArtisanPackUI\Ecommerce\Services\CheckoutService;
use ArtisanPackUI\Ecommerce\Services\CustomerService;
use ArtisanPackUI\Ecommerce\Services\InventoryService;
use ArtisanPackUI\Ecommerce\Services\PaymentOrchestrator;
use ArtisanPackUI\Ecommerce\Services\RefundService;
use ArtisanPackUI\Ecommerce\Services\StorefrontCartService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses( RefreshDatabase::class );

it( 'hands out the main services through the helper and the facade', function (): void {
    expect( ecommerce() )->toBeInstanceOf( Ecommerce::class )
        ->and( ecommerce()->cart() )->toBeInstanceOf( StorefrontCartService::class )
        ->and( ecommerce()->checkout() )->toBeInstanceOf( CheckoutService::class )
        ->and( ecommerce()->orders() )->toBeInstanceOf( OrderServices::class )
        ->and( ecommerce()->orders()->refunds )->toBeInstanceOf( RefundService::class )
        ->and( ecommerce()->payments() )->toBeInstanceOf( PaymentOrchestrator::class )
        ->and( ecommerce()->customers() )->toBeInstanceOf( CustomerService::class )
        ->and( ecommerce()->inventory() )->toBeInstanceOf( InventoryService::class )
        ->and( EcommerceFacade::cart() )->toBeInstanceOf( StorefrontCartService::class )
        ->and( EcommerceFacade::version() )->toBeString()->not->toBe( '' );
} );

it( 'gives a fresh catalog query each time', function (): void {
    $cheap = Product::factory()->create();
    ProductPrice::factory()->forPriceable( $cheap )->create( [ 'currency' => 'USD', 'price_amount' => 500 ] );
    Product::factory()->create();

    $filtered = ecommerce()->catalog()->ids( [ $cheap->id ] );

    expect( ecommerce()->catalog() )->toBeInstanceOf( CatalogQuery::class )->not->toBe( $filtered )
        ->and( $filtered->builder()->pluck( 'id' )->all() )->toBe( [ $cheap->id ] )
        ->and( ecommerce()->catalog()->builder()->count() )->toBe( 2 );
} );

it( 'resolves services from the container so host bindings apply', function (): void {
    $bound = new CustomerService( app( ArtisanPackUI\Ecommerce\Services\ActivityLogService::class ) );

    app()->instance( CustomerService::class, $bound );

    expect( ecommerce()->customers() )->toBe( $bound );
} );
