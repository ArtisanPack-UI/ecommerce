<?php

/**
 * RandomEightCharGenerator.
 *
 * The reference {@see \ArtisanPackUI\Ecommerce\Contracts\OrderNumberGenerator}
 * implementation. Emits eight-character uppercase alphanumeric order numbers
 * with ambiguous glyphs (`0`, `O`, `1`, `I`) stripped from the alphabet, and
 * checks the `orders` table for the generated value to keep concurrent
 * placement collision-safe. Engine spec §4.9.
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

use ArtisanPackUI\Ecommerce\Contracts\OrderNumberGenerator;
use ArtisanPackUI\Ecommerce\Models\Order;
use Closure;
use RuntimeException;

/**
 * Random 8-character alphanumeric order-number generator.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class RandomEightCharGenerator implements OrderNumberGenerator
{
    /**
     * Alphabet used for the generated numbers.
     *
     * Excludes ambiguous glyphs (`0`, `O`, `1`, `I`) to keep printed / hand-typed
     * order numbers unambiguous.
     *
     * @since 1.0.0
     */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * Number of characters in each generated order number.
     *
     * @since 1.0.0
     */
    private const LENGTH = 8;

    /**
     * Maximum collision retries before giving up.
     *
     * @since 1.0.0
     */
    private const MAX_ATTEMPTS = 16;

    /**
     * Optional candidate producer used by the contract test to inject a
     * deterministic candidate stream. Production callers always leave this
     * `null` so {@see self::randomString()} runs.
     *
     * @since 1.0.0
     *
     * @var Closure(): string|null
     */
    private ?Closure $candidateFactory;

    /**
     * @since 1.0.0
     *
     * @param  (Closure(): string)|null  $candidateFactory  Test-only hook. When `null`,
     *                                                     candidates are drawn from
     *                                                     {@see self::randomString()}.
     */
    public function __construct( ?Closure $candidateFactory = null )
    {
        $this->candidateFactory = $candidateFactory;
    }

    /**
     * @since 1.0.0
     *
     * @param  Order  $order  Order being placed.
     *
     * @return string
     */
    public function generate( Order $order ): string
    {
        for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++ ) {
            $candidate = $this->nextCandidate();

            if ( ! Order::query()->where( 'order_number', $candidate )->exists() ) {
                return $candidate;
            }
        }

        throw new RuntimeException( 'Unable to generate a unique order number after ' . self::MAX_ATTEMPTS . ' attempts.' );
    }

    /**
     * Returns the next candidate — either from the injected factory (used by
     * the contract test to prove DB-checking behaviour) or from the default
     * CSPRNG-backed alphabet draw.
     *
     * @since 1.0.0
     *
     * @return string
     */
    private function nextCandidate(): string
    {
        if ( null !== $this->candidateFactory ) {
            return ( $this->candidateFactory )();
        }

        return $this->randomString();
    }

    /**
     * Draws a fresh candidate order number using {@see random_int()}.
     *
     * @since 1.0.0
     *
     * @return string
     */
    private function randomString(): string
    {
        $alphabet = self::ALPHABET;
        $max      = strlen( $alphabet ) - 1;
        $out      = '';

        for ( $i = 0; $i < self::LENGTH; $i++ ) {
            $out .= $alphabet[ random_int( 0, $max ) ];
        }

        return $out;
    }
}
