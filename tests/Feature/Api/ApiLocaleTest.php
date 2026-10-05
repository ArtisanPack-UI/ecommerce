<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Fixtures\ApiUser;

require_once __DIR__ . '/ApiTestHelpers.php';

uses( RefreshDatabase::class );

/**
 * A new guest cart's token, created in `$language` when given.
 */
function localeCartToken( $test, ?string $language = null ): string
{
    $headers = idem() + ( null === $language ? [] : [ 'Accept-Language' => $language ] );

    return $test->postJson( '/api/ecommerce/v1/carts', [ 'currency' => 'usd' ], $headers )->assertCreated()->json( 'data.token' );
}

it( 'answers in the negotiated language', function (): void {
    $token = localeCartToken( $this );

    $english = $this->postJson( "/api/ecommerce/v1/carts/{$token}/coupons", [ 'code' => 'NOPE-NOPE' ], idem() )->assertStatus( 422 );
    $german  = $this->postJson( "/api/ecommerce/v1/carts/{$token}/coupons", [ 'code' => 'NOPE-NOPE' ], idem() + [ 'Accept-Language' => 'de-DE,de;q=0.9,en;q=0.5' ] )->assertStatus( 422 );

    expect( $german->json( 'detail' ) )->toBe( __( (string) $english->json( 'detail' ), [], 'de' ) )
        ->and( $german->json( 'detail' ) )->not->toBe( $english->json( 'detail' ) )
        ->and( $german->headers->get( 'Content-Language' ) )->toBe( 'de' )
        ->and( $german->headers->get( 'Vary' ) )->toContain( 'Accept-Language' )
        ->and( app()->getLocale() )->toBe( 'en' );
} );

it( 'keeps the app locale when no Accept-Language is sent, and ignores unsupported languages', function (): void {
    $this->getJson( '/api/ecommerce/v1/products' )->assertOk()->assertHeader( 'Content-Language', 'en' );

    $this->getJson( '/api/ecommerce/v1/products', [ 'Accept-Language' => 'ja' ] )
        ->assertOk()
        ->assertHeader( 'Content-Language', 'en' );
} );

it( 'records the negotiated language on new carts', function (): void {
    $token = localeCartToken( $this, 'fr-CA, fr;q=0.8' );

    expect( Cart::query()->where( 'token', $token )->value( 'locale' ) )->toBe( 'fr' );
} );

it( 'lets shoppers choose their notification language', function (): void {
    $customer = Customer::factory()->create( [ 'email' => 'ada@example.test' ] );
    Sanctum::actingAs( ApiUser::make( 7, 'ada@example.test' ), [ 'ecommerce:storefront' ] );
    $customer->forceFill( [ 'user_id' => 7 ] )->save();

    $this->patchJson( '/api/ecommerce/v1/me', [ 'locale' => 'es' ], idem() )->assertOk()->assertJsonPath( 'data.locale', 'es' );
    $this->patchJson( '/api/ecommerce/v1/me', [ 'locale' => 'xx' ], idem() )->assertStatus( 422 );

    expect( $customer->refresh()->preferredLocale() )->toBe( 'es' );
} );
