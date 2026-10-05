<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\ValueObjects\PaymentResult;
use Money\Money;

it( 'builds a successful capture via the success() convenience constructor', function (): void {
    $result = PaymentResult::success( Money::USD( 12_500 ), 'ch_abc123' );

    expect( $result->success )->toBeTrue();
    expect( $result->amount->equals( Money::USD( 12_500 ) ) )->toBeTrue();
    expect( $result->gatewayReference )->toBe( 'ch_abc123' );
    expect( $result->errorCode )->toBeNull();
    expect( $result->retryable )->toBeFalse();
} );

it( 'flags terminal failures as not retryable', function (): void {
    $result = PaymentResult::terminalFailure( Money::USD( 500 ), 'card_declined', 'The card was declined.' );

    expect( $result->success )->toBeFalse();
    expect( $result->retryable )->toBeFalse();
    expect( $result->errorCode )->toBe( 'card_declined' );
    expect( $result->errorMessage )->toBe( 'The card was declined.' );
} );

it( 'flags retryable failures as retryable', function (): void {
    $result = PaymentResult::retryableFailure( Money::USD( 500 ), 'provider_unavailable' );

    expect( $result->success )->toBeFalse();
    expect( $result->retryable )->toBeTrue();
    expect( $result->errorCode )->toBe( 'provider_unavailable' );
} );
