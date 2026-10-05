<?php

/**
 * EcommerceJsonFormatter.
 *
 * Monolog formatter that emits structured JSON log lines shaped for the
 * ecommerce engine. Every line carries the canonical fields the engine
 * indexes on downstream: `timestamp`, `level`, `channel`, `message`,
 * `event`, `request_id`, `actor_scope`, and the primary domain
 * identifiers (`order_id`, `cart_id`, …) — surfaced from the log
 * `$context` array so callers only need one `Log::info()` call.
 *
 * Engine plan §16.3.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Logging;

use ArtisanPackUI\Ecommerce\Support\RequestContext;
use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class EcommerceJsonFormatter implements FormatterInterface
{
    /**
     * Context keys promoted to top-level JSON fields. Anything else in the
     * caller's context array is nested under `context` so it stays
     * available without polluting the top-level schema.
     *
     * @since 1.0.0
     *
     * @var  array<int, string>
     */
    protected const PROMOTED_KEYS = [
        'event',
        'request_id',
        'actor_scope',
        'actor_id',
        'order_id',
        'cart_id',
        'customer_id',
        'product_id',
    ];

    /**
     * Formats a single log record as a JSON string terminated by "\n".
     *
     * @since 1.0.0
     *
     * @param  LogRecord  $record  The Monolog record to format.
     *
     * @return string
     */
    public function format( LogRecord $record ): string
    {
        $context = $record->context;
        $extra   = $record->extra;

        $payload = [
            'timestamp' => $record->datetime->format( 'Y-m-d\TH:i:s.uP' ),
            'level'     => strtolower( $record->level->getName() ),
            'channel'   => $record->channel,
            'message'   => $record->message,
        ];

        foreach ( self::PROMOTED_KEYS as $key ) {
            if ( array_key_exists( $key, $context ) ) {
                $payload[ $key ] = $context[ $key ];
                unset( $context[ $key ] );
            } elseif ( array_key_exists( $key, $extra ) ) {
                $payload[ $key ] = $extra[ $key ];
                unset( $extra[ $key ] );
            }
        }

        // The active request ID is always available from the request-scoped
        // holder, even when the caller did not pass it explicitly and the
        // shared Log::withContext did not propagate through this channel.
        if ( ! array_key_exists( 'request_id', $payload ) ) {
            $activeRequestId = RequestContext::requestId();

            if ( null !== $activeRequestId ) {
                $payload['request_id'] = $activeRequestId;
            }
        }

        if ( ! empty( $context ) ) {
            $payload['context'] = $context;
        }

        if ( ! empty( $extra ) ) {
            $payload['extra'] = $extra;
        }

        return json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR ) . "\n";
    }

    /**
     * Formats a batch of log records by concatenating each formatted line.
     *
     * @since 1.0.0
     *
     * @param  array<int, LogRecord>  $records  The records to format.
     *
     * @return string
     */
    public function formatBatch( array $records ): string
    {
        $out = '';

        foreach ( $records as $record ) {
            $out .= $this->format( $record );
        }

        return $out;
    }
}
