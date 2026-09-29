<?php

/**
 * DigitalDownloadController.
 *
 * Token-addressed file delivery (engine spec §9.9). The token in the URL
 * is the credential:
 *
 * - `GET downloads/{token}` spends one download and sends the file as an
 *   attachment. Streaming-only files are refused here (403).
 * - `GET downloads/{token}/stream` pipes the file inline with byte-range
 *   support. The first request of a stream (no `Range`, or one starting
 *   at byte 0) spends a download; later range requests of the same stream
 *   only re-check expiry.
 *
 * Refusals render as `problem+json`: 404 unknown token, 410 expired or out
 * of downloads, 403 streaming-only.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1;

use ArtisanPackUI\Ecommerce\Digital\DigitalFileStreamer;
use ArtisanPackUI\Ecommerce\Exceptions\DigitalDownloadException;
use ArtisanPackUI\Ecommerce\Http\Support\Problem;
use ArtisanPackUI\Ecommerce\Models\DigitalDownload;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use ArtisanPackUI\Ecommerce\Services\DigitalDownloadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class DigitalDownloadController extends ApiController
{
    /**
     * @since 1.0.0
     *
     * @param  DigitalDownloadService  $downloads  Entitlements.
     * @param  DigitalFileStreamer     $streamer   Response builder.
     */
    public function __construct(
        private readonly DigitalDownloadService $downloads,
        private readonly DigitalFileStreamer $streamer,
    ) {
    }

    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     * @param  string   $token    Download token.
     *
     * @return JsonResponse|Response|StreamedResponse
     */
    #[ApiOperation( summary: 'Download a purchased file', description: 'Spends one of the token\'s downloads and returns the file as an attachment.' )]
    public function show( Request $request, string $token ): StreamedResponse|JsonResponse|Response
    {
        return $this->deliver( $request, $token, DigitalDownloadService::MODE_DOWNLOAD, true );
    }

    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     * @param  string   $token    Download token.
     *
     * @return JsonResponse|Response|StreamedResponse
     */
    #[ApiOperation( summary: 'Stream a purchased file', description: 'Byte-range aware inline stream; the first request of a stream spends one download.' )]
    public function stream( Request $request, string $token ): StreamedResponse|JsonResponse|Response
    {
        return $this->deliver( $request, $token, DigitalDownloadService::MODE_STREAM, DigitalFileStreamer::startsStream( $request ) );
    }

    /**
     * Redeems the token and streams the file.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     * @param  string   $token    Download token.
     * @param  string   $mode     Delivery mode.
     * @param  bool     $counted  Whether the request spends a download.
     *
     * @return JsonResponse|Response|StreamedResponse
     */
    protected function deliver( Request $request, string $token, string $mode, bool $counted ): StreamedResponse|JsonResponse|Response
    {
        // Mail link scanners often probe with HEAD: answer from the token's
        // state without spending anything or sending the file.
        if ( $request->isMethod( 'HEAD' ) ) {
            return $this->peek( $request, $token );
        }

        try {
            $download = $this->downloads->redeem( $token, $mode, $request, $counted );

            return $this->streamer->respond( $download, $request, $mode );
        } catch ( DigitalDownloadException $e ) {
            return Problem::make( $e->status, $e->errorCode, __( 'Download unavailable' ), $e->getMessage(), $request );
        }
    }

    /**
     * A body-less status for a HEAD request: 200 while the token can still
     * be redeemed, 404 / 410 otherwise.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     * @param  string   $token    Download token.
     *
     * @return Response
     */
    protected function peek( Request $request, string $token ): Response
    {
        $download = DigitalDownload::query()->where( 'token', DigitalDownload::hashToken( $token ) )->first();

        $status = match ( true ) {
            null === $download                                                  => 404,
            $download->isExpired() || ! $download->hasDownloadsRemaining()      => 410,
            default                                                             => 200,
        };

        return new Response( '', $status, [ 'Cache-Control' => 'private, no-store' ] );
    }
}
