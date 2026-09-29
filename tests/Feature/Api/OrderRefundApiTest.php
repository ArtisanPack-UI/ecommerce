<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Contracts\PaymentGateway;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\Refund;
use ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry;
use ArtisanPackUI\Ecommerce\ValueObjects\RefundResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Money\Money;

require_once __DIR__ . '/ApiTestHelpers.php';

uses( RefreshDatabase::class );

beforeEach( function (): void {
    $gateway = Mockery::mock( PaymentGateway::class );
    $gateway->shouldReceive( 'key' )->andReturn( 'fake' );
    $gateway->shouldReceive( 'supportsRefunds' )->andReturn( true );
    $gateway->shouldReceive( 'supportsPartialRefunds' )->andReturn( true );
    $gateway->shouldReceive( 'refund' )->andReturnUsing( fn ( Order $order, Money $amount ): RefundResult => RefundResult::success( $amount, 're_api_1' ) );

    app( PaymentGatewayRegistry::class )->register( 'fake', $gateway );

    $this->order = Order::factory()->create( [ 'payment_status' => 'paid', 'payment_gateway_key' => 'fake', 'total_amount' => 3_000 ] );
    $this->item  = OrderItem::factory()->create( [ 'order_id' => $this->order->id, 'quantity' => 3, 'unit_price_amount' => 1_000, 'total_amount' => 3_000 ] );
} );

it( 'issues a refund through the refund service', function (): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->postJson( "/api/ecommerce/v1/orders/{$this->order->id}/refunds", [
            'lines'  => [ [ 'order_item_id' => $this->item->id, 'quantity' => 1, 'amount' => 1_000 ] ],
            'reason' => 'Damaged',
        ], idem() )
        ->assertCreated()
        ->assertJsonPath( 'data.type', 'refund' )
        ->assertJsonPath( 'data.amount.amount', 1_000 )
        ->assertJsonPath( 'data.gateway_reference', 're_api_1' )
        ->assertJsonPath( 'data.issued_by_user_id', 1 )
        ->assertJsonCount( 1, 'data.items' );

    expect( Refund::query()->count() )->toBe( 1 );
} );

it( 'requires the order refund ability', function (): void {
    Gate::define( 'ecommerce.admin', fn (): bool => true );
    Gate::define( 'ecommerce.order.refund', fn (): bool => false );

    $this->actingAs( ecommerceShopper(), 'sanctum' )
        ->postJson( "/api/ecommerce/v1/orders/{$this->order->id}/refunds", [
            'lines' => [ [ 'order_item_id' => $this->item->id, 'quantity' => 1, 'amount' => 1_000 ] ],
        ], idem() )
        ->assertForbidden();
} );

it( 'reports refunds the service rejects as problem+json', function (): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->postJson( "/api/ecommerce/v1/orders/{$this->order->id}/refunds", [
            'lines' => [ [ 'order_item_id' => $this->item->id, 'quantity' => 1, 'amount' => 999_999 ] ],
        ], idem() )
        ->assertStatus( 422 )
        ->assertJsonPath( 'type', 'https://docs.artisanpack-ui.dev/ecommerce/problems/refund-not-allowed' );

    expect( Refund::query()->count() )->toBe( 0 );
} );

it( 'validates the refund payload', function (): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->postJson( "/api/ecommerce/v1/orders/{$this->order->id}/refunds", [ 'lines' => [] ], idem() )
        ->assertStatus( 422 )
        ->assertJsonPath( 'errors.0.field', 'lines' );
} );
