<?php

/**
 * MoneyCast.
 *
 * Bridges the paired `{prefix}_amount BIGINT` + `{prefix}_currency CHAR(3)`
 * column pattern to a single {@see Money} value object on the model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Casts;

use ArtisanPackUI\Ecommerce\ValueObjects\Currency as CurrencyVO;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Money\Currency;
use Money\Money;

/**
 * Casts a paired amount + currency column set into a Money value object.
 *
 * Storage layout (engine spec §2.2):
 *   {prefix}_amount   BIGINT       -- signed minor units
 *   {prefix}_currency CHAR(3)      -- ISO 4217
 *
 * Usage:
 *   protected function casts(): array
 *   {
 *       return [
 *           'subtotal' => MoneyCast::class,
 *           'discount' => MoneyCast::class . ':discount_total_amount,discount_total_currency',
 *       ];
 *   }
 *
 * The set() side accepts:
 *   - a {@see Money} instance
 *   - a `[int $amount, string $currency]` tuple
 *   - an `['amount' => int, 'currency' => string]` array
 *   - null (to clear both columns)
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @implements CastsAttributes<Money, array{0: int|string, 1: string}|array{amount: int|string, currency: string}|Money>
 */
final class MoneyCast implements CastsAttributes
{
    /**
     * Column name that stores the signed minor-unit amount.
     *
     * @since 1.0.0
     *
     * @var string|null
     */
    private readonly ?string $amountColumn;

    /**
     * Column name that stores the ISO 4217 currency code.
     *
     * @since 1.0.0
     *
     * @var string|null
     */
    private readonly ?string $currencyColumn;

    /**
     * Configures the cast with optional explicit column names.
     *
     * Column overrides are all-or-nothing: pass both explicit names to opt
     * out of the `{key}_amount` / `{key}_currency` defaults, or omit both.
     *
     * @since 1.0.0
     *
     * @param  string|null  $amountColumn    Column that stores the minor-unit amount (defaults to `{key}_amount`).
     * @param  string|null  $currencyColumn  Column that stores the ISO 4217 code (defaults to `{key}_currency`).
     *
     * @throws InvalidArgumentException When exactly one column override is provided.
     */
    public function __construct( ?string $amountColumn = null, ?string $currencyColumn = null )
    {
        if ( ( null === $amountColumn ) !== ( null === $currencyColumn ) ) {
            throw new InvalidArgumentException(
                'MoneyCast requires both column overrides or neither; mixing explicit and default names silently desynchronises the pair.',
            );
        }

        $this->amountColumn   = $amountColumn;
        $this->currencyColumn = $currencyColumn;
    }

    /**
     * Hydrates the paired amount + currency columns into a {@see Money} instance.
     *
     * @since 1.0.0
     *
     * @param  Model                $model       The Eloquent model being hydrated.
     * @param  string               $key         The cast attribute name.
     * @param  mixed                $value       The raw value on the model for `$key` (unused; see $attributes).
     * @param  array<string, mixed> $attributes  Raw column values from the underlying row.
     *
     * @return Money|null
     */
    public function get( Model $model, string $key, mixed $value, array $attributes ): ?Money
    {
        [ $amountColumn, $currencyColumn ] = $this->columns( $key );

        $amount   = $attributes[ $amountColumn ] ?? null;
        $currency = $attributes[ $currencyColumn ] ?? null;

        if ( null === $amount || null === $currency ) {
            return null;
        }

        return new Money(
            self::normalizeAmount( $amount ),
            CurrencyVO::of( (string) $currency )->toMoneyPhp(),
        );
    }

    /**
     * Splits a {@see Money}, tuple, or amount+currency array back into the paired columns.
     *
     * @since 1.0.0
     *
     * @param  Model                $model       The Eloquent model being persisted.
     * @param  string               $key         The cast attribute name.
     * @param  mixed                $value       The application-side value being written.
     * @param  array<string, mixed> $attributes  Existing raw attributes on the model.
     *
     * @return array<string, int|string|null>
     */
    public function set( Model $model, string $key, mixed $value, array $attributes ): array
    {
        [ $amountColumn, $currencyColumn ] = $this->columns( $key );

        if ( null === $value ) {
            return [
                $amountColumn   => null,
                $currencyColumn => null,
            ];
        }

        [ $amount, $currency ] = self::extractPair( $value );

        return [
            $amountColumn   => $amount,
            $currencyColumn => $currency,
        ];
    }

    /**
     * Resolves the amount and currency column names for the given attribute.
     *
     * @since 1.0.0
     *
     * @param  string  $key  The cast attribute name.
     *
     * @return array{0: string, 1: string}
     */
    private function columns( string $key ): array
    {
        return [
            $this->amountColumn ?? $key . '_amount',
            $this->currencyColumn ?? $key . '_currency',
        ];
    }

    /**
     * Extracts a signed integer amount and ISO 4217 code from a supported value.
     *
     * @since 1.0.0
     *
     * @param  mixed  $value  Money instance, `[amount, currency]` tuple, or associative array.
     *
     * @throws InvalidArgumentException When the value cannot be mapped.
     *
     * @return array{0: int, 1: string}
     */
    private static function extractPair( mixed $value ): array
    {
        if ( $value instanceof Money ) {
            return [
                self::normalizeAmount( $value->getAmount() ),
                CurrencyVO::of( $value->getCurrency()->getCode() )->code(),
            ];
        }

        if ( is_array( $value ) ) {
            if ( array_key_exists( 'amount', $value ) && array_key_exists( 'currency', $value ) ) {
                return [
                    self::normalizeAmount( $value[ 'amount' ] ),
                    CurrencyVO::of( self::stringifyCurrency( $value[ 'currency' ] ) )->code(),
                ];
            }

            if ( array_key_exists( 0, $value ) && array_key_exists( 1, $value ) && 2 === count( $value ) ) {
                return [
                    self::normalizeAmount( $value[ 0 ] ),
                    CurrencyVO::of( self::stringifyCurrency( $value[ 1 ] ) )->code(),
                ];
            }
        }

        throw new InvalidArgumentException(
            'MoneyCast expected a Money instance, [amount, currency] tuple, or [amount, currency] array.',
        );
    }

    /**
     * Coerces a supported currency representation to its ISO 4217 code.
     *
     * @since 1.0.0
     *
     * @param  mixed  $currency  Currency value object, moneyphp currency, or ISO 4217 string.
     *
     * @throws InvalidArgumentException When the value cannot be mapped to a currency code.
     *
     * @return string
     */
    private static function stringifyCurrency( mixed $currency ): string
    {
        if ( $currency instanceof CurrencyVO ) {
            return $currency->code();
        }

        if ( $currency instanceof Currency ) {
            return $currency->getCode();
        }

        if ( is_string( $currency ) ) {
            return $currency;
        }

        throw new InvalidArgumentException(
            'MoneyCast expected a currency code string, Money\\Currency, or Ecommerce Currency value object.',
        );
    }

    /**
     * Normalises the amount to a signed integer of minor units.
     *
     * Floats are explicitly rejected — see acceptance criteria in issue #3
     * (0.1 + 0.2 must never reach the money layer). Values outside the
     * signed 64-bit range are rejected too: {@see Money::getAmount()} is an
     * arbitrary-precision numeric string, so multiplying a large `Money` can
     * yield a value past `PHP_INT_MAX`; `(int)` casting would silently
     * truncate it and corrupt the persisted amount.
     *
     * @since 1.0.0
     *
     * @param  mixed  $amount  Integer or integer-string amount.
     *
     * @throws InvalidArgumentException When the amount is a float, a non-integer string, or exceeds signed 64-bit range.
     *
     * @return int
     */
    private static function normalizeAmount( mixed $amount ): int
    {
        if ( is_int( $amount ) ) {
            return $amount;
        }

        if ( is_string( $amount ) ) {
            $filtered = filter_var( $amount, FILTER_VALIDATE_INT );

            if ( false !== $filtered ) {
                return $filtered;
            }

            // A digit-only string that FILTER_VALIDATE_INT rejects is one
            // that overflowed PHP_INT_MIN…MAX — distinguish that from a
            // float / garbage string so callers get a signal, not a silent
            // truncation on `(int) $amount`.
            if ( 1 === preg_match( '/^-?\d+$/', $amount ) ) {
                throw new InvalidArgumentException(
                    sprintf(
                        'MoneyCast amount "%s" exceeds the signed 64-bit range; BIGINT storage cannot represent it.',
                        $amount,
                    ),
                );
            }
        }

        throw new InvalidArgumentException(
            'MoneyCast requires an integer minor-unit amount; floats are forbidden.',
        );
    }
}
