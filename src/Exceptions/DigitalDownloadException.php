<?php

/**
 * DigitalDownloadException.
 *
 * Thrown by {@see \ArtisanPackUI\Ecommerce\Services\DigitalDownloadService}
 * when a download token can't be honoured: unknown, expired, out of
 * downloads, or a streaming-only file requested through the download
 * endpoint. The REST layer renders it as `problem+json` with
 * {@see self::$status} and {@see self::$errorCode} as the problem slug.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Exceptions;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class DigitalDownloadException extends EcommerceException
{
    /**
     * @since 1.0.0
     *
     * @param  string  $errorCode  Machine-readable reason (`download-not-found`, `download-expired`, …).
     * @param  int     $status     HTTP status to answer with.
     * @param  string  $message    Human-readable explanation.
     */
    public function __construct( public readonly string $errorCode, public readonly int $status, string $message )
    {
        parent::__construct( $message, [ 'error_code' => $errorCode ] );
    }
}
