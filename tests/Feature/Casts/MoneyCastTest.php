<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Money\Currency;
use Money\Money;

/**
 * Fixture model that exercises MoneyCast against a real sqlite row.
 *
 * @property Money|null $subtotal
 * @property Money|null $refund
 */
final class MoneyCastFixture extends Model
{
    public $timestamps = false;

    protected $table = 'money_cast_fixtures';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'subtotal' => MoneyCast::class,
            'refund'   => MoneyCast::class,
        ];
    }
}

beforeEach( function (): void {
    Schema::create( 'money_cast_fixtures', function ( $table ): void {
        $table->id();
        $table->bigInteger( 'subtotal_amount' )->nullable();
        $table->char( 'subtotal_currency', 3 )->nullable();
        $table->bigInteger( 'refund_amount' )->nullable();
        $table->char( 'refund_currency', 3 )->nullable();
    } );
} );

it( 'persists and rehydrates a Money value through an Eloquent model', function (): void {
    $row = MoneyCastFixture::create( [
        'subtotal' => new Money( 12_345, new Currency( 'USD' ) ),
    ] );

    $fresh = MoneyCastFixture::find( $row->getKey() );

    expect( $fresh->subtotal )->toBeInstanceOf( Money::class );
    expect( $fresh->subtotal->getAmount() )->toBe( '12345' );
    expect( $fresh->subtotal->getCurrency()->getCode() )->toBe( 'USD' );
    expect( $fresh->getAttributes()[ 'subtotal_amount' ] )->toBe( 12_345 );
    expect( $fresh->getAttributes()[ 'subtotal_currency' ] )->toBe( 'USD' );
} );

it( 'persists signed negative amounts', function (): void {
    $row = MoneyCastFixture::create( [
        'refund' => new Money( -2_500, new Currency( 'USD' ) ),
    ] );

    $fresh = MoneyCastFixture::find( $row->getKey() );

    expect( $fresh->refund->getAmount() )->toBe( '-2500' );
    expect( $fresh->refund->isNegative() )->toBeTrue();
} );

it( 'round-trips a fluent Money chain through the database', function (): void {
    $base    = new Money( 199, new Currency( 'USD' ) );
    $doubled = $base->multiply( 2 )->add( new Money( 1, new Currency( 'USD' ) ) );

    $row = MoneyCastFixture::create( [ 'subtotal' => $doubled ] );

    $fresh = MoneyCastFixture::find( $row->getKey() );

    expect( $fresh->subtotal->equals( $doubled ) )->toBeTrue();
    expect( $fresh->subtotal->getAmount() )->toBe( '399' );
} );

it( 'clears both paired columns when writing null', function (): void {
    $row = MoneyCastFixture::create( [
        'subtotal' => new Money( 100, new Currency( 'USD' ) ),
    ] );

    $row->subtotal = null;
    $row->save();

    $fresh = MoneyCastFixture::find( $row->getKey() );

    expect( $fresh->subtotal )->toBeNull();
    expect( $fresh->getAttributes()[ 'subtotal_amount' ] )->toBeNull();
    expect( $fresh->getAttributes()[ 'subtotal_currency' ] )->toBeNull();
} );
