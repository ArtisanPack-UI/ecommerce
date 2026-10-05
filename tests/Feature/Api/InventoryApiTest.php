<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Http\Middleware\IdempotencyMiddleware;
use ArtisanPackUI\Ecommerce\Models\ActivityLogEntry;
use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

require_once __DIR__ . '/ApiTestHelpers.php';

uses( RefreshDatabase::class );

it( 'updates an inventory row\'s stock settings', function (): void {
    $item = InventoryItem::factory()->create( [ 'quantity_on_hand' => 7 ] );

    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->patchJson( "/api/ecommerce/v1/admin/inventory/{$item->id}", [
            'track_inventory'     => false,
            'allow_backorder'     => true,
            'low_stock_threshold' => 3,
            'quantity_on_hand'    => 999,
        ], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.type', 'inventoryItem' )
        ->assertJsonPath( 'data.track_inventory', false )
        ->assertJsonPath( 'data.allow_backorder', true )
        ->assertJsonPath( 'data.low_stock_threshold', 3 )
        ->assertJsonPath( 'data.quantity_on_hand', 7 );

    $this->patchJson( "/api/ecommerce/v1/admin/inventory/{$item->id}", [ 'low_stock_threshold' => null ], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.low_stock_threshold', null )
        ->assertJsonPath( 'data.allow_backorder', true );
} );

it( 'rejects invalid stock settings', function (): void {
    $item = InventoryItem::factory()->create();

    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->patchJson( "/api/ecommerce/v1/admin/inventory/{$item->id}", [ 'low_stock_threshold' => -1, 'track_inventory' => 'sometimes' ], idem() )
        ->assertStatus( 422 )
        ->assertJsonPath( 'type', fn ( string $type ): bool => str_ends_with( $type, 'validation-failed' ) )
        ->assertJsonFragment( [ 'field' => 'low_stock_threshold' ] )
        ->assertJsonFragment( [ 'field' => 'track_inventory' ] );
} );

it( 'adjusts on-hand stock with a reason and records it in the activity log', function (): void {
    $item = InventoryItem::factory()->create( [ 'quantity_on_hand' => 10 ] );

    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->postJson( "/api/ecommerce/v1/admin/inventory/{$item->id}/adjust", [ 'delta' => -4, 'reason' => '  Recount  ' ], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.quantity_on_hand', 6 );

    $entry = ActivityLogEntry::query()->where( 'event_type', 'inventory.adjusted' )->latest( 'id' )->first();

    expect( $entry )->not->toBeNull()
        ->and( $entry->payload['reason'] ?? null )->toBe( 'Recount' )
        ->and( $entry->payload['delta'] ?? null )->toBe( -4 );
} );

it( 'requires a non-zero delta and a reason to adjust', function ( array $payload, string $field ): void {
    $item = InventoryItem::factory()->create( [ 'quantity_on_hand' => 10 ] );

    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->postJson( "/api/ecommerce/v1/admin/inventory/{$item->id}/adjust", $payload, idem() )
        ->assertStatus( 422 )
        ->assertJsonFragment( [ 'field' => $field ] );

    expect( $item->fresh()->quantity_on_hand )->toBe( 10 );
} )->with( [
    'zero delta'     => [ [ 'delta' => 0, 'reason' => 'Count' ], 'delta' ],
    'missing delta'  => [ [ 'reason' => 'Count' ], 'delta' ],
    'missing reason' => [ [ 'delta' => 2 ], 'reason' ],
    'blank reason'   => [ [ 'delta' => 2, 'reason' => '   ' ], 'reason' ],
] );

it( 'replays an adjustment retried with the same Idempotency-Key instead of applying it twice', function (): void {
    $item    = InventoryItem::factory()->create( [ 'quantity_on_hand' => 10 ] );
    $headers = idem();

    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $first = $this->postJson( "/api/ecommerce/v1/admin/inventory/{$item->id}/adjust", [ 'delta' => 5, 'reason' => 'Intake' ], $headers )->assertOk();

    $this->postJson( "/api/ecommerce/v1/admin/inventory/{$item->id}/adjust", [ 'delta' => 5, 'reason' => 'Intake' ], $headers )
        ->assertOk()
        ->assertHeader( IdempotencyMiddleware::REPLAY_HEADER )
        ->assertExactJson( $first->json() );

    expect( $item->fresh()->quantity_on_hand )->toBe( 15 );
} );

it( 'requires an Idempotency-Key on both writes', function (): void {
    $item = InventoryItem::factory()->create();

    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $this->patchJson( "/api/ecommerce/v1/admin/inventory/{$item->id}", [ 'allow_backorder' => true ], [ 'Accept' => 'application/json' ] )->assertStatus( 400 );
    $this->postJson( "/api/ecommerce/v1/admin/inventory/{$item->id}/adjust", [ 'delta' => 1, 'reason' => 'x' ], [ 'Accept' => 'application/json' ] )->assertStatus( 400 );
} );

it( 'gates both writes on inventory.adjust rather than product.update', function (): void {
    $item = InventoryItem::factory()->create( [ 'quantity_on_hand' => 10 ] );

    $this->actingAs( ecommerceShopper(), 'sanctum' );

    Gate::define( 'ecommerce.product.update', fn (): bool => true );
    Gate::define( 'ecommerce.inventory.viewAny', fn (): bool => true );

    $this->patchJson( "/api/ecommerce/v1/admin/inventory/{$item->id}", [ 'allow_backorder' => true ], idem() )->assertForbidden();
    $this->postJson( "/api/ecommerce/v1/admin/inventory/{$item->id}/adjust", [ 'delta' => 1, 'reason' => 'x' ], idem() )->assertForbidden();

    Gate::define( 'ecommerce.inventory.adjust', fn (): bool => true );

    $this->patchJson( "/api/ecommerce/v1/admin/inventory/{$item->id}", [ 'allow_backorder' => true ], idem() )->assertOk();
    $this->postJson( "/api/ecommerce/v1/admin/inventory/{$item->id}/adjust", [ 'delta' => 1, 'reason' => 'x' ], idem() )->assertOk();
} );

it( '404s an unknown inventory row', function (): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->postJson( '/api/ecommerce/v1/admin/inventory/999999/adjust', [ 'delta' => 1, 'reason' => 'x' ], idem() )
        ->assertNotFound();
} );
