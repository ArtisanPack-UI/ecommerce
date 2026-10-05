<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Contracts\TaxProvider;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\TaxRate;
use ArtisanPackUI\Ecommerce\Registries\TaxProviderRegistry;
use ArtisanPackUI\Ecommerce\Services\TaxService;
use ArtisanPackUI\Ecommerce\Tax\ManualTaxProvider;
use ArtisanPackUI\Ecommerce\ValueObjects\Address;
use ArtisanPackUI\Ecommerce\ValueObjects\TaxContext;
use ArtisanPackUI\Ecommerce\ValueObjects\TaxResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Money\Currency;
use Money\Money;

uses( RefreshDatabase::class );

/**
 * Builds a stub provider that always charges a flat amount.
 */
function flatTaxProvider( string $key, int $amount, string $currency = 'USD' ): TaxProvider
{
    return new class ( $key, $amount, $currency ) implements TaxProvider {
        public function __construct( private string $key, private int $amount, private string $currency )
        {
        }

        public function key(): string
        {
            return $this->key;
        }

        public function label(): string
        {
            return 'Flat';
        }

        public function calculate( Cart $cart, Address $destination ): TaxResult
        {
            $money = new Money( $this->amount, new Currency( $this->currency ) );

            return new TaxResult( $money, [], [], new Money( 0, new Currency( $this->currency ) ) );
        }
    };
}

beforeEach( function (): void {
    $this->address = new Address( address1: '1 Main', city: 'Chicago', countryCode: 'US' );
} );

it( 'registers the manual provider and uses it by default', function (): void {
    TaxRate::factory()->create( [ 'rate_ubps' => 100_000_000 ] );

    $service = app( TaxService::class );

    expect( $service->activeProvider() )->toBeInstanceOf( ManualTaxProvider::class );
    expect( (int) $service->calculate( cartWithLines( [ [ 'unit' => 1_000 ] ] ), $this->address )->total->getAmount() )->toBe( 100 );
} );

it( 're-binds to a satellite provider through config', function (): void {
    app( TaxProviderRegistry::class )->register( 'stripe-tax', flatTaxProvider( 'stripe-tax', 42 ) );
    config()->set( 'artisanpack.ecommerce.tax.provider', 'stripe-tax' );

    $result = app( TaxService::class )->calculate( cartWithLines( [ [ 'unit' => 1_000 ] ] ), $this->address );

    expect( (int) $result->total->getAmount() )->toBe( 42 );
} );

it( 'lets ap.ecommerce.tax.calculating swap the provider and ap.ecommerce.tax.calculated rewrite the result', function (): void {
    app( TaxProviderRegistry::class )->register( 'alt', flatTaxProvider( 'alt', 7 ) );

    addFilter( 'ap.ecommerce.tax.calculating', fn ( TaxContext $context ) => new TaxContext( $context->destination, 'alt', false ) );
    addFilter( 'ap.ecommerce.tax.calculated', fn ( TaxResult $result ) => new TaxResult(
        $result->total->multiply( 2 ),
        $result->breakdown,
        $result->perLine,
        $result->shipping,
    ) );

    $result = app( TaxService::class )->calculate( cartWithLines( [ [ 'unit' => 1_000 ] ] ), $this->address );

    expect( (int) $result->total->getAmount() )->toBe( 14 );
} );

it( 'refuses a provider result in the wrong currency', function (): void {
    app( TaxProviderRegistry::class )->register( 'eur-only', flatTaxProvider( 'eur-only', 1, 'EUR' ) );
    config()->set( 'artisanpack.ecommerce.tax.provider', 'eur-only' );

    app( TaxService::class )->calculate( cartWithLines( [ [ 'unit' => 1_000 ] ] ), $this->address );
} )->throws( UnexpectedValueException::class );

it( 'throws on double registration in testing', function (): void {
    app( TaxProviderRegistry::class )->register( 'manual', ManualTaxProvider::class );
} )->throws( InvalidArgumentException::class );

it( 'rejects entries that do not implement TaxProvider', function (): void {
    app( TaxProviderRegistry::class )->register( 'bogus', stdClass::class );
} )->throws( InvalidArgumentException::class );

it( 'passes the result and the cart to ap.ecommerce.tax.calculated', function (): void {
    app( TaxProviderRegistry::class )->register( 'flat', flatTaxProvider( 'flat', 9 ) );
    config()->set( 'artisanpack.ecommerce.tax.provider', 'flat' );

    $cart     = cartWithLines( [ [ 'unit' => 1_000 ] ] );
    $received = null;

    addFilter( 'ap.ecommerce.tax.calculated', function ( TaxResult $result, Cart $filtered ) use ( &$received ): TaxResult {
        $received = [ (int) $result->total->getAmount(), $filtered->id ];

        return $result;
    } );

    app( TaxService::class )->calculate( $cart, $this->address );

    expect( $received )->toBe( [ 9, $cart->id ] );
} );
