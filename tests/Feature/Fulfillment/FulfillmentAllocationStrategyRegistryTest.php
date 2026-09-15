<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Contracts\FulfillmentAllocationStrategy;
use ArtisanPackUI\Ecommerce\Fulfillment\ProportionalByLineTotalStrategy;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Registries\FulfillmentAllocationStrategyRegistry;

it( 'boots with proportional-by-line-total registered', function (): void {
    $registry = $this->app->make( FulfillmentAllocationStrategyRegistry::class );

    expect( $registry->has( 'proportional-by-line-total' ) )->toBeTrue();
    expect( $registry->get( 'proportional-by-line-total' ) )
        ->toBeInstanceOf( ProportionalByLineTotalStrategy::class );
} );

it( 'throws when resolving an unknown key', function (): void {
    $registry = $this->app->make( FulfillmentAllocationStrategyRegistry::class );

    expect( fn () => $registry->get( 'nope' ) )->toThrow( RuntimeException::class );
} );

it( 'resolves the active strategy named by config', function (): void {
    config()->set(
        'artisanpack.ecommerce.fulfillment.allocation_strategy',
        'proportional-by-line-total',
    );

    $registry = $this->app->make( FulfillmentAllocationStrategyRegistry::class );

    expect( $registry->active() )->toBeInstanceOf( ProportionalByLineTotalStrategy::class );
} );

it( 'rejects an empty key', function (): void {
    $registry = $this->app->make( FulfillmentAllocationStrategyRegistry::class );

    expect( fn () => $registry->register( '   ', ProportionalByLineTotalStrategy::class ) )
        ->toThrow( InvalidArgumentException::class );
} );

it( 'rejects a class name that is not a FulfillmentAllocationStrategy', function (): void {
    $registry = $this->app->make( FulfillmentAllocationStrategyRegistry::class );

    expect( fn () => $registry->register( 'bogus', stdClass::class ) )
        ->toThrow( InvalidArgumentException::class );
} );

it( 'throws on double registration in testing environment', function (): void {
    $registry = $this->app->make( FulfillmentAllocationStrategyRegistry::class );

    // `proportional-by-line-total` is already registered from boot.
    expect( fn () => $registry->register(
        'proportional-by-line-total',
        ProportionalByLineTotalStrategy::class,
    ) )->toThrow( InvalidArgumentException::class );
} );

it( 'accepts a pre-built instance and returns it verbatim', function (): void {
    $registry = $this->app->make( FulfillmentAllocationStrategyRegistry::class );

    $instance = new class implements FulfillmentAllocationStrategy {
        public function key(): string
        {
            return 'stub';
        }

        public function allocate( Order $order, iterable $items ): array
        {
            return [];
        }
    };

    $registry->register( 'stub', $instance );

    expect( $registry->get( 'stub' ) )->toBe( $instance );
} );
