<?php

declare( strict_types=1 );

use Illuminate\Foundation\Testing\RefreshDatabase;

uses( RefreshDatabase::class );

describe( 'shared currency column (D14)', function (): void {
    it( 'clears only the amount when another money attribute shares the currency', function (): void {
        $price = ArtisanPackUI\Ecommerce\Models\ProductPrice::factory()->create( [ 'currency' => 'USD', 'price_amount' => 1_000, 'compare_at_amount' => 1_500 ] );

        $price->compare_at = null;
        $price->save();

        expect( $price->refresh()->currency )->toBe( 'USD' )
            ->and( $price->compare_at_amount )->toBeNull()
            ->and( (int) $price->price->getAmount() )->toBe( 1_000 );
    } );

    it( 'refuses an amount in a different currency than the row', function (): void {
        $price = ArtisanPackUI\Ecommerce\Models\ProductPrice::factory()->create( [ 'currency' => 'USD', 'price_amount' => 1_000 ] );

        expect( fn () => $price->cost = Money\Money::EUR( 300 ) )->toThrow( InvalidArgumentException::class, 'shares the "currency" column' );

        $price->cost = Money\Money::USD( 300 );

        expect( (int) $price->cost->getAmount() )->toBe( 300 );
    } );
} );
