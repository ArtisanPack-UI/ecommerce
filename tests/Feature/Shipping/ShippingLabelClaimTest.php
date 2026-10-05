<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Contracts\ShippingLabelProvider;
use ArtisanPackUI\Ecommerce\Http\Resources\ShipmentResource;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\Shipment;
use ArtisanPackUI\Ecommerce\Registries\ShippingLabelProviderRegistry;
use ArtisanPackUI\Ecommerce\Services\ShipmentService;
use ArtisanPackUI\Ecommerce\ValueObjects\ShippingLabel;
use ArtisanPackUI\Ecommerce\ValueObjects\TrackingStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses( RefreshDatabase::class );

/**
 * A label provider whose purchase runs `$during` (the "carrier call").
 */
function claimTestProvider( Closure $during, int $labelId = 501 ): ShippingLabelProvider
{
    return new class( $during, $labelId ) implements ShippingLabelProvider {
        /** @var array<int, int> */
        public array $voided = [];

        public function __construct( private readonly Closure $during, private readonly int $labelId )
        {
        }

        public function key(): string
        {
            return 'claim-labels';
        }

        public function buyLabel( Shipment $shipment ): ShippingLabel
        {
            ( $this->during )( $shipment );

            return new ShippingLabel( $this->labelId, 'claim-labels', 'TRACK-' . $this->labelId, null, 'ups', 'ground' );
        }

        public function voidLabel( ShippingLabel $label ): void
        {
            $this->voided[] = $label->id;
        }

        public function trackLabel( ShippingLabel $label ): TrackingStatus
        {
            return new TrackingStatus( Shipment::STATUS_IN_TRANSIT );
        }
    };
}

beforeEach( function (): void {
    $this->service  = app( ShipmentService::class );
    $order          = Order::factory()->create();
    OrderItem::factory()->create( [ 'order_id' => $order->id, 'quantity' => 1 ] );
    $this->shipment = $this->service->create( $order, 'flat-rate' );
} );

it( 'calls the carrier outside any transaction, with the claim recorded and its key on the shipment', function (): void {
    $baseLevel = DB::transactionLevel();
    $seen      = [];

    app( ShippingLabelProviderRegistry::class )->register( 'claim-labels', claimTestProvider( function ( Shipment $shipment ) use ( $baseLevel, &$seen ): void {
        $seen = [
            'level'  => DB::transactionLevel(),
            'key'    => $shipment->meta['label_purchase']['key'] ?? null,
            'stored' => Shipment::query()->find( $shipment->id )->meta['label_purchase'] ?? null,
        ];

        expect( $seen['level'] )->toBe( $baseLevel );
    } ) );

    $label = $this->service->buyLabel( $this->shipment, 'claim-labels' );

    expect( $seen['key'] )->toBeString()->not->toBe( '' )
        ->and( $seen['stored']['key'] )->toBe( $seen['key'] )
        ->and( $seen['stored']['provider'] )->toBe( 'claim-labels' )
        ->and( $label->id )->toBe( 501 )
        ->and( $this->shipment->fresh()->only( [ 'label_id', 'tracking_number', 'carrier', 'service' ] ) )
        ->toBe( [ 'label_id' => 501, 'tracking_number' => 'TRACK-501', 'carrier' => 'ups', 'service' => 'ground' ] )
        ->and( $this->shipment->fresh()->meta )->toBeNull();
} );

it( 'lets other writes to the shipment through while the carrier responds', function (): void {
    app( ShippingLabelProviderRegistry::class )->register( 'claim-labels', claimTestProvider( function ( Shipment $shipment ): void {
        app( ShipmentService::class )->updateTracking( Shipment::query()->findOrFail( $shipment->id ), new TrackingStatus( Shipment::STATUS_IN_TRANSIT, trackingUrl: 'https://track.test/x' ) );
    } ) );

    $this->service->buyLabel( $this->shipment, 'claim-labels' );

    expect( $this->shipment->fresh()->only( [ 'status', 'tracking_url', 'label_id' ] ) )
        ->toBe( [ 'status' => Shipment::STATUS_IN_TRANSIT, 'tracking_url' => 'https://track.test/x', 'label_id' => 501 ] );
} );

it( 'refuses a second purchase while one is in progress', function (): void {
    $concurrent = null;

    app( ShippingLabelProviderRegistry::class )->register( 'claim-labels', claimTestProvider( function ( Shipment $shipment ) use ( &$concurrent ): void {
        try {
            app( ShipmentService::class )->buyLabel( Shipment::query()->findOrFail( $shipment->id ), 'claim-labels' );
        } catch ( InvalidArgumentException $exception ) {
            $concurrent = $exception->getMessage();
        }
    } ) );

    $this->service->buyLabel( $this->shipment, 'claim-labels' );

    expect( $concurrent )->toContain( 'already being bought' );
} );

it( 'clears the claim and rethrows when the purchase fails', function (): void {
    app( ShippingLabelProviderRegistry::class )->register( 'claim-labels', claimTestProvider( function (): void {
        throw new RuntimeException( 'carrier timeout' );
    } ) );

    expect( fn () => $this->service->buyLabel( $this->shipment, 'claim-labels' ) )->toThrow( RuntimeException::class, 'carrier timeout' );

    expect( $this->shipment->fresh()->meta )->toBeNull()
        ->and( $this->shipment->fresh()->label_id )->toBeNull();
} );

it( 'keeps other meta keys when it claims and finalizes', function (): void {
    $this->shipment->forceFill( [ 'meta' => [ 'picker' => 'sam' ] ] )->save();

    app( ShippingLabelProviderRegistry::class )->register( 'claim-labels', claimTestProvider( fn () => null ) );

    $this->service->buyLabel( $this->shipment, 'claim-labels' );

    expect( $this->shipment->fresh()->meta )->toBe( [ 'picker' => 'sam' ] );
} );

it( 'takes over a stale claim and reuses its key for the same provider', function (): void {
    $seenKey = null;

    $this->shipment->forceFill( [ 'meta' => [ 'label_purchase' => [
        'key'        => 'crashed-worker-key',
        'provider'   => 'claim-labels',
        'claimed_at' => Carbon::now()->subMinutes( 30 )->toIso8601String(),
    ] ] ] )->save();

    app( ShippingLabelProviderRegistry::class )->register( 'claim-labels', claimTestProvider( function ( Shipment $shipment ) use ( &$seenKey ): void {
        $seenKey = $shipment->meta['label_purchase']['key'];
    } ) );

    $this->service->buyLabel( $this->shipment, 'claim-labels' );

    expect( $seenKey )->toBe( 'crashed-worker-key' )
        ->and( $this->shipment->fresh()->label_id )->toBe( 501 );
} );

it( 'refuses a fresh claim left by another request', function (): void {
    $this->shipment->forceFill( [ 'meta' => [ 'label_purchase' => [
        'key'        => 'live-key',
        'provider'   => 'claim-labels',
        'claimed_at' => Carbon::now()->subMinute()->toIso8601String(),
    ] ] ] )->save();

    app( ShippingLabelProviderRegistry::class )->register( 'claim-labels', claimTestProvider( fn () => null ) );

    expect( fn () => $this->service->buyLabel( $this->shipment, 'claim-labels' ) )->toThrow( InvalidArgumentException::class, 'already being bought' );
} );

it( 'voids its label when another request finished the purchase first', function (): void {
    $provider = claimTestProvider( function ( Shipment $shipment ): void {
        // The request that took over our claim as stale finishes first.
        Shipment::query()->whereKey( $shipment->id )->update( [ 'label_id' => 900 ] );
    } );

    app( ShippingLabelProviderRegistry::class )->register( 'claim-labels', $provider );

    expect( fn () => $this->service->buyLabel( $this->shipment, 'claim-labels' ) )->toThrow( InvalidArgumentException::class, 'already has a label' );

    expect( $provider->voided )->toBe( [ 501 ] )
        ->and( $this->shipment->fresh()->label_id )->toBe( 900 );
} );

it( 'never renders the claim key in the shipment resource', function (): void {
    $this->shipment->forceFill( [ 'meta' => [ 'label_purchase' => [ 'key' => 'secret-key', 'provider' => 'claim-labels', 'claimed_at' => now()->toIso8601String() ] ] ] )->save();

    $rendered = ( new ShipmentResource( $this->shipment->fresh() ) )->resolve( request() );

    expect( $rendered['meta']['label_purchase'] )->toBe( [ 'provider' => 'claim-labels', 'claimed_at' => $this->shipment->fresh()->meta['label_purchase']['claimed_at'] ] );
} );
