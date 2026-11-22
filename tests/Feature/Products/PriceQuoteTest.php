<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Contracts\QuotesPrice;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\InventoryReservation;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductPrice;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use ArtisanPackUI\Ecommerce\Models\Promotion;
use ArtisanPackUI\Ecommerce\Models\PromotionAction;
use ArtisanPackUI\Ecommerce\ProductTypes\SimpleProductType;
use ArtisanPackUI\Ecommerce\Registries\ProductTypeRegistry;
use ArtisanPackUI\Ecommerce\Services\PriceQuoteService;
use ArtisanPackUI\Ecommerce\Support\MoneyFormatter;
use ArtisanPackUI\Ecommerce\ValueObjects\PriceQuote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Money\Money;

require_once __DIR__ . '/../GraphQL/GraphQLTestHelpers.php';

uses( RefreshDatabase::class );

/**
 * A configurable print: base price by size plus a frame surcharge, with a
 * breakdown.
 */
class QuoteConfigurablePrintType extends SimpleProductType implements QuotesPrice
{
    /** @var array<string, int> */
    public const SIZES = [ '8x10' => 6_000, '16x20' => 12_000 ];

    /** @var array<string, int> */
    public const FRAMES = [ 'none' => 0, 'oak' => 6_500 ];

    public function key(): string
    {
        return 'quote-configurable-print';
    }

    public function validateCartOptions( Product $product, array $options ): array
    {
        $errors = [];

        if ( ! isset( self::SIZES[ $options['size'] ?? '' ] ) ) {
            $errors['size'] = [ 'Choose a size.' ];
        }

        if ( ! isset( self::FRAMES[ $options['frame'] ?? '' ] ) ) {
            $errors['options.frame'] = [ 'Choose a frame.' ];
        }

        if ( [] !== $errors ) {
            throw ValidationException::withMessages( $errors );
        }

        return [ 'size' => $options['size'], 'frame' => $options['frame'] ];
    }

    public function priceLine( Product $product, array $options, int $quantity, string $currency ): Money
    {
        return Money::{$currency}( ( self::SIZES[ $options['size'] ] + self::FRAMES[ $options['frame'] ] ) * $quantity );
    }

    public function quoteBreakdown( Product $product, array $options, int $quantity, string $currency ): array
    {
        return [
            [ 'label' => 'Print (' . $options['size'] . ')', 'amount' => Money::{$currency}( self::SIZES[ $options['size'] ] ) ],
            [ 'label' => 'Frame', 'amount' => Money::{$currency}( self::FRAMES[ $options['frame'] ] ) ],
            [ 'label' => 'Wrong currency', 'amount' => Money::EUR( 1 ) ],
            [ 'label' => 'Not money', 'amount' => 5 ],
        ];
    }
}

beforeEach( function (): void {
    app( ProductTypeRegistry::class )->register( 'quote-configurable-print', QuoteConfigurablePrintType::class );

    $this->quotes = app( PriceQuoteService::class );
} );

function quotedProduct( string $type = 'simple', int $amount = 2_500 ): Product
{
    $product = Product::factory()->create( [ 'type' => $type ] );
    ProductPrice::factory()->forPriceable( $product )->create( [ 'currency' => 'USD', 'price_amount' => $amount ] );

    return $product;
}

it( 'quotes a simple product at its list price, ignoring promotions', function (): void {
    $product   = quotedProduct();
    $promotion = Promotion::factory()->create( [ 'source_type' => 'automatic' ] );
    PromotionAction::factory()->create( [ 'promotion_id' => $promotion->id, 'type' => 'percent-off-cart', 'config' => [ 'percent' => 50 ] ] );

    $quote = $this->quotes->quote( $product, [], 3, 'usd' );

    expect( $quote )->toBeInstanceOf( PriceQuote::class )
        ->and( $quote->isValid() )->toBeTrue()
        ->and( $quote->currency() )->toBe( 'USD' )
        ->and( $quote->quantity() )->toBe( 3 )
        ->and( $quote->unitPrice()->getAmount() )->toBe( '2500' )
        ->and( $quote->lineTotal()->getAmount() )->toBe( '7500' )
        ->and( $quote->breakdown() )->toBe( [] )
        ->and( $quote->errors() )->toBe( [] );
} );

it( 'quotes a variable product at the chosen variant\'s price', function (): void {
    $product = Product::factory()->create( [ 'type' => 'variable' ] );
    $small   = ProductVariant::factory()->for( $product )->create();
    $large   = ProductVariant::factory()->for( $product )->create();
    ProductPrice::factory()->forPriceable( $small )->create( [ 'currency' => 'USD', 'price_amount' => 1_000 ] );
    ProductPrice::factory()->forPriceable( $large )->create( [ 'currency' => 'USD', 'price_amount' => 1_800 ] );

    $quote = $this->quotes->quote( $product, [ 'variant_id' => $large->id ], 2, 'USD' );

    expect( $quote->unitPrice()->getAmount() )->toBe( '1800' )
        ->and( $quote->lineTotal()->getAmount() )->toBe( '3600' );

    // No variant: the type rejects the options.
    expect( $this->quotes->quote( $product, [], 1, 'USD' )->errors() )->toHaveKey( 'options' );

    // Another product's variant.
    $foreign = ProductVariant::factory()->create();

    expect( $this->quotes->quote( $product, [ 'variant_id' => $foreign->id ], 1, 'USD' )->errors() )->toHaveKey( 'options.variant_id' );
} );

it( 'adds the breakdown from a type implementing QuotesPrice, dropping malformed rows', function (): void {
    $product = quotedProduct( 'quote-configurable-print' );

    $quote = $this->quotes->quote( $product, [ 'size' => '16x20', 'frame' => 'oak' ], 1, 'USD' );

    expect( $quote->unitPrice()->getAmount() )->toBe( '18500' )
        ->and( array_column( $quote->breakdown(), 'label' ) )->toBe( [ 'Print (16x20)', 'Frame' ] )
        ->and( $quote->toArray() )->toMatchArray( [
            'currency'   => 'USD',
            'quantity'   => 1,
            'unit_price' => [ 'amount' => 18_500, 'currency' => 'USD', 'formatted' => MoneyFormatter::format( 18_500, 'USD' ) ],
            'valid'      => true,
            'errors'     => [],
        ] )
        ->and( $quote->toArray()['breakdown'][1] )->toBe( [ 'label' => 'Frame', 'amount' => [ 'amount' => 6_500, 'currency' => 'USD', 'formatted' => MoneyFormatter::format( 6_500, 'USD' ) ] ] );
} );

it( 'returns errors keyed options.* and zero prices for invalid options', function (): void {
    $product = quotedProduct( 'quote-configurable-print' );

    $quote = $this->quotes->quote( $product, [ 'size' => 'huge' ], 2, 'USD' );

    expect( $quote->isValid() )->toBeFalse()
        ->and( array_keys( $quote->errors() ) )->toBe( [ 'options.size', 'options.frame' ] )
        ->and( $quote->unitPrice()->getAmount() )->toBe( '0' )
        ->and( $quote->lineTotal()->getAmount() )->toBe( '0' )
        ->and( $quote->currency() )->toBe( 'USD' );
} );

it( 'reports a missing price, a bad currency, and a bad quantity', function (): void {
    $product = quotedProduct();

    expect( $this->quotes->quote( $product, [], 1, 'EUR' )->errors() )->toHaveKey( 'currency' )
        ->and( $this->quotes->quote( $product, [], 1, 'dollars' )->errors() )->toHaveKey( 'currency' )
        ->and( $this->quotes->quote( $product, [], 0, 'USD' )->errors() )->toHaveKey( 'quantity' );
} );

it( 'has no side effects', function (): void {
    $product = quotedProduct();
    $before  = [ Cart::query()->count(), InventoryReservation::query()->count() ];

    $this->quotes->quote( $product, [], 2, 'USD' );

    expect( [ Cart::query()->count(), InventoryReservation::query()->count() ] )->toBe( $before );
} );

it( 'serves quotes over REST: 200, 422 with options.* errors, 404 when not on sale', function (): void {
    $product = quotedProduct( 'quote-configurable-print' );

    $this->postJson( "/api/ecommerce/v1/products/{$product->id}/quote", [ 'options' => [ 'size' => '16x20', 'frame' => 'oak' ], 'quantity' => 2, 'currency' => 'usd' ] )
        ->assertOk()
        ->assertJsonPath( 'data.currency', 'USD' )
        ->assertJsonPath( 'data.unit_price.amount', 18_500 )
        ->assertJsonPath( 'data.line_total.amount', 37_000 )
        ->assertJsonPath( 'data.breakdown.0.label', 'Print (16x20)' )
        ->assertJsonPath( 'data.valid', true );

    $errors = $this->postJson( "/api/ecommerce/v1/products/{$product->id}/quote", [ 'options' => [ 'size' => '16x20' ] ] )
        ->assertStatus( 422 )
        ->json( 'errors' );

    expect( array_column( $errors, 'field' ) )->toBe( [ 'options.frame' ] );

    $this->postJson( "/api/ecommerce/v1/products/{$product->id}/quote", [ 'quantity' => 0 ] )->assertStatus( 422 );

    $draft = Product::factory()->create( [ 'status' => 'draft' ] );

    $this->postJson( "/api/ecommerce/v1/products/{$draft->id}/quote", [] )->assertNotFound();
    $this->postJson( '/api/ecommerce/v1/products/999999/quote', [] )->assertNotFound();
} );

it( 'is rate-limited by the catalog policy and needs no Idempotency-Key', function (): void {
    $route = app( 'router' )->getRoutes()->getByName( 'ecommerce.api.products.quote' );

    expect( $route->gatherMiddleware() )->toContain( 'ecommerce.rate-limit:ecommerce.catalog.read' )
        ->and( $route->gatherMiddleware() )->not->toContain( 'ecommerce.idempotency' );
} );

it( 'serves quotes over GraphQL', function (): void {
    $product = quotedProduct( 'quote-configurable-print' );
    $query   = 'query ($id: ID!, $options: JSON) { productQuote(product: $id, options: $options, quantity: 2, currency: "USD") { currency quantity unit_price { amount formatted } line_total { amount } breakdown { label amount { amount } } valid errors { field message } } }';

    gql( $this, $query, [ 'id' => $product->id, 'options' => [ 'size' => '8x10', 'frame' => 'none' ] ] )
        ->assertJsonPath( 'data.productQuote.unit_price.amount', 6_000 )
        ->assertJsonPath( 'data.productQuote.line_total.amount', 12_000 )
        ->assertJsonPath( 'data.productQuote.breakdown.0.label', 'Print (8x10)' )
        ->assertJsonPath( 'data.productQuote.valid', true );

    gql( $this, $query, [ 'id' => $product->id, 'options' => [ 'size' => '8x10' ] ] )
        ->assertJsonPath( 'data.productQuote.valid', false )
        ->assertJsonPath( 'data.productQuote.errors.0.field', 'options.frame' );

    $draft = Product::factory()->create( [ 'status' => 'draft' ] );

    gql( $this, $query, [ 'id' => $draft->id, 'options' => [] ] )->assertJsonPath( 'data.productQuote', null );
} );

it( 'refuses oversized or nested option payloads like the cart does', function ( array $options ): void {
    $product = quotedProduct( 'quote-configurable-print' );

    $this->postJson( "/api/ecommerce/v1/products/{$product->id}/quote", [ 'options' => $options ] )
        ->assertStatus( 422 );

    $query = 'query ($id: ID!, $options: JSON) { productQuote(product: $id, options: $options) { valid } }';

    expect( gql( $this, $query, [ 'id' => $product->id, 'options' => $options ] )->json( 'errors.0.extensions.code' ) )->toBe( 'BAD_USER_INPUT' );
} )->with( [
    'too many options' => [ array_fill_keys( array_map( static fn ( int $i ): string => "o{$i}", range( 1, 21 ) ), 'x' ) ],
    'a nested value'   => [ [ 'size' => [ 'deep' => [ 'deeper' => 'x' ] ] ] ],
    'a long value'     => [ [ 'size' => str_repeat( 'x', 256 ) ] ],
] );
