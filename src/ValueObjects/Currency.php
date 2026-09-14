<?php

/**
 * Currency value object.
 *
 * ISO 4217 currency code, stored uppercase.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\ValueObjects;

use InvalidArgumentException;
use JsonSerializable;
use Money\Currency as MoneyCurrency;
use Stringable;

/**
 * Currency Value Object (ISO 4217).
 *
 * Immutable wrapper around a three-letter ISO 4217 currency code.
 * Pairs with {@see \ArtisanPackUI\Ecommerce\Casts\MoneyCast} on the write
 * side and with {@see \Money\Money} on the read side.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class Currency implements JsonSerializable, Stringable
{
    /**
     * ISO 4217 currency code, three uppercase A–Z letters.
     *
     * @since 1.0.0
     *
     * @var string
     */
    private readonly string $code;

    /**
     * Creates a new Currency value object.
     *
     * @since 1.0.0
     *
     * @param  string  $code  ISO 4217 currency code (case-insensitive).
     *
     * @throws InvalidArgumentException When the code is not three A–Z letters.
     */
    public function __construct( string $code )
    {
        $normalized = strtoupper( trim( $code ) );

        if ( 1 !== preg_match( '/^[A-Z]{3}$/', $normalized ) ) {
            throw new InvalidArgumentException(
                sprintf( 'Invalid ISO 4217 currency code: "%s".', $code ),
            );
        }

        $this->code = $normalized;
    }

    /**
     * Returns the currency code when cast to string.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->code;
    }

    /**
     * Named constructor for a Currency value object.
     *
     * @since 1.0.0
     *
     * @param  string  $code  ISO 4217 currency code.
     *
     * @return self
     */
    public static function of( string $code ): self
    {
        return new self( $code );
    }

    /**
     * Returns the ISO 4217 currency code.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function code(): string
    {
        return $this->code;
    }

    /**
     * Determines whether two currency codes are equivalent.
     *
     * @since 1.0.0
     *
     * @param  self  $other  Currency to compare against.
     *
     * @return bool
     */
    public function equals( self $other ): bool
    {
        return $this->code === $other->code;
    }

    /**
     * Converts this value object to a moneyphp Currency instance.
     *
     * @since 1.0.0
     *
     * @return MoneyCurrency
     */
    public function toMoneyPhp(): MoneyCurrency
    {
        return new MoneyCurrency( $this->code );
    }

    /**
     * Serializes the value object as its currency code.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function jsonSerialize(): string
    {
        return $this->code;
    }
}
