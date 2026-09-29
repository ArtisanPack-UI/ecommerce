<?php

/**
 * DigitalFileStreamer.
 *
 * Builds the HTTP response that pipes a redeemed {@see DigitalDownload}'s
 * bytes to the customer. The file is read from its storage disk and
 * streamed through PHP — its disk path or storage URL is never exposed,
 * which is what keeps streaming-only files from leaking a direct link.
 *
 * Stream responses honour a single `Range: bytes=…` request (206 Partial
 * Content, 416 when unsatisfiable) so media players can seek. Before the
 * first byte goes out, the context runs through
 * `ap.ecommerce.digital.downloading`, and stream responses run through
 * `ap.ecommerce.digital.streamWatermark` so a satellite can wrap the byte
 * stream (e.g. stamping the buyer's email into a PDF). When the last byte
 * has been written, `ap.ecommerce.digital.downloaded` fires.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Digital;

use ArtisanPackUI\Ecommerce\Exceptions\DigitalDownloadException;
use ArtisanPackUI\Ecommerce\Models\DigitalDownload;
use ArtisanPackUI\Ecommerce\Services\DigitalDownloadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnexpectedValueException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class DigitalFileStreamer
{
    /**
     * Bytes per chunk written to the client.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const CHUNK_BYTES = 1_048_576;

    /**
     * Parses a `Range` header against a file of `$size` bytes.
     *
     * Returns `null` when the whole file should be sent (no header, a
     * multi-range or malformed header — RFC 9110 lets a server ignore
     * those), `false` when the range is unsatisfiable, or the inclusive
     * `[ start, end ]` byte offsets.
     *
     * @since 1.0.0
     *
     * @param  string|null  $header  `Range` header value.
     * @param  int          $size    File size in bytes.
     *
     * @return array{0: int, 1: int}|false|null
     */
    public static function parseRange( ?string $header, int $size ): array|false|null
    {
        if ( null === $header || 1 !== preg_match( '/^bytes=(\d*)-(\d*)$/', trim( $header ), $matches ) ) {
            return null;
        }

        [ , $start, $end ] = $matches;

        if ( '' === $start && '' === $end ) {
            return null;
        }

        if ( '' === $start ) {
            // Suffix range: the last N bytes.
            $length = (int) $end;

            return 0 === $length || 0 === $size ? false : [ max( 0, $size - $length ), $size - 1 ];
        }

        $first = (int) $start;
        $last  = '' === $end ? $size - 1 : min( (int) $end, $size - 1 );

        return $first >= $size || $first > $last ? false : [ $first, $last ];
    }

    /**
     * Whether a request starts a stream (and so spends a download): no
     * range, or a range that starts at byte 0.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return bool
     */
    public static function startsStream( Request $request ): bool
    {
        $header = $request->header( 'Range' );

        return null === $header || 1 !== preg_match( '/^bytes=(\d+)-\d*$/', trim( (string) $header ), $matches ) || 0 === (int) $matches[1];
    }

    /**
     * Builds the response for a redeemed entitlement.
     *
     * @since 1.0.0
     *
     * @param  DigitalDownload  $download  Redeemed entitlement (file loaded).
     * @param  Request          $request   Request (for `Range`).
     * @param  string           $mode      {@see DigitalDownloadService::MODE_DOWNLOAD} or `MODE_STREAM`.
     *
     * @throws DigitalDownloadException When the file's bytes can't be found.
     *
     * @return StreamedResponse
     */
    public function respond( DigitalDownload $download, Request $request, string $mode ): StreamedResponse
    {
        $location = $download->file->storageLocation();

        if ( null === $location || ! Storage::disk( $location[0] )->exists( $location[1] ) ) {
            throw new DigitalDownloadException( 'download-file-missing', 404, __( 'The file for this download is unavailable.' ) );
        }

        $context = (array) applyFilters( 'ap.ecommerce.digital.downloading', [
            'mode'     => $mode,
            'disk'     => $location[0],
            'path'     => $location[1],
            'filename' => $download->file->downloadName(),
            'headers'  => [],
        ], $download );

        $disk  = Storage::disk( (string) $context['disk'] );
        $path  = (string) $context['path'];
        $size  = (int) $disk->size( $path );
        $range = DigitalDownloadService::MODE_STREAM === $mode ? self::parseRange( $request->header( 'Range' ), $size ) : null;

        if ( false === $range ) {
            return new StreamedResponse( static function (): void {
            }, 416, [ 'Content-Range' => 'bytes */' . $size, 'Accept-Ranges' => 'bytes' ] );
        }

        [ $start, $end ] = $range ?? [ 0, $size - 1 ];

        if ( DigitalDownloadService::MODE_STREAM === $mode ) {
            $this->spendStreamAllowance( $download, max( 0, $end - $start + 1 ), $size );
        }

        $headers = array_merge( [
            'Content-Type'           => $disk->mimeType( $path ) ?: 'application/octet-stream',
            'Content-Length'         => (string) max( 0, $end - $start + 1 ),
            'Content-Disposition'    => HeaderUtils::makeDisposition(
                DigitalDownloadService::MODE_STREAM === $mode ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
                (string) $context['filename'],
                $this->asciiFallback( (string) $context['filename'] ),
            ),
            'Accept-Ranges'          => DigitalDownloadService::MODE_STREAM === $mode ? 'bytes' : 'none',
            'Cache-Control'          => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], (array) $context['headers'] );

        if ( null !== $range ) {
            $headers['Content-Range'] = sprintf( 'bytes %d-%d/%d', $start, $end, $size );
        }

        $response = new StreamedResponse( function () use ( $disk, $path, $start, $end, $size, $download ): void {
            $this->pipe( $disk->readStream( $path ), $start, $end, $size );

            doAction( 'ap.ecommerce.digital.downloaded', $download );
        }, null === $range ? 200 : 206, $headers );

        if ( DigitalDownloadService::MODE_STREAM !== $mode ) {
            return $response;
        }

        $watermarked = applyFilters( 'ap.ecommerce.digital.streamWatermark', $response, $download );

        if ( ! $watermarked instanceof StreamedResponse ) {
            throw new UnexpectedValueException( 'ap.ecommerce.digital.streamWatermark must return a StreamedResponse.' );
        }

        return $watermarked;
    }

    /**
     * Charges `$bytes` against the stream the entitlement last started.
     *
     * One counted request opens a stream; its byte-range continuations are
     * free, but together they may not send more than
     * `artisanpack.ecommerce.digital.stream_byte_allowance` times the file
     * size (default 3) — enough for a player to seek around, not enough to
     * turn one spent download into unlimited copies.
     *
     * @since 1.0.0
     *
     * @param  DigitalDownload  $download  Redeemed entitlement.
     * @param  int              $bytes     Bytes about to be sent.
     * @param  int              $size      File size.
     *
     * @throws DigitalDownloadException When the stream's allowance is spent.
     *
     * @return void
     */
    protected function spendStreamAllowance( DigitalDownload $download, int $bytes, int $size ): void
    {
        $multiple = max( 1, (int) config( 'artisanpack.ecommerce.digital.stream_byte_allowance', 3 ) );
        $minutes  = max( 1, (int) config( 'artisanpack.ecommerce.digital.stream_window_minutes', 240 ) );
        $key      = sprintf( 'ecommerce:digital-stream:%d:%d', $download->id, (int) $download->last_downloaded_at?->getTimestamp() );

        Cache::add( $key, 0, now()->addMinutes( $minutes ) );

        if ( (int) Cache::increment( $key, $bytes ) > $multiple * max( 1, $size ) ) {
            throw new DigitalDownloadException( 'download-stream-exhausted', 410, __( 'This stream has used its allowance. Start a new stream.' ) );
        }
    }

    /**
     * Copies bytes `$start`–`$end` of `$stream` to the output buffer.
     *
     * @since 1.0.0
     *
     * @param  resource|null  $stream  Readable stream.
     * @param  int            $start   First byte (inclusive).
     * @param  int            $end     Last byte (inclusive).
     * @param  int            $size    Total size.
     *
     * @return void
     */
    protected function pipe( $stream, int $start, int $end, int $size ): void
    {
        if ( ! is_resource( $stream ) || 0 === $size ) {
            return;
        }

        if ( $start > 0 && 0 !== fseek( $stream, $start ) ) {
            // Non-seekable remote streams: read and discard up to $start.
            $skip = $start;

            while ( $skip > 0 && ! feof( $stream ) ) {
                $read  = fread( $stream, (int) min( self::CHUNK_BYTES, $skip ) );
                $skip -= false === $read ? $skip : strlen( $read );
            }
        }

        $remaining = $end - $start + 1;

        while ( $remaining > 0 && ! feof( $stream ) ) {
            $chunk = fread( $stream, (int) min( self::CHUNK_BYTES, $remaining ) );

            if ( false === $chunk || '' === $chunk ) {
                break;
            }

            $remaining -= strlen( $chunk );

            echo $chunk;
            flush();
        }

        fclose( $stream );
    }

    /**
     * An ASCII-only fallback filename for `Content-Disposition`.
     *
     * @since 1.0.0
     *
     * @param  string  $filename  Filename.
     *
     * @return string
     */
    protected function asciiFallback( string $filename ): string
    {
        $ascii = (string) preg_replace( '/[^\x20-\x7E]|[%\/\\\\"]/', '', $filename );

        return '' === $ascii ? 'download' : $ascii;
    }
}
