<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Coupon;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\Promotion;
use ArtisanPackUI\Ecommerce\Models\Shipment;
use ArtisanPackUI\Ecommerce\Models\ShippingMethod;
use ArtisanPackUI\Ecommerce\Models\ShippingZone;
use ArtisanPackUI\Ecommerce\Models\TaxRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

require_once __DIR__ . '/ApiTestHelpers.php';

uses( RefreshDatabase::class );

it( 'requires authentication for admin routes', function (): void {
    $this->getJson( '/api/ecommerce/v1/orders' )->assertUnauthorized();
} );

it( 'denies by default when no Gate ability is defined', function (): void {
    $this->actingAs( ecommerceShopper(), 'sanctum' )
        ->getJson( '/api/ecommerce/v1/orders' )
        ->assertForbidden()
        ->assertHeader( 'Content-Type', 'application/problem+json' );
} );

it( 'prefers a specific ability over the umbrella one, and runs the ability filter last', function (): void {
    $admin = ecommerceAdmin();
    Gate::define( 'ecommerce.order.viewAny', fn (): bool => false );

    $this->actingAs( $admin, 'sanctum' )->getJson( '/api/ecommerce/v1/orders' )->assertForbidden();

    addFilter( 'ap.ecommerce.abilities.order.viewAny', fn ( bool $allowed ): bool => true );

    $this->actingAs( $admin, 'sanctum' )->getJson( '/api/ecommerce/v1/orders' )->assertOk();
} );

it( 'lists and shows orders with includes and admin-only fields', function (): void {
    $order = Order::factory()->create( [ 'ip_address' => '203.0.113.9', 'system_status' => 'processing' ] );
    Order::factory()->create( [ 'system_status' => 'pending' ] );
    OrderItem::factory()->count( 2 )->create( [ 'order_id' => $order->id ] );

    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $this->getJson( '/api/ecommerce/v1/orders?filter[system_status]=processing&include=items' )
        ->assertOk()
        ->assertJsonCount( 1, 'data' )
        ->assertJsonCount( 2, 'data.0.items' )
        ->assertJsonPath( 'data.0.ip_address', '203.0.113.9' )
        ->assertJsonPath( 'data.0.total.currency', 'USD' );

    $this->getJson( "/api/ecommerce/v1/orders/{$order->id}" )->assertOk()->assertJsonPath( 'data.order_number', $order->order_number );
} );

it( 'requires an Idempotency-Key on mutations and replays the original response', function (): void {
    $order = Order::factory()->create();
    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $this->patchJson( "/api/ecommerce/v1/orders/{$order->id}", [ 'customer_note' => 'Leave at door' ] )->assertStatus( 400 );

    $headers = idem();
    $first   = $this->patchJson( "/api/ecommerce/v1/orders/{$order->id}", [ 'customer_note' => 'Leave at door' ], $headers );

    $first->assertOk()->assertJsonPath( 'data.customer_note', 'Leave at door' );

    $this->patchJson( "/api/ecommerce/v1/orders/{$order->id}", [ 'customer_note' => 'Leave at door' ], $headers )
        ->assertOk()
        ->assertHeader( 'Idempotent-Replay', 'true' );
} );

it( 'creates and updates shipments through the order', function (): void {
    $order = Order::factory()->withSystemStatus( 'processing' )->create();
    $line  = OrderItem::factory()->create( [ 'order_id' => $order->id, 'quantity' => 2 ] );
    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $created = $this->postJson( "/api/ecommerce/v1/orders/{$order->id}/shipments", [
        'method_key' => 'flat-rate',
        'items'      => [ [ 'order_item_id' => $line->id, 'quantity' => 2 ] ],
        'carrier'    => 'usps',
    ], idem() )->assertCreated()->assertJsonPath( 'data.type', 'shipment' );

    $shipmentId = $created->json( 'data.id' );

    $this->patchJson( "/api/ecommerce/v1/orders/{$order->id}/shipments/{$shipmentId}", [ 'status' => 'in_transit', 'tracking_number' => '1Z' ], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.status', 'in_transit' )
        ->assertJsonPath( 'data.tracking_number', '1Z' );

    $this->postJson( "/api/ecommerce/v1/orders/{$order->id}/shipments", [ 'method_key' => 'flat-rate' ], idem() )
        ->assertStatus( 422 )
        ->assertHeader( 'Content-Type', 'application/problem+json' );

    $otherOrder = Order::factory()->create();
    $this->patchJson( "/api/ecommerce/v1/orders/{$otherOrder->id}/shipments/{$shipmentId}", [ 'status' => 'delivered' ], idem() )->assertNotFound();
} );

it( 'never exposes the local-pickup code hash', function (): void {
    $order = Order::factory()->withSystemStatus( 'processing' )->create();
    OrderItem::factory()->create( [ 'order_id' => $order->id ] );
    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $response = $this->postJson( "/api/ecommerce/v1/orders/{$order->id}/shipments", [ 'method_key' => 'local-pickup' ], idem() )->assertCreated();

    expect( $response->json( 'data.meta.pickup' ) )->toHaveKey( 'issued_at' )->not->toHaveKey( 'code_hash' );
    expect( Shipment::query()->first()->meta['pickup'] )->toHaveKey( 'code_hash' );
} );

it( 'updates customers and stamps marketing consent', function (): void {
    $customer = Customer::factory()->create( [ 'accepts_marketing' => false ] );
    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $this->patchJson( "/api/ecommerce/v1/customers/{$customer->id}", [ 'first_name' => 'Ada', 'accepts_marketing' => true ], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.first_name', 'Ada' )
        ->assertJsonPath( 'data.accepts_marketing', true );

    expect( $customer->fresh()->accepts_marketing_at )->not->toBeNull();
} );

it( 'manages tax rates, accepting percentages without floats', function (): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $created = $this->postJson( '/api/ecommerce/v1/admin/tax-rates', [
        'tax_class_key' => 'standard',
        'country_code'  => 'us',
        'region_code'   => 'il',
        'rate_percent'  => '8.375',
        'label'         => 'Cook County',
    ], idem() )->assertCreated();

    $created->assertJsonPath( 'data.rate_ubps', 83_750_000 )
        ->assertJsonPath( 'data.rate_percent', '8.375' )
        ->assertJsonPath( 'data.country_code', 'US' );

    $id = $created->json( 'data.id' );

    $this->patchJson( "/api/ecommerce/v1/admin/tax-rates/{$id}", [ 'is_active' => false ], idem() )->assertOk()->assertJsonPath( 'data.is_active', false );
    $this->deleteJson( "/api/ecommerce/v1/admin/tax-rates/{$id}", [], idem() )->assertOk()->assertJsonPath( 'data.id', $id );

    expect( TaxRate::query()->count() )->toBe( 0 );
} );

it( 'validates tax rates as problem+json', function (): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $response = $this->postJson( '/api/ecommerce/v1/admin/tax-rates', [
        'tax_class_key' => 'nope',
        'country_code'  => 'USA',
        'rate_percent'  => '1e2',
        'label'         => 'x',
    ], idem() )->assertStatus( 422 )->assertHeader( 'Content-Type', 'application/problem+json' );

    expect( array_column( $response->json( 'errors' ), 'field' ) )->toContain( 'tax_class_key', 'country_code', 'rate_ubps' );
} );

it( 'creates tax classes and lists them', function (): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $this->postJson( '/api/ecommerce/v1/admin/tax-classes', [ 'key' => 'luxury', 'label' => 'Luxury goods' ], idem() )->assertCreated();
    $this->postJson( '/api/ecommerce/v1/admin/tax-classes', [ 'key' => 'luxury', 'label' => 'Dup' ], idem() )->assertStatus( 422 );

    expect( $this->getJson( '/api/ecommerce/v1/admin/tax-classes' )->json( 'data.*.key' ) )->toContain( 'luxury', 'standard' );
} );

it( 'manages shipping zones and methods', function (): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $zone = $this->postJson( '/api/ecommerce/v1/admin/shipping-zones', [ 'name' => 'US', 'country_codes' => [ 'US' ] ], idem() )
        ->assertCreated()->json( 'data.id' );

    $method = $this->postJson( "/api/ecommerce/v1/admin/shipping-zones/{$zone}/methods", [
        'key' => 'flat-rate', 'label' => 'Standard', 'config' => [ 'amount' => 500 ],
    ], idem() )->assertCreated()->assertJsonPath( 'data.zone_id', $zone )->json( 'data.id' );

    $this->postJson( "/api/ecommerce/v1/admin/shipping-zones/{$zone}/methods", [ 'key' => 'teleport', 'label' => 'Nope' ], idem() )->assertStatus( 422 );
    $this->postJson( "/api/ecommerce/v1/admin/shipping-zones/{$zone}/methods", [ 'key' => 'provider:shippo', 'label' => 'Nope' ], idem() )->assertStatus( 422 );

    $this->patchJson( "/api/ecommerce/v1/admin/shipping-methods/{$method}", [ 'label' => 'Ground' ], idem() )->assertOk()->assertJsonPath( 'data.label', 'Ground' );

    $this->getJson( '/api/ecommerce/v1/admin/shipping-zones?include=methods' )->assertJsonPath( 'data.0.methods.0.label', 'Ground' );

    $this->deleteJson( "/api/ecommerce/v1/admin/shipping-zones/{$zone}", [], idem() )->assertOk();

    expect( ShippingZone::query()->count() )->toBe( 0 );
    expect( ShippingMethod::query()->count() )->toBe( 0 );
} );

it( 'creates a promotion with rules, replaces rules on update, and manages coupons', function (): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $promotion = $this->postJson( '/api/ecommerce/v1/admin/promotions', [
        'key'         => 'welcome',
        'name'        => 'Welcome 10%',
        'source_type' => 'coupon',
        'conditions'  => [ [ 'type' => 'customer-first-order', 'config' => [] ] ],
        'actions'     => [ [ 'type' => 'percent-off-cart', 'config' => [ 'percent' => 10 ] ] ],
    ], idem() )->assertCreated()
        ->assertJsonPath( 'data.conditions.0.condition_type', 'customer-first-order' )
        ->assertJsonPath( 'data.actions.0.config.percent', 10 )
        ->json( 'data.id' );

    $this->patchJson( "/api/ecommerce/v1/admin/promotions/{$promotion}", [
        'actions' => [ [ 'type' => 'fixed-off-cart', 'config' => [ 'amount' => 500 ] ] ],
    ], idem() )->assertOk()
        ->assertJsonCount( 1, 'data.actions' )
        ->assertJsonPath( 'data.actions.0.action_type', 'fixed-off-cart' )
        ->assertJsonCount( 1, 'data.conditions' );

    $coupon = $this->postJson( "/api/ecommerce/v1/admin/promotions/{$promotion}/coupons", [ 'code' => ' welcome10 ' ], idem() )
        ->assertCreated()->assertJsonPath( 'data.code', 'WELCOME10' )->json( 'data.id' );

    $this->postJson( "/api/ecommerce/v1/admin/promotions/{$promotion}/coupons", [ 'code' => 'WELCOME10' ], idem() )->assertStatus( 422 );
    $this->patchJson( "/api/ecommerce/v1/admin/coupons/{$coupon}", [ 'code' => 'hello' ], idem() )->assertOk()->assertJsonPath( 'data.code', 'HELLO' );
    $this->deleteJson( "/api/ecommerce/v1/admin/coupons/{$coupon}", [], idem() )->assertOk();

    expect( Coupon::query()->count() )->toBe( 0 );

    $this->deleteJson( "/api/ecommerce/v1/admin/promotions/{$promotion}", [], idem() )->assertOk();
    expect( Promotion::query()->count() )->toBe( 0 );
} );

it( 'rejects unregistered promotion rule types and sources', function (): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $response = $this->postJson( '/api/ecommerce/v1/admin/promotions', [
        'key'         => 'bad',
        'name'        => 'Bad',
        'source_type' => 'carrier-pigeon',
        'conditions'  => [ [ 'type' => 'moon-phase', 'config' => [] ] ],
        'actions'     => [ [ 'type' => 'free-money', 'config' => [] ] ],
    ], idem() )->assertStatus( 422 );

    expect( array_column( $response->json( 'errors' ), 'field' ) )->toContain( 'source_type', 'conditions.0.type', 'actions.0.type' );
} );

it( 'lists inventory for admins', function (): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' )->getJson( '/api/ecommerce/v1/admin/inventory' )->assertOk()->assertJsonPath( 'data', [] );
} );
