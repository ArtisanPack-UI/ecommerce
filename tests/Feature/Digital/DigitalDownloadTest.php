<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Events\DigitalDownloadTokenIssued;
use ArtisanPackUI\Ecommerce\Exceptions\DigitalDownloadException;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\DigitalDownload;
use ArtisanPackUI\Ecommerce\Models\DigitalDownloadEvent;
use ArtisanPackUI\Ecommerce\Models\DigitalFile;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use ArtisanPackUI\Ecommerce\Notifications\EcommerceNotification;
use ArtisanPackUI\Ecommerce\Notifications\NotificationCatalog;
use ArtisanPackUI\Ecommerce\Services\DigitalDownloadService;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Money\Money;
use Symfony\Component\HttpFoundation\StreamedResponse;

uses( RefreshDatabase::class );

const DOWNLOADS_API = '/api/ecommerce/v1/downloads';
const FILE_BYTES    = '0123456789abcdefghij';

beforeEach( function (): void {
    Storage::fake( 'local' );
    Storage::disk( 'local' )->put( 'digital/book.pdf', FILE_BYTES );
} );

/**
 * An entitlement for `digital/book.pdf` with the plain token `$token`.
 *
 * @param  array<string, mixed>  $attributes
 * @param  array<string, mixed>  $file
 */
function downloadFor( string $token, array $attributes = [], array $file = [] ): DigitalDownload
{
    return DigitalDownload::factory()
        ->withToken( $token )
        ->for( DigitalFile::factory()->state( $file + [ 'disk' => 'local', 'path' => 'digital/book.pdf', 'label' => 'The Book' ] ), 'file' )
        ->create( $attributes );
}

function downloadToken(): string
{
    return str_repeat( 'a', 32 ) . Illuminate\Support\Str::random( 32 );
}

it( 'issues opaque tokens and stores only their hash', function (): void {
    Event::fake( [ DigitalDownloadTokenIssued::class ] );
    $file = DigitalFile::factory()->create();
    $item = OrderItem::factory()->create();

    $download = app( DigitalDownloadService::class )->issue( $item, $file );

    expect( $download->plainToken )->toHaveLength( 64 )
        ->and( $download->token )->toBe( hash( 'sha256', $download->plainToken ) )
        ->and( $download->downloads_remaining )->toBe( 5 )
        ->and( $download->expires_at->isSameDay( now()->addDays( 30 ) ) )->toBeTrue()
        ->and( $download->fresh()->toArray() )->not->toHaveKey( 'token' );

    Event::assertDispatched( DigitalDownloadTokenIssued::class );
} );

it( 'serves the file and spends a download', function (): void {
    $token    = downloadToken();
    $download = downloadFor( $token, [ 'downloads_remaining' => 2 ] );

    $response = $this->get( DOWNLOADS_API . "/{$token}" );

    $response->assertOk()
        ->assertHeader( 'Content-Disposition', 'attachment; filename="The Book.pdf"' )
        ->assertHeader( 'Cache-Control', 'no-store, private' );

    expect( $response->streamedContent() )->toBe( FILE_BYTES );

    $download->refresh();

    expect( $download->downloads_remaining )->toBe( 1 )
        ->and( $download->download_count )->toBe( 1 )
        ->and( $download->first_downloaded_at )->not->toBeNull()
        ->and( $download->events()->pluck( 'event_type' )->all() )->toBe( [ DigitalDownloadEvent::TYPE_DOWNLOAD ] );
} );

it( 'answers unknown tokens with 404', function (): void {
    $this->getJson( DOWNLOADS_API . '/' . downloadToken() )
        ->assertNotFound()
        ->assertJsonPath( 'type', 'https://docs.artisanpack-ui.dev/ecommerce/problems/download-not-found' );
} );

it( 'lets only one of two racing requests take the last download', function (): void {
    $token    = downloadToken();
    $download = downloadFor( $token, [ 'downloads_remaining' => 1 ] );
    $service  = app( DigitalDownloadService::class );
    $request  = Request::create( '/' );

    $service->redeem( $token, DigitalDownloadService::MODE_DOWNLOAD, $request );

    expect( fn () => $service->redeem( $token, DigitalDownloadService::MODE_DOWNLOAD, $request ) )
        ->toThrow( DigitalDownloadException::class, 'no downloads left' );

    $this->getJson( DOWNLOADS_API . "/{$token}" )->assertStatus( 410 );

    expect( $download->fresh()->downloads_remaining )->toBe( 0 )
        ->and( $download->fresh()->download_count )->toBe( 1 )
        ->and( $download->events()->where( 'event_type', DigitalDownloadEvent::TYPE_FORBIDDEN )->count() )->toBe( 2 );
} );

it( 'refuses expired entitlements', function (): void {
    $token = downloadToken();
    downloadFor( $token, [ 'expires_at' => now()->subMinute() ] );

    $this->getJson( DOWNLOADS_API . "/{$token}" )->assertStatus( 410 )->assertJsonPath( 'type', 'https://docs.artisanpack-ui.dev/ecommerce/problems/download-expired' );
} );

it( 'allows unlimited downloads when the quota is null', function (): void {
    $token    = downloadToken();
    $download = downloadFor( $token, [ 'downloads_remaining' => null, 'expires_at' => null ] );

    foreach ( range( 1, 3 ) as $attempt ) {
        $this->get( DOWNLOADS_API . "/{$token}" )->assertOk();
    }

    expect( $download->fresh()->downloads_remaining )->toBeNull()->and( $download->fresh()->download_count )->toBe( 3 );
} );

it( 'does not spend a download when the file is missing', function (): void {
    $token    = downloadToken();
    $download = downloadFor( $token, [ 'downloads_remaining' => 1 ], [ 'path' => 'digital/missing.pdf' ] );

    $this->getJson( DOWNLOADS_API . "/{$token}" )->assertNotFound()->assertJsonPath( 'type', 'https://docs.artisanpack-ui.dev/ecommerce/problems/download-file-missing' );

    expect( $download->fresh()->downloads_remaining )->toBe( 1 );
} );

it( 'never serves streaming-only files through the download endpoint', function (): void {
    $token = downloadToken();
    downloadFor( $token, [], [ 'is_streaming_only' => true ] );

    $this->getJson( DOWNLOADS_API . "/{$token}" )->assertForbidden();

    $response = $this->get( DOWNLOADS_API . "/{$token}/stream" );

    $response->assertOk()->assertHeader( 'Accept-Ranges', 'bytes' );

    expect( $response->headers->get( 'Content-Disposition' ) )->toStartWith( 'inline' )
        ->and( $response->streamedContent() )->toBe( FILE_BYTES );
} );

it( 'streams byte ranges, counting only the start of a stream', function (): void {
    $token    = downloadToken();
    $download = downloadFor( $token, [ 'downloads_remaining' => 1 ] );

    $this->get( DOWNLOADS_API . "/{$token}/stream", [ 'Range' => 'bytes=5-9' ] )
        ->assertStatus( 416 );

    $first = $this->get( DOWNLOADS_API . "/{$token}/stream", [ 'Range' => 'bytes=0-4' ] );
    $first->assertStatus( 206 )->assertHeader( 'Content-Range', 'bytes 0-4/20' )->assertHeader( 'Content-Length', '5' );
    expect( $first->streamedContent() )->toBe( '01234' );

    $next = $this->get( DOWNLOADS_API . "/{$token}/stream", [ 'Range' => 'bytes=15-' ] );
    $next->assertStatus( 206 )->assertHeader( 'Content-Range', 'bytes 15-19/20' );
    expect( $next->streamedContent() )->toBe( 'fghij' );

    $this->get( DOWNLOADS_API . "/{$token}/stream", [ 'Range' => 'bytes=40-50' ] )
        ->assertStatus( 416 )
        ->assertHeader( 'Content-Range', 'bytes */20' );

    expect( $download->fresh()->downloads_remaining )->toBe( 0 )
        ->and( $download->events()->where( 'event_type', DigitalDownloadEvent::TYPE_STREAM )->count() )->toBe( 1 );

    // A new stream needs a download that is no longer there.
    $this->get( DOWNLOADS_API . "/{$token}/stream" )->assertStatus( 410 );

    // Continuations stop once the stream window has passed.
    $this->travel( 241 )->minutes();
    $this->get( DOWNLOADS_API . "/{$token}/stream", [ 'Range' => 'bytes=1-' ] )->assertStatus( 416 );
} );

it( 'caps what the range requests of one stream may send', function (): void {
    $token    = downloadToken();
    $download = downloadFor( $token, [ 'downloads_remaining' => 1 ] );

    // One counted start, then free continuations up to 3× the file size (60 bytes).
    $this->get( DOWNLOADS_API . "/{$token}/stream", [ 'Range' => 'bytes=0-9' ] )->assertStatus( 206 );

    foreach ( range( 1, 2 ) as $copy ) {
        $this->get( DOWNLOADS_API . "/{$token}/stream", [ 'Range' => 'bytes=1-' ] )->assertStatus( 206 );
    }

    $this->getJson( DOWNLOADS_API . "/{$token}/stream", [ 'Range' => 'bytes=1-' ] )
        ->assertStatus( 410 )
        ->assertJsonPath( 'type', 'https://docs.artisanpack-ui.dev/ecommerce/problems/download-stream-exhausted' );

    expect( $download->fresh()->downloads_remaining )->toBe( 0 );
} );

it( 'answers HEAD probes without spending a download', function (): void {
    $token    = downloadToken();
    $download = downloadFor( $token, [ 'downloads_remaining' => 1 ] );

    $this->call( 'HEAD', DOWNLOADS_API . "/{$token}" )->assertOk();
    $this->call( 'HEAD', DOWNLOADS_API . '/' . downloadToken() )->assertNotFound();

    expect( $download->fresh()->downloads_remaining )->toBe( 1 )
        ->and( $download->events()->count() )->toBe( 0 );

    $download->update( [ 'downloads_remaining' => 0 ] );
    $this->call( 'HEAD', DOWNLOADS_API . "/{$token}" )->assertStatus( 410 );
} );

it( 'takes digital access back when an order is fully refunded or cancelled', function (): void {
    $refunded  = Order::factory()->create( [ 'payment_status' => 'refunded' ] );
    $partial   = Order::factory()->create( [ 'payment_status' => 'partially_refunded' ] );
    $cancelled = Order::factory()->create();

    $grant = function ( Order $order ): array {
        $item = OrderItem::factory()->create( [ 'order_id' => $order->id ] );

        return [
            DigitalDownload::factory()->create( [ 'order_item_id' => $item->id ] ),
            ArtisanPackUI\Ecommerce\Models\LicenseKey::factory()->create( [ 'order_item_id' => $item->id ] ),
        ];
    };

    [ $refundedDownload, $refundedKey ]   = $grant( $refunded );
    [ $partialDownload, $partialKey ]     = $grant( $partial );
    [ $cancelledDownload, $cancelledKey ] = $grant( $cancelled );

    Notification::fake();
    doAction( 'ap.ecommerce.order.refunded', $refunded, ArtisanPackUI\Ecommerce\Models\Refund::factory()->create( [ 'order_id' => $refunded->id ] ) );
    doAction( 'ap.ecommerce.order.refunded', $partial, ArtisanPackUI\Ecommerce\Models\Refund::factory()->create( [ 'order_id' => $partial->id ] ) );
    doAction( 'ap.ecommerce.order.statusChanged', $cancelled, 'processing', 'cancelled' );

    expect( $refundedDownload->fresh()->isExpired() )->toBeTrue()
        ->and( $refundedKey->fresh()->is_revoked )->toBeTrue()
        ->and( $refundedKey->fresh()->meta['revoked_reason'] )->toBe( 'Order refunded' )
        ->and( $partialDownload->fresh()->isExpired() )->toBeFalse()
        ->and( $partialKey->fresh()->is_revoked )->toBeFalse()
        ->and( $cancelledDownload->fresh()->isExpired() )->toBeTrue()
        ->and( $cancelledKey->fresh()->is_revoked )->toBeTrue();
} );

it( 'runs stream responses through the watermark filter', function (): void {
    $token = downloadToken();
    $seen  = null;
    downloadFor( $token );

    addFilter( 'ap.ecommerce.digital.streamWatermark', function ( StreamedResponse $response, DigitalDownload $download ) use ( &$seen ): StreamedResponse {
        $seen = $download->id;
        $response->headers->set( 'X-Watermarked', 'yes' );

        return $response;
    } );

    $this->get( DOWNLOADS_API . "/{$token}/stream" )->assertOk()->assertHeader( 'X-Watermarked', 'yes' );

    expect( $seen )->not->toBeNull();
} );

it( 'issues downloads for every digital file on a paid order, once', function (): void {
    $product = Product::factory()->digital()->create();
    $variant = ProductVariant::factory()->create( [ 'product_id' => $product->id ] );
    $other   = ProductVariant::factory()->create( [ 'product_id' => $product->id ] );
    $shared  = DigitalFile::factory()->create( [ 'product_id' => $product->id ] );
    $mine    = DigitalFile::factory()->create( [ 'product_id' => $product->id, 'product_variant_id' => $variant->id ] );
    DigitalFile::factory()->create( [ 'product_id' => $product->id, 'product_variant_id' => $other->id ] );

    $order = Order::factory()->create();
    OrderItem::factory()->create( [ 'order_id' => $order->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id ] );

    $service = app( DigitalDownloadService::class );
    $issued  = $service->issueForOrder( $order );

    expect( collect( $issued )->pluck( 'digital_file_id' )->sort()->values()->all() )->toBe( [ $shared->id, $mine->id ] )
        ->and( $service->issueForOrder( $order ) )->toBe( [] )
        ->and( DigitalDownload::query()->count() )->toBe( 2 );
} );

it( 'uses the product\'s own download limit and expiry, where 0 means no cap', function ( array $digital, ?int $remaining, bool $expires ): void {
    config( [ 'artisanpack.ecommerce.digital.download_limit' => 5, 'artisanpack.ecommerce.digital.download_expiry_days' => 30 ] );

    $product = Product::factory()->digital()->create( [ 'meta' => [ 'digital' => $digital ] ] );
    DigitalFile::factory()->create( [ 'product_id' => $product->id ] );
    $order = Order::factory()->create();
    OrderItem::factory()->create( [ 'order_id' => $order->id, 'product_id' => $product->id ] );

    $download = app( DigitalDownloadService::class )->issueForOrder( $order )[0];

    expect( $download->downloads_remaining )->toBe( $remaining )
        ->and( null !== $download->expires_at )->toBe( $expires );
} )->with( [
    'product limits'   => [ [ 'download_limit' => 3, 'download_expiry_days' => 7 ], 3, true ],
    'no caps'          => [ [ 'download_limit' => 0, 'download_expiry_days' => 0 ], null, false ],
    'config defaults'  => [ [], 5, true ],
] );

it( 'issues downloads and emails the links when payment succeeds', function (): void {
    Notification::fake();
    $customer = Customer::factory()->create();
    $product  = Product::factory()->digital()->create();
    DigitalFile::factory()->create( [ 'product_id' => $product->id, 'label' => 'Handbook' ] );
    $order = Order::factory()->forCustomer( $customer )->create( [ 'payment_status' => 'paid' ] );
    OrderItem::factory()->create( [ 'order_id' => $order->id, 'product_id' => $product->id ] );

    doAction( 'ap.ecommerce.payment.succeeded', PaymentResult::success( Money::USD( 5_000 ), 'pi_1' ), $order );
    doAction( 'ap.ecommerce.payment.succeeded', PaymentResult::success( Money::USD( 5_000 ), 'pi_1' ), $order );

    expect( DigitalDownload::query()->count() )->toBe( 1 );

    Notification::assertSentToTimes( $customer, EcommerceNotification::class, 1 );
    Notification::assertSentTo( $customer, EcommerceNotification::class, function ( EcommerceNotification $notification ) use ( $customer ): bool {
        $mail = $notification->toMail( $customer );
        $url  = $notification->variables['Downloads'][0]['url'];

        return NotificationCatalog::DOWNLOAD_READY === $notification->templateKey
            && str_contains( $url, '/api/ecommerce/v1/downloads/' )
            && DigitalDownload::query()->where( 'token', hash( 'sha256', basename( $url ) ) )->exists()
            && str_contains( $mail->htmlBody, 'Handbook' );
    } );
} );
