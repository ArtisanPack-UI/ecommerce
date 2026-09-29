<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Kanban\Widgets\TotalWidget;
use ArtisanPackUI\Ecommerce\Models\KanbanColumn;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Notifications\NotificationTemplateRenderer;
use ArtisanPackUI\Ecommerce\Support\LocalizedDate;
use ArtisanPackUI\Ecommerce\Support\MoneyFormatter;
use ArtisanPackUI\Ecommerce\Support\TaxLabel;
use Illuminate\Support\Carbon;
use Money\Currency;
use Money\Money;

/**
 * Collapses intl's no-break and narrow no-break spaces so expectations can
 * be written with plain spaces.
 */
function plainSpaces( string $value ): string
{
    return str_replace( [ "\u{00A0}", "\u{202F}" ], ' ', $value );
}

describe( 'MoneyFormatter', function (): void {
    it( 'never prints raw minor units for unknown currencies or locales', function (): void {
        expect( plainSpaces( MoneyFormatter::format( 123456, 'XYZ', 'en' ) ) )->toBe( 'XYZ 1,234.56' )
            ->and( plainSpaces( MoneyFormatter::format( 123456, 'EUR', 'xx' ) ) )->toBe( '€1,234.56' )
            ->and( plainSpaces( MoneyFormatter::format( 123456, 'USD', '!!bad' ) ) )->toBe( '$1,234.56' );
    } );

    it( 'puts the minus sign before the ISO code in the fallback', function (): void {
        expect( plainSpaces( MoneyFormatter::format( -123456, 'CHF', 'en' ) ) )->toBe( '-CHF 1,234.56' );
    } );

    it( 'formats with locale grouping, separator, and symbol placement', function ( string $locale, string $expected ): void {
        expect( plainSpaces( MoneyFormatter::format( 123456, 'EUR', $locale ) ) )->toBe( $expected );
    } )->with( [
        'en' => [ 'en', '€1,234.56' ],
        'es' => [ 'es', '1.234,56 €' ],
        'fr' => [ 'fr', '1 234,56 €' ],
        'de' => [ 'de', '1.234,56 €' ],
    ] );

    it( 'follows the currency\'s minor units', function (): void {
        expect( MoneyFormatter::format( 1500, 'JPY', 'en' ) )->toBe( '¥1,500' )
            ->and( MoneyFormatter::format( 1500, 'KWD', 'en' ) )->toBe( 'KWD 1.500' );
    } );

    it( 'falls back to the ISO code with locale grouping when the locale has no symbol', function ( string $locale, string $expected ): void {
        expect( MoneyFormatter::hasLocalizedSymbol( $locale, 'CHF' ) )->toBeFalse()
            ->and( plainSpaces( MoneyFormatter::format( 123456, 'CHF', $locale ) ) )->toBe( $expected );
    } )->with( [
        'en' => [ 'en', 'CHF 1,234.56' ],
        'es' => [ 'es', 'CHF 1.234,56' ],
        'fr' => [ 'fr', 'CHF 1 234,56' ],
        'de' => [ 'de', 'CHF 1.234,56' ],
    ] );

    it( 'defaults to the application locale', function (): void {
        app()->setLocale( 'de' );

        expect( MoneyFormatter::format( 5036, 'USD' ) )->toBe( MoneyFormatter::format( 5036, 'USD', 'de' ) )
            ->and( plainSpaces( MoneyFormatter::format( 5036, 'USD' ) ) )->toBe( '50,36 $' );
    } );

    it( 'formats a Money instance', function (): void {
        expect( MoneyFormatter::formatMoney( new Money( 999, new Currency( 'USD' ) ), 'en' ) )->toBe( '$9.99' );
    } );

    it( 'assumes two fraction digits for an unknown currency', function (): void {
        expect( plainSpaces( MoneyFormatter::format( 100, 'ZZZ', 'en' ) ) )->toBe( 'ZZZ 1.00' );
    } );

    it( 'formats the kanban total widget in the active locale', function (): void {
        app()->setLocale( 'fr' );

        $order = Order::factory()->make( [ 'total_amount' => 123456, 'total_currency' => 'EUR', 'currency' => 'EUR', 'total_refunded_amount' => 0 ] );

        $payload = app( TotalWidget::class )->render( $order, new KanbanColumn() );

        expect( plainSpaces( (string) $payload['value'] ) )->toBe( '1 234,56 €' );
    } );
} );

describe( 'TaxLabel', function (): void {
    it( 'uses the shipped terminology per locale', function ( string $locale, string $expected ): void {
        expect( TaxLabel::for( $locale ) )->toBe( $expected );
    } )->with( [
        'en' => [ 'en', 'Tax' ],
        'es' => [ 'es', 'IVA' ],
        'fr' => [ 'fr', 'TVA' ],
        'de' => [ 'de', 'USt.' ],
    ] );

    it( 'defaults to the application locale', function (): void {
        app()->setLocale( 'es' );

        expect( TaxLabel::for() )->toBe( 'IVA' );
    } );

    it( 'lets a store override the label per locale, including regional variants', function (): void {
        config()->set( 'artisanpack.ecommerce.localization.tax_labels', [ 'en' => 'Sales Tax', 'de' => '  ' ] );

        expect( TaxLabel::for( 'en' ) )->toBe( 'Sales Tax' )
            ->and( TaxLabel::for( 'en_US' ) )->toBe( 'Sales Tax' )
            ->and( TaxLabel::for( 'de' ) )->toBe( 'USt.' )
            ->and( TaxLabel::for( 'fr' ) )->toBe( 'TVA' );
    } );
} );

describe( 'LocalizedDate', function (): void {
    it( 'formats with translated month names in each locale\'s pattern', function ( string $locale, string $expected ): void {
        expect( LocalizedDate::format( Carbon::parse( '2026-10-29 14:03:00' ), locale: $locale ) )->toBe( $expected );
    } )->with( [
        'en' => [ 'en', 'October 29, 2026' ],
        'es' => [ 'es', '29 de octubre de 2026' ],
        'fr' => [ 'fr', '29 octobre 2026' ],
        'de' => [ 'de', '29. Oktober 2026' ],
    ] );

    it( 'accepts ISO strings and custom formats, and tolerates empty or bad input', function (): void {
        expect( LocalizedDate::format( '2026-10-29T14:03:00+00:00', 'l', 'fr' ) )->toBe( 'jeudi' )
            ->and( LocalizedDate::format( null ) )->toBe( '' )
            ->and( LocalizedDate::format( 'not a date' ) )->toBe( 'not a date' );
    } );

    it( 'is available to notification templates as the localized_date filter', function (): void {
        app()->setLocale( 'de' );

        $rendered = app( NotificationTemplateRenderer::class )->renderTemplate(
            'test',
            'Bis {{ Download.expires_at|localized_date }}',
            '<p>{{ Download.expires_at|localized_date("j. F") }}</p>',
            [ 'Download' => [ 'expires_at' => '2026-10-29T14:03:00+00:00' ] ],
            'mail',
        );

        expect( $rendered['subject'] )->toBe( 'Bis 29. Oktober 2026' )
            ->and( $rendered['body'] )->toBe( '<p>29. Oktober</p>' );
    } );
} );
