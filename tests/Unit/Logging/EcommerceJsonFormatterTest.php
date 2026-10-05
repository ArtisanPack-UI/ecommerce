<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Logging\EcommerceJsonFormatter;
use Monolog\Level;
use Monolog\LogRecord;

function makeRecord( string $message, array $context = [], array $extra = [], Level $level = Level::Info ): LogRecord
{
    return new LogRecord(
        datetime: new DateTimeImmutable( '2026-01-02T03:04:05.678900+00:00' ),
        channel: 'ecommerce',
        level: $level,
        message: $message,
        context: $context,
        extra: $extra,
    );
}

it( 'emits a JSON line terminated by a newline', function (): void {
    $formatter = new EcommerceJsonFormatter();

    $line = $formatter->format( makeRecord( 'hello' ) );

    expect( $line )->toEndWith( "\n" );
    $payload = json_decode( trim( $line ), true, flags: JSON_THROW_ON_ERROR );
    expect( $payload )->toBeArray();
} );

it( 'includes the canonical top-level fields', function (): void {
    $formatter = new EcommerceJsonFormatter();

    $line    = $formatter->format( makeRecord( 'hello' ) );
    $payload = json_decode( trim( $line ), true, flags: JSON_THROW_ON_ERROR );

    expect( $payload['timestamp'] )->toBe( '2026-01-02T03:04:05.678900+00:00' );
    expect( $payload['level'] )->toBe( 'info' );
    expect( $payload['channel'] )->toBe( 'ecommerce' );
    expect( $payload['message'] )->toBe( 'hello' );
} );

it( 'promotes recognised context keys to top-level fields', function (): void {
    $formatter = new EcommerceJsonFormatter();

    $line = $formatter->format( makeRecord( 'order placed', [
        'event'       => 'order.placed',
        'request_id'  => 'req-abc',
        'actor_scope' => 'customer',
        'actor_id'    => 42,
        'order_id'    => 7,
    ] ) );

    $payload = json_decode( trim( $line ), true, flags: JSON_THROW_ON_ERROR );

    expect( $payload['event'] )->toBe( 'order.placed' );
    expect( $payload['request_id'] )->toBe( 'req-abc' );
    expect( $payload['actor_scope'] )->toBe( 'customer' );
    expect( $payload['actor_id'] )->toBe( 42 );
    expect( $payload['order_id'] )->toBe( 7 );
    expect( array_key_exists( 'context', $payload ) )->toBeFalse();
} );

it( 'keeps unrecognised context keys nested under a `context` object', function (): void {
    $formatter = new EcommerceJsonFormatter();

    $line = $formatter->format( makeRecord( 'x', [
        'order_id' => 1,
        'notes'    => 'something extra',
    ] ) );

    $payload = json_decode( trim( $line ), true, flags: JSON_THROW_ON_ERROR );

    expect( $payload['order_id'] )->toBe( 1 );
    expect( $payload['context'] )->toBe( [ 'notes' => 'something extra' ] );
} );

it( 'omits the `context` and `extra` keys when both are empty', function (): void {
    $formatter = new EcommerceJsonFormatter();

    $line    = $formatter->format( makeRecord( 'x' ) );
    $payload = json_decode( trim( $line ), true, flags: JSON_THROW_ON_ERROR );

    expect( array_key_exists( 'context', $payload ) )->toBeFalse();
    expect( array_key_exists( 'extra', $payload ) )->toBeFalse();
} );

it( 'lowercases the level name (info/debug/warning/error)', function (): void {
    $formatter = new EcommerceJsonFormatter();

    $line    = $formatter->format( makeRecord( 'x', level: Level::Warning ) );
    $payload = json_decode( trim( $line ), true, flags: JSON_THROW_ON_ERROR );

    expect( $payload['level'] )->toBe( 'warning' );
} );

it( 'formats a batch as one line per record', function (): void {
    $formatter = new EcommerceJsonFormatter();

    $batch = $formatter->formatBatch( [
        makeRecord( 'first' ),
        makeRecord( 'second' ),
    ] );

    $lines = array_values( array_filter( explode( "\n", $batch ), fn ( string $line ): bool => '' !== $line ) );
    expect( $lines )->toHaveCount( 2 );

    expect( json_decode( $lines[0], true, flags: JSON_THROW_ON_ERROR )['message'] )->toBe( 'first' );
    expect( json_decode( $lines[1], true, flags: JSON_THROW_ON_ERROR )['message'] )->toBe( 'second' );
} );
