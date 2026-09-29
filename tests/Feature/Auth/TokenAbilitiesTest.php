<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Auth\TokenAbilities;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\TransientToken;
use Tests\Fixtures\ApiUser;

require_once __DIR__ . '/../Api/ApiTestHelpers.php';

uses( RefreshDatabase::class );

beforeEach( function (): void {
    Gate::define( 'ecommerce.admin', fn ( $user ): bool => 1 === (int) $user->getAuthIdentifier() );
} );

it( 'derives per-resource scopes from resource and action', function ( string $resource, string $action, string $scope ): void {
    expect( TokenAbilities::forAction( $resource, $action ) )->toBe( $scope );
} )->with( [
    'order read'          => [ 'order', 'viewAny', 'ecommerce:orders.read' ],
    'order view'          => [ 'order', 'view', 'ecommerce:orders.read' ],
    'order refund'        => [ 'order', 'refund', 'ecommerce:orders.write' ],
    'tax rate update'     => [ 'taxRate', 'update', 'ecommerce:tax-rates.write' ],
    'shipping zone read'  => [ 'shippingZone', 'viewAny', 'ecommerce:shipping-zones.read' ],
    'webhook sub create'  => [ 'webhookSubscription', 'create', 'ecommerce:webhook-subscriptions.write' ],
] );

it( 'lets an admin token through admin routes', function (): void {
    Sanctum::actingAs( ApiUser::make( 1 ), [ TokenAbilities::ADMIN ] );

    $this->getJson( '/api/ecommerce/v1/orders' )->assertOk();
    $this->getJson( '/api/ecommerce/v1/customers' )->assertOk();
} );

it( 'lets a wildcard token through, as Sanctum tokens default to *', function (): void {
    Sanctum::actingAs( ApiUser::make( 1 ), [ '*' ] );

    $this->getJson( '/api/ecommerce/v1/orders' )->assertOk();
} );

it( 'keeps a storefront token out of admin routes even for an admin user', function (): void {
    Sanctum::actingAs( ApiUser::make( 1 ), [ TokenAbilities::STOREFRONT ] );

    $this->getJson( '/api/ecommerce/v1/orders' )
        ->assertForbidden()
        ->assertHeader( 'Content-Type', 'application/problem+json' );
} );

it( 'limits a service token to the scopes it was issued', function (): void {
    $order = Order::factory()->create();
    Sanctum::actingAs( ApiUser::make( 1 ), [ 'ecommerce:orders.read' ] );

    $this->getJson( '/api/ecommerce/v1/orders' )->assertOk();
    $this->getJson( "/api/ecommerce/v1/orders/{$order->id}" )->assertOk();
    $this->patchJson( "/api/ecommerce/v1/orders/{$order->id}", [ 'customer_note' => 'x' ], idem() )->assertForbidden();
    $this->getJson( '/api/ecommerce/v1/customers' )->assertForbidden();
} );

it( 'lets a write scope perform the write', function (): void {
    $order = Order::factory()->create();
    Sanctum::actingAs( ApiUser::make( 1 ), [ 'ecommerce:orders.write' ] );

    $this->patchJson( "/api/ecommerce/v1/orders/{$order->id}", [ 'customer_note' => 'Leave at door' ], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.customer_note', 'Leave at door' );
} );

it( 'never lets a token scope grant what the Gate denies', function (): void {
    Sanctum::actingAs( ApiUser::make( 2 ), [ TokenAbilities::ADMIN ] );

    $this->getJson( '/api/ecommerce/v1/orders' )->assertForbidden();
} );

it( 'does not let an ability filter widen a narrowed token', function (): void {
    addFilter( 'ap.ecommerce.abilities.order.viewAny', fn (): bool => true );
    Sanctum::actingAs( ApiUser::make( 2 ), [ TokenAbilities::STOREFRONT ] );

    $this->getJson( '/api/ecommerce/v1/orders' )->assertForbidden();
} );

it( 'does not narrow cookie-session requests', function (): void {
    $user = ApiUser::make( 1 )->withAccessToken( new TransientToken() );

    expect( TokenAbilities::isNarrowed( $user ) )->toBeFalse()
        ->and( TokenAbilities::allowsAction( $user, 'order', 'refund' ) )->toBeTrue();

    $this->actingAs( $user, 'sanctum' )->getJson( '/api/ecommerce/v1/orders' )->assertOk();
} );

it( 'allows storefront access only to storefront or admin tokens', function (): void {
    $customer = Customer::factory()->create( [ 'user_id' => 7 ] );
    $order    = Order::factory()->create( [ 'customer_id' => $customer->id ] );

    Sanctum::actingAs( $shopper = ApiUser::make( 7 ), [ TokenAbilities::STOREFRONT ] );
    expect( Gate::forUser( $shopper )->allows( 'view', $order ) )->toBeTrue();

    Sanctum::actingAs( $shopper = ApiUser::make( 7 ), [ 'ecommerce:products.read' ] );
    expect( Gate::forUser( $shopper )->allows( 'view', $order ) )->toBeFalse();
} );
