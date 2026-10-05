<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\ProductReview;
use ArtisanPackUI\Ecommerce\Models\ProductReviewMedia;
use ArtisanPackUI\Ecommerce\Models\Shipment;
use ArtisanPackUI\Ecommerce\Models\ShipmentItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses( RefreshDatabase::class );

it( 'links review media to its review with integer ids and no timestamps', function (): void {
    $review = ProductReview::factory()->create();
    $media  = ProductReviewMedia::query()->create( [ 'review_id' => (string) $review->id, 'media_id' => '42' ] );

    expect( $media->getTable() )->toBe( 'ecommerce_product_review_media' )
        ->and( $media->review->is( $review ) )->toBeTrue()
        ->and( $media->fresh()->media_id )->toBe( 42 )
        ->and( $media->fresh()->review_id )->toBe( $review->id )
        ->and( $media->usesTimestamps() )->toBeFalse()
        ->and( $review->media()->pluck( 'media_id' )->all() )->toBe( [ 42 ] )
        ->and( ProductReviewMedia::factory()->create()->review )->toBeInstanceOf( ProductReview::class );
} );

it( 'links shipment items to their shipment and order line', function (): void {
    $shipment = Shipment::factory()->create();
    $line     = OrderItem::factory()->create( [ 'order_id' => $shipment->order_id ] );
    $item     = ShipmentItem::query()->create( [ 'shipment_id' => $shipment->id, 'order_item_id' => $line->id, 'quantity' => '3' ] );

    expect( $item->getTable() )->toBe( 'ecommerce_shipment_items' )
        ->and( $item->shipment->is( $shipment ) )->toBeTrue()
        ->and( $item->orderItem->is( $line ) )->toBeTrue()
        ->and( $item->fresh()->quantity )->toBe( 3 )
        ->and( $item->usesTimestamps() )->toBeFalse()
        ->and( $shipment->items()->pluck( 'id' )->all() )->toBe( [ $item->id ] );

    $made = ShipmentItem::factory()->create();

    expect( $made->shipment )->toBeInstanceOf( Shipment::class )
        ->and( $made->orderItem )->toBeInstanceOf( OrderItem::class )
        ->and( $made->quantity )->toBe( 1 );
} );

it( 'only mass-assigns its own columns', function (): void {
    expect( ( new ProductReviewMedia() )->getFillable() )->toBe( [ 'review_id', 'media_id' ] )
        ->and( ( new ShipmentItem() )->getFillable() )->toBe( [ 'shipment_id', 'order_item_id', 'quantity' ] );
} );
