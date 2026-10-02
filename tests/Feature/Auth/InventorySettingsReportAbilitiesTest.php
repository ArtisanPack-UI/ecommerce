<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Auth\TokenAbilities;
use ArtisanPackUI\Ecommerce\Models\EcommerceSetting;
use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Policies\InventoryPolicy;
use ArtisanPackUI\Ecommerce\Policies\ReportPolicy;
use ArtisanPackUI\Ecommerce\Policies\SettingsPolicy;
use ArtisanPackUI\Ecommerce\Reports\Report;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Tests\Fixtures\ApiUser;

require_once __DIR__ . '/../Api/ApiTestHelpers.php';

uses( RefreshDatabase::class );

it( 'registers policies for inventory, settings, and reports', function (): void {
    expect( Gate::getPolicyFor( InventoryItem::class ) )->toBeInstanceOf( InventoryPolicy::class )
        ->and( Gate::getPolicyFor( EcommerceSetting::class ) )->toBeInstanceOf( SettingsPolicy::class )
        ->and( Gate::getPolicyFor( Report::class ) )->toBeInstanceOf( ReportPolicy::class );
} );

it( 'resolves each new ability through the specific Gate, the umbrella, then the filter', function ( string $class, string $resource, string $action ): void {
    $user = new GenericUser( [ 'id' => 1 ] );

    expect( Gate::forUser( $user )->allows( $action, $class ) )->toBeFalse();

    Gate::define( 'ecommerce.admin', fn (): bool => true );

    expect( Gate::forUser( $user )->allows( $action, $class ) )->toBeTrue();

    Gate::define( "ecommerce.{$resource}.{$action}", fn (): bool => false );

    expect( Gate::forUser( $user )->allows( $action, $class ) )->toBeFalse();

    addFilter( "ap.ecommerce.abilities.{$resource}.{$action}", fn (): bool => true );

    expect( Gate::forUser( $user )->allows( $action, $class ) )->toBeTrue();
} )->with( [
    'inventory.viewAny' => [ InventoryItem::class, 'inventory', 'viewAny' ],
    'inventory.adjust'  => [ InventoryItem::class, 'inventory', 'adjust' ],
    'settings.view'     => [ EcommerceSetting::class, 'settings', 'view' ],
    'settings.update'   => [ EcommerceSetting::class, 'settings', 'update' ],
    'report.view'       => [ Report::class, 'report', 'view' ],
] );

it( 'derives read and write token scopes for the new resources', function ( string $resource, string $action, string $scope ): void {
    expect( TokenAbilities::forAction( $resource, $action ) )->toBe( $scope );
} )->with( [
    [ 'inventory', 'viewAny', 'ecommerce:inventories.read' ],
    [ 'inventory', 'adjust', 'ecommerce:inventories.write' ],
    [ 'settings', 'view', 'ecommerce:settings.read' ],
    [ 'settings', 'update', 'ecommerce:settings.write' ],
    [ 'report', 'view', 'ecommerce:reports.read' ],
] );

it( 'lists inventory with inventory.viewAny rather than product.viewAny', function (): void {
    InventoryItem::factory()->create();
    $this->actingAs( ecommerceShopper(), 'sanctum' );

    Gate::define( 'ecommerce.product.viewAny', fn (): bool => true );
    $this->getJson( '/api/ecommerce/v1/admin/inventory' )->assertForbidden();

    Gate::define( 'ecommerce.inventory.viewAny', fn (): bool => true );
    $this->getJson( '/api/ecommerce/v1/admin/inventory' )->assertOk()->assertJsonCount( 1, 'data' );
} );

it( 'lets warehouse staff adjust stock without editing products', function (): void {
    $product = Product::factory()->create( [ 'type' => 'simple' ] );
    $this->actingAs( ecommerceShopper(), 'sanctum' );

    $this->postJson( "/api/ecommerce/v1/admin/products/{$product->id}/stock", [ 'delta' => 5, 'reason' => 'Count' ], idem() )->assertForbidden();

    Gate::define( 'ecommerce.inventory.adjust', fn (): bool => true );

    $this->postJson( "/api/ecommerce/v1/admin/products/{$product->id}/stock", [ 'delta' => 5, 'reason' => 'Count' ], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.quantity_on_hand', 5 );

    $this->patchJson( "/api/ecommerce/v1/admin/products/{$product->id}", [ 'name' => 'Renamed' ], idem() )->assertForbidden();
} );

it( 'needs inventory.adjust for a stock adjustment inside a product update', function (): void {
    $product = Product::factory()->create( [ 'type' => 'simple' ] );
    Gate::define( 'ecommerce.product.update', fn (): bool => true );

    $this->actingAs( ecommerceShopper(), 'sanctum' )
        ->patchJson( "/api/ecommerce/v1/admin/products/{$product->id}", [ 'stock_adjustment' => [ 'delta' => 3, 'reason' => 'Count' ] ], idem() )
        ->assertForbidden();

    $this->patchJson( "/api/ecommerce/v1/admin/products/{$product->id}", [ 'name' => 'Renamed' ], idem() )->assertOk();

    Gate::define( 'ecommerce.inventory.adjust', fn (): bool => true );

    $this->patchJson( "/api/ecommerce/v1/admin/products/{$product->id}", [ 'stock_adjustment' => [ 'delta' => 3, 'reason' => 'Count' ] ], idem() )->assertOk();
} );

it( 'lets an analyst token read reports and nothing else', function (): void {
    Gate::define( 'ecommerce.admin', fn (): bool => true );
    Sanctum::actingAs( ApiUser::make( 1 ), [ TokenAbilities::scope( 'report', 'read' ) ] );

    $this->getJson( '/api/ecommerce/v1/admin/reports' )->assertOk();
    $this->getJson( '/api/ecommerce/v1/admin/reports/sales' )->assertOk();
    $this->getJson( '/api/ecommerce/v1/admin/settings' )->assertForbidden();
    $this->getJson( '/api/ecommerce/v1/orders' )->assertForbidden();
} );
