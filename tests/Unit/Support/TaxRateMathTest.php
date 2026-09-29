<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Support\TaxRateMath;

it( 'converts percentages to rate_ubps using the spec worked examples', function ( string $percent, int $ubps ): void {
    expect( TaxRateMath::fromPercent( $percent ) )->toBe( $ubps );
    expect( TaxRateMath::toPercent( $ubps ) )->toBe( $percent );
} )->with( [
    'Cook County 8.375%' => [ '8.375', 83_750_000 ],
    '8.75%'              => [ '8.75', 87_500_000 ],
    'UK VAT 20%'         => [ '20', 200_000_000 ],
    'Québec QST 9.975%'  => [ '9.975', 99_750_000 ],
    'zero'               => [ '0', 0 ],
] );

it( 'renders the fractional rate as a bcmath decimal string with no float drift', function (): void {
    expect( TaxRateMath::toFraction( 83_750_000 ) )->toBe( '0.083750000000000000' );
    expect( TaxRateMath::onePlus( 200_000_000 ) )->toBe( '1.200000000000000000' );
} );

it( 'rejects percentages that are not plain decimals or exceed stored precision', function ( string $percent ): void {
    TaxRateMath::fromPercent( $percent );
} )->with( [ 'abc', '1e2', '8.12345678' ] )->throws( InvalidArgumentException::class );
