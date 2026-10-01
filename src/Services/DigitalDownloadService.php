<?php

/**
 * DigitalDownloadService.
 *
 * Issues and redeems download entitlements (parent plan §5.13, engine spec
 * §3.27). Download links carry opaque server-issued tokens rather than
 * Laravel signed URLs: a signed URL can prove it hasn't been tampered with,
 * but it can't atomically spend a download from a quota. Only the token's
 * sha256 is stored.
 *
 * Redeeming ({@see self::redeem()}) locks the entitlement row, enforces
 * `expires_at`, spends one of `downloads_remaining` with a conditional
 * decrement, and records a `digital_download_events` row — so two browser
 * tabs racing for the last download can't both win.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Services;

use ArtisanPackUI\Ecommerce\Events\DigitalDownloadTokenIssued;
use ArtisanPackUI\Ecommerce\Exceptions\DigitalDownloadException;
use ArtisanPackUI\Ecommerce\Models\DigitalDownload;
use ArtisanPackUI\Ecommerce\Models\DigitalDownloadEvent;
use ArtisanPackUI\Ecommerce\Models\DigitalFile;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class DigitalDownloadService
{
    /**
     * Redeeming through the download endpoint.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const MODE_DOWNLOAD = 'download';

    /**
     * Redeeming through the byte-range stream endpoint.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const MODE_STREAM = 'stream';

    /**
     * Length of a plain download token.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const TOKEN_LENGTH = 64;

    /**
     * Issues a download entitlement for `$file` on `$item`.
     *
     * Quota and lifetime default to `artisanpack.ecommerce.digital.download_limit`
     * and `.download_expiry_days` (set either to 0 for no cap). The
     * returned model carries the plain
     * token in {@see DigitalDownload::$plainToken} — the only place it
     * ever exists.
     *
     * @since 1.0.0
     *
     * @param  OrderItem               $item       Order line the entitlement is for.
     * @param  DigitalFile             $file       File it unlocks.
     * @param  int|null                $downloads  Download quota (null → configured default).
     * @param  DateTimeInterface|null  $expiresAt  Expiry (null → configured default).
     *
     * @return DigitalDownload
     */
    public function issue( OrderItem $item, DigitalFile $file, ?int $downloads = null, ?DateTimeInterface $expiresAt = null ): DigitalDownload
    {
        return $this->createDownload( $item, $file, $downloads ?? $this->defaultLimit(), $expiresAt ?? $this->defaultExpiry() );
    }

    /**
     * Issues entitlements for every digital file on every line of `$order`
     * that doesn't have one yet. Safe to call more than once (e.g. from a
     * retried payment webhook).
     *
     * @since 1.0.0
     *
     * @param  Order  $order  Paid order.
     *
     * @return array<int, DigitalDownload> The newly issued entitlements (with plain tokens).
     */
    public function issueForOrder( Order $order ): array
    {
        $issued = [];

        foreach ( $order->items()->get() as $item ) {
            $existing = $item->digitalDownloads()->pluck( 'digital_file_id' )->all();

            [ $downloads, $expiresAt ] = $this->productLimits( $item );

            foreach ( $this->filesFor( $item ) as $file ) {
                if ( ! in_array( $file->id, $existing, true ) ) {
                    $issued[] = $this->createDownload( $item, $file, $downloads, $expiresAt );
                }
            }
        }

        return $issued;
    }

    /**
     * The digital files an order line unlocks: its variant's files plus the
     * product's product-wide (variant-less) files.
     *
     * @since 1.0.0
     *
     * @param  OrderItem  $item  Order line.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, DigitalFile>
     */
    public function filesFor( OrderItem $item ): \Illuminate\Database\Eloquent\Collection
    {
        if ( null === $item->product_id && null === $item->product_variant_id ) {
            return new \Illuminate\Database\Eloquent\Collection();
        }

        return DigitalFile::query()
            ->where( function ( Builder $query ) use ( $item ): void {
                if ( null !== $item->product_variant_id ) {
                    $query->orWhere( 'product_variant_id', $item->product_variant_id );
                }

                if ( null !== $item->product_id ) {
                    $query->orWhere( fn ( Builder $q ) => $q->where( 'product_id', $item->product_id )->whereNull( 'product_variant_id' ) );
                }
            } )
            ->orderBy( 'id' )
            ->get();
    }

    /**
     * Resolves a plain token to its entitlement and spends one use of it.
     *
     * A `$counted` redemption (a download, or the first request of a
     * stream) decrements `downloads_remaining` and records an event; an
     * uncounted one (a later byte-range request of a stream already under
     * way, within the stream window) only re-checks expiry. Refused
     * redemptions of a known token are
     * recorded as `forbidden` events.
     *
     * @since 1.0.0
     *
     * @param  string   $plainToken  Token from the URL.
     * @param  string   $mode        {@see self::MODE_DOWNLOAD} or {@see self::MODE_STREAM}.
     * @param  Request  $request     Request (for the audit row).
     * @param  bool     $counted     Whether this request spends a download.
     *
     * @throws DigitalDownloadException When the token is unknown or can't be redeemed.
     *
     * @return DigitalDownload The entitlement, with its file loaded.
     */
    public function redeem( string $plainToken, string $mode, Request $request, bool $counted = true ): DigitalDownload
    {
        $id = DigitalDownload::query()->where( 'token', DigitalDownload::hashToken( $plainToken ) )->value( 'id' );

        if ( null === $id ) {
            throw new DigitalDownloadException( 'download-not-found', 404, __( 'This download link is not valid.' ) );
        }

        $refusal = DB::transaction( function () use ( $id, $mode, $counted, $request ): ?DigitalDownloadException {
            /** @var DigitalDownload $download */
            $download = DigitalDownload::query()->with( 'file' )->lockForUpdate()->findOrFail( $id );

            $refusal = $this->refusal( $download, $mode, $counted );

            if ( null !== $refusal ) {
                return $refusal;
            }

            if ( ! $counted ) {
                return null;
            }

            $spent = DigitalDownload::query()
                ->whereKey( $id )
                ->where( fn ( Builder $q ) => $q->whereNull( 'downloads_remaining' )->orWhere( 'downloads_remaining', '>', 0 ) )
                ->update( [
                    'downloads_remaining' => DB::raw( 'CASE WHEN downloads_remaining IS NULL THEN NULL ELSE downloads_remaining - 1 END' ),
                    'download_count'      => DB::raw( 'download_count + 1' ),
                    'first_downloaded_at' => $download->first_downloaded_at ?? now(),
                    'last_downloaded_at'  => now(),
                    'updated_at'          => now(),
                ] );

            if ( 0 === $spent ) {
                return $this->exhausted();
            }

            $this->record( $id, self::MODE_DOWNLOAD === $mode ? DigitalDownloadEvent::TYPE_DOWNLOAD : DigitalDownloadEvent::TYPE_STREAM, $request );

            return null;
        } );

        if ( null !== $refusal ) {
            $this->record( (int) $id, DigitalDownloadEvent::TYPE_FORBIDDEN, $request );

            throw $refusal;
        }

        return DigitalDownload::query()->with( 'file', 'orderItem' )->findOrFail( $id );
    }

    /**
     * Stores an entitlement with exactly the given quota and expiry (null
     * meaning unlimited / never) and fires the issued hooks.
     *
     * @since 1.0.0
     *
     * @param  OrderItem               $item       Order line.
     * @param  DigitalFile             $file       File.
     * @param  int|null                $downloads  Quota (null = unlimited).
     * @param  DateTimeInterface|null  $expiresAt  Expiry (null = never).
     *
     * @return DigitalDownload
     */
    protected function createDownload( OrderItem $item, DigitalFile $file, ?int $downloads, ?DateTimeInterface $expiresAt ): DigitalDownload
    {
        $plainToken = Str::random( self::TOKEN_LENGTH );

        $download = DigitalDownload::query()->create( [
            'order_item_id'       => $item->id,
            'digital_file_id'     => $file->id,
            'token'               => DigitalDownload::hashToken( $plainToken ),
            'downloads_remaining' => $downloads,
            'expires_at'          => $expiresAt,
        ] );

        $download->plainToken = $plainToken;

        doAction( 'ap.ecommerce.digital.tokenIssued', $download, $item );
        Event::dispatch( new DigitalDownloadTokenIssued( $download, $item ) );

        return $download;
    }

    /**
     * Why `$download` can't be redeemed right now, if it can't.
     *
     * @since 1.0.0
     *
     * @param  DigitalDownload  $download  Locked entitlement.
     * @param  string           $mode      Redemption mode.
     * @param  bool             $counted   Whether the request spends a download.
     *
     * @return DigitalDownloadException|null
     */
    protected function refusal( DigitalDownload $download, string $mode, bool $counted ): ?DigitalDownloadException
    {
        if ( self::MODE_DOWNLOAD === $mode && $download->file->is_streaming_only ) {
            return new DigitalDownloadException( 'download-streaming-only', 403, __( 'This file can only be streamed.' ) );
        }

        if ( $download->isExpired() ) {
            return new DigitalDownloadException( 'download-expired', 410, __( 'This download link has expired.' ) );
        }

        if ( $counted && ! $download->hasDownloadsRemaining() ) {
            return $this->exhausted();
        }

        // A byte-range continuation only makes sense for a stream that
        // spent its download recently: without the window, `Range: bytes=1-`
        // would fetch the whole file again, forever, for free.
        if ( ! $counted && ! $this->withinStreamWindow( $download ) ) {
            return new DigitalDownloadException( 'download-not-started', 416, __( 'Start the stream from the beginning of the file.' ) );
        }

        // Never spend a download on a file that isn't there.
        $location = $download->file->storageLocation();

        if ( null === $location || ! Storage::disk( $location[0] )->exists( $location[1] ) ) {
            return new DigitalDownloadException( 'download-file-missing', 404, __( 'The file for this download is unavailable.' ) );
        }

        return null;
    }

    /**
     * Whether a counted request started a stream on `$download` within the
     * last `artisanpack.ecommerce.digital.stream_window_minutes`.
     *
     * @since 1.0.0
     *
     * @param  DigitalDownload  $download  Locked entitlement.
     *
     * @return bool
     */
    protected function withinStreamWindow( DigitalDownload $download ): bool
    {
        $minutes = max( 1, (int) config( 'artisanpack.ecommerce.digital.stream_window_minutes', 240 ) );

        return null !== $download->last_downloaded_at && $download->last_downloaded_at->gt( now()->subMinutes( $minutes ) );
    }

    /**
     * @since 1.0.0
     *
     * @return DigitalDownloadException
     */
    protected function exhausted(): DigitalDownloadException
    {
        return new DigitalDownloadException( 'download-limit-reached', 410, __( 'This download link has no downloads left.' ) );
    }

    /**
     * Appends an audit row.
     *
     * @since 1.0.0
     *
     * @param  int      $downloadId  Entitlement id.
     * @param  string   $type        Event type.
     * @param  Request  $request     Request.
     *
     * @return void
     */
    protected function record( int $downloadId, string $type, Request $request ): void
    {
        DigitalDownloadEvent::query()->create( [
            'digital_download_id' => $downloadId,
            'ip_address'          => $request->ip(),
            'user_agent'          => Str::limit( (string) $request->userAgent(), 1_000, '' ),
            'event_type'          => $type,
        ] );
    }

    /**
     * The line's quota and expiry: the product's
     * `meta.digital.download_limit` / `meta.digital.download_expiry_days`
     * when set (`0` meaning no cap), else the configured defaults.
     *
     * @since 1.0.0
     *
     * @param  OrderItem  $item  Order line.
     *
     * @return array{0: int|null, 1: DateTimeInterface|null}
     */
    protected function productLimits( OrderItem $item ): array
    {
        $settings = (array) ( $item->product?->meta['digital'] ?? [] );
        $limit    = $settings['download_limit'] ?? null;
        $days     = $settings['download_expiry_days'] ?? null;

        return [
            is_numeric( $limit ) ? ( (int) $limit > 0 ? (int) $limit : null ) : $this->defaultLimit(),
            is_numeric( $days ) ? ( (int) $days > 0 ? now()->addDays( (int) $days ) : null ) : $this->defaultExpiry(),
        ];
    }

    /**
     * Configured default quota (null = unlimited).
     *
     * @since 1.0.0
     *
     * @return int|null
     */
    protected function defaultLimit(): ?int
    {
        $limit = config( 'artisanpack.ecommerce.digital.download_limit', 5 );

        return is_numeric( $limit ) && (int) $limit > 0 ? (int) $limit : null;
    }

    /**
     * Configured default expiry (null = never).
     *
     * @since 1.0.0
     *
     * @return DateTimeInterface|null
     */
    protected function defaultExpiry(): ?DateTimeInterface
    {
        $days = config( 'artisanpack.ecommerce.digital.download_expiry_days', 30 );

        return is_numeric( $days ) && (int) $days > 0 ? now()->addDays( (int) $days ) : null;
    }
}
