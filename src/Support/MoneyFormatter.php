<?php

/**
 * MoneyFormatter.
 *
 * Locale-aware display formatting for minor-unit amounts (parent plan
 * §16.5). Built on PHP's `NumberFormatter` (the `intl` extension is a hard
 * requirement of the engine).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Support;

use Locale;
use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Money;
use NumberFormatter;
use Throwable;

/**
 * Formats money for display in the active (or a given) locale.
 *
 * Grouping, decimal separator, and symbol placement follow the locale:
 * `1234.56 EUR` renders as `€1,234.56` in `en`, `1234,56 €` in `es`,
 * `1 234,56 €` in `fr`, and `1.234,56 €` in `de`. When the locale has no
 * localized symbol for the currency (intl falls back to the bare ISO code),
 * the amount renders as `CODE 1,234.56` with the locale's own grouping so
 * the code never collides with the digits.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class MoneyFormatter
{
    /**
     * Formats `$amount` minor units of `$currency`.
     *
     * @since 1.0.0
     *
     * @param  int          $amount    Minor units (e.g. cents).
     * @param  string       $currency  ISO 4217 code.
     * @param  string|null  $locale    Locale; defaults to the application locale.
     *
     * @return string
     */
    public static function format( int $amount, string $currency, ?string $locale = null ): string
    {
        $currency = strtoupper( $currency );

        try {
            return self::formatMoney( new Money( $amount, new Currency( $currency ) ), $locale );
        } catch ( Throwable ) {
            // Last resort — never print raw minor units.
            return ( $amount < 0 ? '-' : '' ) . $currency . ' ' . number_format( abs( $amount ) / 100, 2 );
        }
    }

    /**
     * Formats a {@see Money} value.
     *
     * @since 1.0.0
     *
     * @param  Money        $money   Amount to format.
     * @param  string|null  $locale  Locale; defaults to the application locale.
     *
     * @return string
     */
    public static function formatMoney( Money $money, ?string $locale = null ): string
    {
        $locale  = self::usableLocale( $locale ?? self::appLocale() );
        $code    = $money->getCurrency()->getCode();
        $subunit = self::subunitFor( $money );
        $decimal = (float) $money->getAmount() / ( 10 ** $subunit );

        if ( ! self::hasLocalizedSymbol( $locale, $code ) ) {
            return self::isoFallback( $decimal, $subunit, $code, $locale );
        }

        $formatter = new NumberFormatter( $locale, NumberFormatter::CURRENCY );
        $formatter->setAttribute( NumberFormatter::MIN_FRACTION_DIGITS, $subunit );
        $formatter->setAttribute( NumberFormatter::MAX_FRACTION_DIGITS, $subunit );

        $formatted = $formatter->formatCurrency( $decimal, $code );

        return false === $formatted ? self::isoFallback( $decimal, $subunit, $code, $locale ) : $formatted;
    }

    /**
     * Whether intl knows a symbol for `$code` in `$locale` other than the
     * ISO code itself.
     *
     * @since 1.0.0
     *
     * @param  string  $locale  Locale.
     * @param  string  $code    ISO 4217 code.
     *
     * @return bool
     */
    public static function hasLocalizedSymbol( string $locale, string $code ): bool
    {
        $formatter = new NumberFormatter( $locale . '@currency=' . $code, NumberFormatter::CURRENCY );
        $symbol    = $formatter->getSymbol( NumberFormatter::CURRENCY_SYMBOL );

        return '' !== $symbol && $code !== $symbol && '¤' !== $symbol;
    }

    /**
     * `CODE 1.234,56` with the locale's grouping; the sign leads the code
     * (`-CHF 1,234.56`), as intl places it before the symbol.
     *
     * @since 1.0.0
     *
     * @param  float   $decimal  Major-unit amount.
     * @param  int     $subunit  Fraction digits.
     * @param  string  $code     ISO 4217 code.
     * @param  string  $locale   Locale.
     *
     * @return string
     */
    private static function isoFallback( float $decimal, int $subunit, string $code, string $locale ): string
    {
        return ( $decimal < 0 ? '-' : '' ) . $code . ' ' . self::formatDecimal( abs( $decimal ), $subunit, $locale );
    }

    /**
     * Fraction digits for the currency; 2 for codes ISO 4217 doesn't know
     * (so an unknown code still prints major units, never raw minor units).
     *
     * @since 1.0.0
     *
     * @param  Money  $money  Amount.
     *
     * @return int
     */
    private static function subunitFor( Money $money ): int
    {
        try {
            return ( new ISOCurrencies() )->subunitFor( $money->getCurrency() );
        } catch ( Throwable ) {
            return 2;
        }
    }

    /**
     * `$locale` when ICU has data for its language, otherwise `en`.
     * Depending on the ICU build, an unknown locale either throws or quietly
     * resolves to another locale (`root`, or the process default such as
     * `en_US_POSIX`), whose number pattern also varies; a resolved language
     * other than the one asked for means "unknown" here.
     *
     * @since 1.0.0
     *
     * @param  string  $locale  Requested locale.
     *
     * @return string
     */
    private static function usableLocale( string $locale ): string
    {
        if ( '' === $locale ) {
            return 'en';
        }

        try {
            $formatter = new NumberFormatter( $locale, NumberFormatter::DECIMAL );
        } catch ( Throwable ) {
            return 'en';
        }

        $resolved = (string) $formatter->getLocale( Locale::VALID_LOCALE );
        $wanted   = strtolower( (string) Locale::getPrimaryLanguage( $locale ) );

        if ( '' === $resolved || 'root' === $resolved || '' === $wanted || strtolower( (string) Locale::getPrimaryLanguage( $resolved ) ) !== $wanted ) {
            return 'en';
        }

        return false === $formatter->format( 1 ) ? 'en' : $locale;
    }

    /**
     * Formats a plain decimal with the locale's grouping and separator.
     *
     * @since 1.0.0
     *
     * @param  float   $decimal   Major-unit amount.
     * @param  int     $subunit   Fraction digits.
     * @param  string  $locale    Locale.
     *
     * @return string
     */
    private static function formatDecimal( float $decimal, int $subunit, string $locale ): string
    {
        $formatter = new NumberFormatter( $locale, NumberFormatter::DECIMAL );
        $formatter->setAttribute( NumberFormatter::MIN_FRACTION_DIGITS, $subunit );
        $formatter->setAttribute( NumberFormatter::MAX_FRACTION_DIGITS, $subunit );

        return (string) $formatter->format( $decimal );
    }

    /**
     * The application locale, or `en` outside a booted application.
     *
     * @since 1.0.0
     *
     * @return string
     */
    private static function appLocale(): string
    {
        return function_exists( 'app' ) && app()->bound( 'translator' ) ? (string) app()->getLocale() : 'en';
    }
}
