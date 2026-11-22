<?php

/**
 * PriceQuote value object.
 *
 * The price of a product with a set of add-to-cart options (#224), from
 * {@see \ArtisanPackUI\Ecommerce\Services\PriceQuoteService::quote()}: the
 * unit price, the line total for the quantity, an optional breakdown the
 * product type supplies ({@see \ArtisanPackUI\Ecommerce\Contracts\QuotesPrice}),
 * and the validation errors (keyed `options.*`) when the options were
 * rejected — in which case both prices are zero. List price: promotions
 * are not applied.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.4.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\ValueObjects;

use ArtisanPackUI\Ecommerce\Support\MoneyFormatter;
use Money\Money;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.4.0
 */
final readonly class PriceQuote
{
    /**
     * @since 1.4.0
     *
     * @param  Money                                             $unitPrice  Price of one unit.
     * @param  Money                                             $lineTotal  Price of `$quantity` units.
     * @param  int                                               $quantity   Units quoted.
     * @param  array<int, array{label: string, amount: Money}>  $breakdown  Rows the product type supplied.
     * @param  array<string, array<int, string>>                 $errors     Validation errors, keyed `options.*`.
     */
    public function __construct(
        private Money $unitPrice,
        private Money $lineTotal,
        private int $quantity,
        private array $breakdown = [],
        private array $errors = [],
    ) {
    }

    /**
     * A rejected quote: zero prices in `$currency` and the errors.
     *
     * @since 1.4.0
     *
     * @param  string                             $currency  ISO 4217 code.
     * @param  int                                $quantity  Units requested.
     * @param  array<string, array<int, string>>  $errors    Validation errors.
     *
     * @return self
     */
    public static function invalid( string $currency, int $quantity, array $errors ): self
    {
        $zero = Money::{strtoupper( $currency )}( 0 );

        return new self( $zero, $zero, $quantity, [], $errors );
    }

    /**
     * @since 1.4.0
     *
     * @return Money
     */
    public function unitPrice(): Money
    {
        return $this->unitPrice;
    }

    /**
     * @since 1.4.0
     *
     * @return Money
     */
    public function lineTotal(): Money
    {
        return $this->lineTotal;
    }

    /**
     * @since 1.4.0
     *
     * @return string ISO 4217 code.
     */
    public function currency(): string
    {
        return $this->unitPrice->getCurrency()->getCode();
    }

    /**
     * @since 1.4.0
     *
     * @return int
     */
    public function quantity(): int
    {
        return $this->quantity;
    }

    /**
     * @since 1.4.0
     *
     * @return array<int, array{label: string, amount: Money}>
     */
    public function breakdown(): array
    {
        return $this->breakdown;
    }

    /**
     * @since 1.4.0
     *
     * @return array<string, array<int, string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Whether the options were accepted and priced.
     *
     * @since 1.4.0
     *
     * @return bool
     */
    public function isValid(): bool
    {
        return [] === $this->errors;
    }

    /**
     * The API shape. Money is `{ amount, currency, formatted }`: minor
     * units, ISO 4217 code, and a display string in the current locale.
     *
     * @since 1.4.0
     *
     * @return array{currency: string, quantity: int, unit_price: array{amount: int, currency: string, formatted: string}, line_total: array{amount: int, currency: string, formatted: string}, breakdown: array<int, array{label: string, amount: array{amount: int, currency: string, formatted: string}}>, errors: array<string, array<int, string>>, valid: bool}
     */
    public function toArray(): array
    {
        return [
            'currency'   => $this->currency(),
            'quantity'   => $this->quantity,
            'unit_price' => self::money( $this->unitPrice ),
            'line_total' => self::money( $this->lineTotal ),
            'breakdown'  => array_map( static fn ( array $row ): array => [ 'label' => $row['label'], 'amount' => self::money( $row['amount'] ) ], $this->breakdown ),
            'errors'     => $this->errors,
            'valid'      => $this->isValid(),
        ];
    }

    /**
     * Money in the API shape.
     *
     * @since 1.4.0
     *
     * @param  Money  $money  Amount.
     *
     * @return array{amount: int, currency: string, formatted: string}
     */
    private static function money( Money $money ): array
    {
        $currency = $money->getCurrency()->getCode();

        return [
            'amount'    => (int) $money->getAmount(),
            'currency'  => $currency,
            'formatted' => MoneyFormatter::format( (int) $money->getAmount(), $currency ),
        ];
    }
}
