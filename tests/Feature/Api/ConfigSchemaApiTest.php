<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Contracts\PromotionCondition;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\KanbanAutomation;
use ArtisanPackUI\Ecommerce\Models\Promotion;
use ArtisanPackUI\Ecommerce\Models\ShippingMethod;
use ArtisanPackUI\Ecommerce\Models\ShippingZone;
use ArtisanPackUI\Ecommerce\Registries\PromotionConditionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

require_once __DIR__ . '/ApiTestHelpers.php';
require_once __DIR__ . '/../Kanban/KanbanTestHelpers.php';

uses( RefreshDatabase::class );

it( 'lists promotion conditions, promotion actions, and shipping method types with their schemas', function ( string $path, string $key, string $field, string $type ): void {
    $data = $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->getJson( "/api/ecommerce/v1/admin/{$path}" )
        ->assertOk()
        ->json( 'data' );

    $row = collect( $data )->firstWhere( 'key', $key );

    expect( $row )->not->toBeNull()
        ->and( $row['provided_by'] )->toBe( 'ecommerce' )
        ->and( $row['label'] )->toBeString()->not->toBe( '' )
        ->and( collect( $row['config_schema'] )->firstWhere( 'name', $field ) )->toMatchArray( [ 'type' => $type ] );
} )->with( [
    'min-subtotal condition' => [ 'promotion-conditions', 'min-subtotal', 'amount', 'money' ],
    'day-of-week condition'  => [ 'promotion-conditions', 'day-of-week', 'days', 'weekday' ],
    'tiered action'          => [ 'promotion-actions', 'tiered-discount', 'tiers', 'repeater' ],
    'weight-based method'    => [ 'shipping-method-types', 'weight-based', 'unit', 'select' ],
] );

it( 'spells the presence and type rules out in each schema field', function (): void {
    $row = collect( $this->actingAs( ecommerceAdmin(), 'sanctum' )->getJson( '/api/ecommerce/v1/admin/promotion-actions' )->json( 'data' ) )
        ->firstWhere( 'key', 'percent-off-cart' );

    expect( $row['config_schema'][0] )->toMatchArray( [
        'name'     => 'percent',
        'type'     => 'percent',
        'required' => true,
        'rules'    => [ 'required', 'numeric', 'gt:0', 'max:100' ],
    ] );
} );

it( 'returns a null schema for an entry that declares none', function (): void {
    app( PromotionConditionRegistry::class )->register( 'always', new class implements PromotionCondition {
        public function key(): string
        {
            return 'always';
        }

        public function label(): string
        {
            return 'Always';
        }

        public function evaluate( Cart $cart, array $config ): bool
        {
            return true;
        }
    } );

    $row = collect( $this->actingAs( ecommerceAdmin(), 'sanctum' )->getJson( '/api/ecommerce/v1/admin/promotion-conditions' )->json( 'data' ) )
        ->firstWhere( 'key', 'always' );

    expect( $row['config_schema'] )->toBeNull();

    // …and its config is accepted as-is.
    $this->postJson( '/api/ecommerce/v1/admin/promotions', [
        'key'        => 'anything', 'name' => 'Anything', 'source_type' => 'automatic',
        'conditions' => [ [ 'type' => 'always', 'config' => [ 'whatever' => [ 1, 2 ] ] ] ],
    ], idem() )->assertCreated();
} );

it( 'gates the catalogs on the promotion and shipping abilities', function (): void {
    $this->actingAs( ecommerceShopper(), 'sanctum' );

    $this->getJson( '/api/ecommerce/v1/admin/promotion-conditions' )->assertForbidden();
    $this->getJson( '/api/ecommerce/v1/admin/promotion-actions' )->assertForbidden();
    $this->getJson( '/api/ecommerce/v1/admin/shipping-method-types' )->assertForbidden();

    Gate::define( 'ecommerce.promotion.viewAny', fn (): bool => true );
    Gate::define( 'ecommerce.shippingZone.viewAny', fn (): bool => true );

    $this->getJson( '/api/ecommerce/v1/admin/promotion-conditions' )->assertOk();
    $this->getJson( '/api/ecommerce/v1/admin/shipping-method-types' )->assertOk();
} );

it( 'includes schemas in the kanban widget and trigger catalogs', function (): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $trigger = collect( $this->getJson( '/api/ecommerce/v1/kanban/triggers' )->assertOk()->json( 'data' ) )->firstWhere( 'key', 'send-email' );
    $widget  = collect( $this->getJson( '/api/ecommerce/v1/kanban/widgets' )->assertOk()->json( 'data' ) )->firstWhere( 'key', 'total' );

    expect( collect( $trigger['config_schema'] )->firstWhere( 'name', 'subject' ) )->toMatchArray( [ 'type' => 'template', 'required' => true ] )
        ->and( collect( $trigger['config_schema'] )->firstWhere( 'name', 'subject' )['tokens'] )->toContain( '{order_number}' )
        ->and( $widget['config_schema'] )->toBe( [] );
} );

it( 'validates promotion rule configs against their schemas', function (): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $base = [ 'key' => 'big-spender', 'name' => 'Big spender', 'source_type' => 'automatic' ];

    $this->postJson( '/api/ecommerce/v1/admin/promotions', $base + [
        'conditions' => [ [ 'type' => 'min-subtotal', 'config' => [ 'amount' => 'lots' ] ] ],
        'actions'    => [ [ 'type' => 'percent-off-cart', 'config' => [ 'percent' => 150 ] ] ],
    ], idem() )
        ->assertStatus( 422 )
        ->assertJsonFragment( [ 'field' => 'conditions.0.config.amount' ] )
        ->assertJsonFragment( [ 'field' => 'actions.0.config.percent' ] );

    $this->postJson( '/api/ecommerce/v1/admin/promotions', $base + [
        'actions' => [ [ 'type' => 'tiered-discount', 'config' => [ 'tiers' => [ [ 'percent' => 5 ] ] ] ] ],
    ], idem() )
        ->assertStatus( 422 )
        ->assertJsonFragment( [ 'field' => 'actions.0.config.tiers.0.min_subtotal' ] );

    expect( Promotion::query()->count() )->toBe( 0 );
} );

it( 'accepts money as minor units or a currency map, and keeps undeclared config keys', function (): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $id = $this->postJson( '/api/ecommerce/v1/admin/promotions', [
        'key'        => 'big-spender', 'name' => 'Big spender', 'source_type' => 'automatic',
        'conditions' => [ [ 'type' => 'min-subtotal', 'config' => [ 'amount' => [ 'USD' => 5000, 'EUR' => 4500 ], 'note' => 'internal' ] ] ],
        'actions'    => [ [ 'type' => 'fixed-off-cart', 'config' => [ 'amount' => 500 ] ] ],
    ], idem() )->assertCreated()->json( 'data.id' );

    $condition = Promotion::query()->findOrFail( $id )->conditions()->firstOrFail();

    expect( $condition->config )->toBe( [ 'amount' => [ 'USD' => 5000, 'EUR' => 4500 ], 'note' => 'internal' ] );

    $this->patchJson( "/api/ecommerce/v1/admin/promotions/{$id}", [
        'conditions' => [ [ 'type' => 'min-subtotal', 'config' => [ 'amount' => [ 'DOLLARS' => 5 ] ] ] ],
    ], idem() )
        ->assertStatus( 422 )
        ->assertJsonFragment( [ 'field' => 'conditions.0.config.amount' ] );
} );

it( 'validates shipping method configs against the method type schema', function (): void {
    $zone = ShippingZone::factory()->create();
    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $this->postJson( "/api/ecommerce/v1/admin/shipping-zones/{$zone->id}/methods", [ 'key' => 'price-based', 'label' => 'Tiered' ], idem() )
        ->assertStatus( 422 )
        ->assertJsonFragment( [ 'field' => 'config.tiers' ] );

    $id = $this->postJson( "/api/ecommerce/v1/admin/shipping-zones/{$zone->id}/methods", [
        'key'    => 'weight-based', 'label' => 'By weight',
        'config' => [ 'unit' => 'lb', 'tiers' => [ [ 'max_weight' => 5, 'amount' => 700 ], [ 'amount' => 1500 ] ] ],
    ], idem() )->assertCreated()->json( 'data.id' );

    $this->patchJson( "/api/ecommerce/v1/admin/shipping-methods/{$id}", [ 'config' => [ 'unit' => 'stone', 'tiers' => [ [ 'amount' => 1 ] ] ] ], idem() )
        ->assertStatus( 422 )
        ->assertJsonFragment( [ 'field' => 'config.unit' ] );

    // A label-only update doesn't re-check the stored config.
    $this->patchJson( "/api/ecommerce/v1/admin/shipping-methods/{$id}", [ 'label' => 'Weight' ], idem() )->assertOk();

    expect( ShippingMethod::query()->findOrFail( $id )->config['tiers'][1] )->toBe( [ 'amount' => 1500 ] );
} );

it( 'validates kanban automation trigger configs against the trigger schema', function (): void {
    $board = kanbanBoard( [], [ [ 'processing', 'Printing' ] ] );
    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $payload = [ 'to_column_id' => kanbanColumn( $board, 'Printing' )->id, 'trigger_key' => 'send-email' ];

    $this->postJson( "/api/ecommerce/v1/kanban/boards/{$board->id}/automations", $payload + [ 'trigger_config' => [ 'to' => [ 'customer' ] ] ], idem() )
        ->assertStatus( 422 )
        ->assertJsonFragment( [ 'field' => 'trigger_config.subject' ] )
        ->assertJsonPath( 'errors.0.message', 'The Subject field is required.' );

    $id = $this->postJson( "/api/ecommerce/v1/kanban/boards/{$board->id}/automations", $payload + [ 'trigger_config' => [ 'to' => 'customer', 'subject' => 'Printing {order_number}' ] ], idem() )
        ->assertCreated()
        ->json( 'data.id' );

    $this->patchJson( "/api/ecommerce/v1/kanban/automations/{$id}", [ 'trigger_key' => 'webhook', 'trigger_config' => [ 'url' => 'not a url' ] ], idem() )
        ->assertStatus( 422 )
        ->assertJsonFragment( [ 'field' => 'trigger_config.url' ] );

    expect( KanbanAutomation::query()->findOrFail( $id )->trigger_key )->toBe( 'send-email' );
} );

it( 're-checks the stored config when an update changes the entry key without sending a new config', function (): void {
    $zone  = ShippingZone::factory()->create();
    $board = kanbanBoard( [], [ [ 'processing', 'Printing' ] ] );
    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $method = $this->postJson( "/api/ecommerce/v1/admin/shipping-zones/{$zone->id}/methods", [ 'key' => 'flat-rate', 'label' => 'Flat', 'config' => [ 'amount' => 500 ] ], idem() )
        ->assertCreated()->json( 'data.id' );

    // price-based needs tiers; the flat-rate config has none.
    $this->patchJson( "/api/ecommerce/v1/admin/shipping-methods/{$method}", [ 'key' => 'price-based' ], idem() )
        ->assertStatus( 422 )
        ->assertJsonFragment( [ 'field' => 'config.tiers' ] );

    $automation = $this->postJson( "/api/ecommerce/v1/kanban/boards/{$board->id}/automations", [
        'to_column_id'   => kanbanColumn( $board, 'Printing' )->id,
        'trigger_key'    => 'send-email',
        'trigger_config' => [ 'to' => 'customer', 'subject' => 'Hi' ],
    ], idem() )->assertCreated()->json( 'data.id' );

    $this->patchJson( "/api/ecommerce/v1/kanban/automations/{$automation}", [ 'trigger_key' => 'webhook' ], idem() )
        ->assertStatus( 422 )
        ->assertJsonFragment( [ 'field' => 'trigger_config.url' ] );

    expect( ShippingMethod::query()->findOrFail( $method )->key )->toBe( 'flat-rate' )
        ->and( KanbanAutomation::query()->findOrFail( $automation )->trigger_key )->toBe( 'send-email' );
} );
