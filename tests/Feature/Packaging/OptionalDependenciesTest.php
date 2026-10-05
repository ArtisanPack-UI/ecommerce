<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Providers\EcommerceServiceProvider;
use ArtisanPackUI\Ecommerce\Registries\FraudProviderRegistry;
use ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry;
use ArtisanPackUI\Ecommerce\Services\Fraud\StripeRadarFraudProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Rebing\GraphQL\GraphQL;
use Stripe\StripeClient;

/*
 * stripe/stripe-php and rebing/graphql-laravel are optional (audit A6/A7).
 * The CI "optional dependencies" job removes both and runs this group; on
 * the regular stacks the "installed" cases run instead.
 */

uses( RefreshDatabase::class );

it( 'boots and serves REST without rebing/graphql-laravel', function (): void {
    expect( collect( Route::getRoutes()->getRoutes() )->contains( fn ( $route ): bool => str_starts_with( $route->uri(), 'graphql' ) ) )->toBeFalse()
        ->and( Route::has( 'ecommerce.api.products.index' ) )->toBeTrue();

    $this->getJson( '/api/ecommerce/v1/products' )->assertOk();
} )->group( 'optional-dependencies' )->skip( class_exists( GraphQL::class ), 'rebing/graphql-laravel is installed.' );

it( 'serves the ecommerce schema when rebing/graphql-laravel is installed', function (): void {
    $this->postJson( '/graphql/ecommerce', [ 'query' => '{ products { nodes { id } } }' ] )
        ->assertOk()
        ->assertJsonMissingPath( 'errors' );
} )->group( 'optional-dependencies' )->skip( ! class_exists( GraphQL::class ), 'rebing/graphql-laravel is not installed.' );

it( 'registers no Stripe gateway or Radar provider while the gateway is disabled', function (): void {
    expect( app( PaymentGatewayRegistry::class )->has( 'stripe' ) )->toBeFalse()
        ->and( app( FraudProviderRegistry::class )->has( StripeRadarFraudProvider::KEY ) )->toBeFalse();
} )->group( 'optional-dependencies' );

describe( 'with the Stripe gateway enabled', function (): void {
    beforeEach( function (): void {
        $this->logger = Mockery::spy();
        Log::shouldReceive( 'channel' )->andReturn( $this->logger );
        config()->set( 'artisanpack.ecommerce.gateways.stripe.enabled', true );

        // Re-run the boot-time registration with the flag on, into fresh registries.
        app()->forgetInstance( PaymentGatewayRegistry::class );
        app()->forgetInstance( FraudProviderRegistry::class );
        ( fn () => [ $this->registerCorePaymentGateways(), $this->registerCoreFraudProviders() ] )
            ->call( app()->getProvider( EcommerceServiceProvider::class ) );
    } );

    it( 'registers the gateway and Radar when stripe/stripe-php is installed', function (): void {
        expect( app( PaymentGatewayRegistry::class )->has( 'stripe' ) )->toBeTrue()
            ->and( app( FraudProviderRegistry::class )->has( StripeRadarFraudProvider::KEY ) )->toBeTrue();
    } )->skip( ! class_exists( StripeClient::class ), 'stripe/stripe-php is not installed.' );

    it( 'skips the gateway and logs why when stripe/stripe-php is missing', function (): void {
        expect( app( PaymentGatewayRegistry::class )->has( 'stripe' ) )->toBeFalse()
            ->and( app( FraudProviderRegistry::class )->has( StripeRadarFraudProvider::KEY ) )->toBeFalse();

        $this->logger->shouldHaveReceived( 'error' )->once()->withArgs( fn ( string $message ): bool => str_contains( $message, 'stripe/stripe-php is not installed' ) );
    } )->skip( class_exists( StripeClient::class ), 'stripe/stripe-php is installed.' );
} )->group( 'optional-dependencies' );
