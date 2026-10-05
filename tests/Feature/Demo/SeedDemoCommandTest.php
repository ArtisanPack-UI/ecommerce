<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Demo\DemoCatalog;
use ArtisanPackUI\Ecommerce\Demo\DemoSeeder;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Registries\KanbanAutomationRegistry;
use ArtisanPackUI\Ecommerce\Services\KanbanRoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses( RefreshDatabase::class );

/**
 * Runs the seeder with small counts and a fixed seed.
 *
 * @param  array<string, mixed>  $options  Extra command options.
 */
function seedDemo( array $options = [] ): Illuminate\Testing\PendingCommand
{
    return test()->artisan( 'ecommerce:seed-demo', array_merge( [
        '--products' => 12,
        '--orders'   => 30,
        '--seed'     => 42,
        '--force'    => true,
    ], $options ) );
}

it( 'seeds the requested number of products and orders', function (): void {
    seedDemo()->assertSuccessful();

    expect( Product::query()->count() )->toBe( 12 )
        ->and( Order::query()->count() )->toBe( 30 )
        ->and( Customer::query()->count() )->toBeGreaterThanOrEqual( 4 )
        ->and( DB::table( 'ecommerce_customer_addresses' )->count() )->toBeGreaterThanOrEqual( Customer::query()->count() )
        ->and( DB::table( 'ecommerce_tax_rates' )->count() )->toBeGreaterThan( 0 )
        ->and( DB::table( 'ecommerce_shipping_methods' )->count() )->toBeGreaterThan( 0 )
        ->and( DB::table( 'ecommerce_coupons' )->count() )->toBeGreaterThan( 0 );
} );

it( 'covers simple, variable, and digital products with categories in meta', function (): void {
    seedDemo()->assertSuccessful();

    $kinds = Product::query()->get()->map( fn ( Product $product ): string => $product->meta['kind'] )->unique()->sort()->values()->all();

    expect( $kinds )->toBe( [ 'digital', 'simple', 'variable' ] )
        ->and( Product::query()->where( 'type', 'digital' )->count() )->toBeGreaterThan( 0 )
        ->and( DB::table( 'ecommerce_product_variants' )->count() )->toBeGreaterThan( 0 )
        ->and( DB::table( 'ecommerce_product_variant_option_values' )->count() )->toBe( DB::table( 'ecommerce_product_variants' )->count() * 2 )
        ->and( DB::table( 'ecommerce_digital_files' )->count() )->toBe( Product::query()->where( 'type', 'digital' )->count() )
        ->and( Product::query()->get()->every( fn ( Product $product ): bool => [] !== ( $product->meta['categories'] ?? [] ) ) )->toBeTrue();
} );

it( 'places every order within the last 90 days across every system status', function (): void {
    seedDemo()->assertSuccessful();

    $oldest = Order::query()->min( 'placed_at' );

    expect( now()->subDays( 90 )->lessThan( $oldest ) )->toBeTrue()
        ->and( Order::query()->where( 'placed_at', '>', now() )->exists() )->toBeFalse()
        ->and( Order::query()->distinct()->pluck( 'system_status' )->sort()->values()->all() )
        ->toBe( [ 'cancelled', 'complete', 'failed', 'pending', 'processing', 'refunded' ] );
} );

it( 'writes order totals that add up', function (): void {
    seedDemo()->assertSuccessful();

    Order::query()->with( 'items' )->get()->each( function ( Order $order ): void {
        expect( $order->total_amount )
            ->toBe( $order->subtotal_amount + $order->shipping_amount + $order->tax_amount - $order->discount_amount )
            ->and( $order->items->sum( fn ( $item ): int => $item->unit_price_amount * $item->quantity ) )->toBe( $order->subtotal_amount )
            ->and( $order->items->sum( 'total_amount' ) )->toBe( $order->total_amount );
    } );

    expect( Order::query()->where( 'system_status', 'refunded' )->whereColumn( 'total_refunded_amount', '!=', 'total_amount' )->exists() )->toBeFalse()
        ->and( DB::table( 'ecommerce_refunds' )->count() )->toBeGreaterThan( 0 )
        ->and( DB::table( 'ecommerce_shipments' )->count() )->toBeGreaterThan( 0 );
} );

it( 'pre-populates the kanban boards with columns, automations, and cards', function (): void {
    seedDemo()->assertSuccessful();

    $triggers = app( KanbanAutomationRegistry::class );

    expect( DB::table( 'ecommerce_kanban_boards' )->count() )->toBe( 2 )
        ->and( DB::table( 'ecommerce_kanban_columns' )->count() )->toBeGreaterThan( 2 )
        ->and( DB::table( 'ecommerce_kanban_automations' )->count() )->toBeGreaterThan( 0 )
        ->and( DB::table( 'ecommerce_order_board_assignments' )->count() )->toBeGreaterThan( 0 )
        ->and( DB::table( 'ecommerce_kanban_automations' )->pluck( 'trigger_key' )->every( fn ( string $key ): bool => $triggers->has( $key ) ) )->toBeTrue();

    // Each card sits in a column of its board, and the board's routing
    // rules agree the order belongs there.
    $routing = app( KanbanRoutingService::class );

    DB::table( 'ecommerce_order_board_assignments' )->get()->each( function ( object $card ) use ( $routing ): void {
        expect( DB::table( 'ecommerce_kanban_columns' )->where( 'board_id', $card->board_id )->where( 'substatus_id', $card->substatus_id )->exists() )->toBeTrue()
            ->and( $routing->matchingBoardIds( Order::query()->findOrFail( $card->order_id ) ) )->toContain( (int) $card->board_id );
    } );
} );

it( 'draws content from all four locales', function (): void {
    seedDemo()->assertSuccessful();

    $productLocales  = Product::query()->get()->map( fn ( Product $product ): string => $product->meta['locale'] )->unique()->sort()->values()->all();
    $customerLocales = Customer::query()->get()->map( fn ( Customer $customer ): string => $customer->meta['locale'] )->unique()->sort()->values()->all();
    $expected        = collect( DemoCatalog::LOCALES )->sort()->values()->all();

    expect( $productLocales )->toBe( $expected )
        ->and( $customerLocales )->toBe( $expected )
        ->and( DB::table( 'ecommerce_customer_addresses' )->distinct()->pluck( 'country_code' )->intersect( [ 'ES', 'FR', 'DE' ] )->count() )->toBe( 3 );
} );

it( 'produces the same store twice for the same seed', function (): void {
    seedDemo()->assertSuccessful();

    $first = [ app( DemoSeeder::class )->counts(), Order::query()->orderBy( 'id' )->pluck( 'total_amount' )->all() ];

    seedDemo( [ '--fresh' => true ] )->assertSuccessful();

    $second = [ app( DemoSeeder::class )->counts(), Order::query()->orderBy( 'id' )->pluck( 'total_amount' )->all() ];

    expect( $second )->toBe( $first )
        ->and( DB::table( 'ecommerce_order_substatuses' )->where( 'key', 'packed' )->count() )->toBe( 1 );
} );

it( 'refuses to seed a store that already has data without --fresh', function (): void {
    seedDemo()->assertSuccessful();

    seedDemo()->assertFailed();

    expect( Product::query()->count() )->toBe( 12 );
} );

it( 'refuses when only store configuration exists', function ( Closure $configure ): void {
    $configure();

    seedDemo()->assertFailed();

    expect( Product::query()->count() )->toBe( 0 );
} )->with( [
    'a coupon'        => [ fn () => ArtisanPackUI\Ecommerce\Models\Coupon::factory()->create( [ 'code' => 'WELCOME10' ] ) ],
    'a shipping zone' => [ fn () => ArtisanPackUI\Ecommerce\Models\ShippingZone::factory()->create() ],
    'a kanban board'  => [ fn () => ArtisanPackUI\Ecommerce\Models\KanbanBoard::factory()->create() ],
] );

it( 'rolls the wipe back when a delete fails part-way through', function (): void {
    seedDemo()->assertSuccessful();

    $products = Product::query()->count();

    // Fail on a table late in the wipe order, after earlier tables were emptied.
    DB::listen( static function ( $query ): void {
        if ( str_starts_with( strtolower( $query->sql ), 'delete from "ecommerce_products"' ) ) {
            throw new RuntimeException( 'blocked by a foreign key' );
        }
    } );

    expect( fn () => ( new DemoSeeder() )->wipe() )->toThrow( RuntimeException::class );

    expect( Product::query()->count() )->toBe( $products )
        ->and( Order::query()->count() )->toBeGreaterThan( 0 );
} );

it( 'asks before wiping and leaves the store alone when declined', function (): void {
    seedDemo()->assertSuccessful();

    $this->artisan( 'ecommerce:seed-demo', [ '--fresh' => true, '--products' => 5, '--orders' => 5 ] )
        ->expectsConfirmation( 'This deletes every product, customer, order, promotion, coupon, tax rate, shipping zone, webhook subscription, license key, and kanban board in the store. Continue?', 'no' )
        ->assertFailed();

    expect( Product::query()->count() )->toBe( 12 );
} );

it( 'refuses to run in production without --force', function (): void {
    app()->detectEnvironment( fn (): string => 'production' );

    $this->artisan( 'ecommerce:seed-demo', [ '--products' => 5, '--orders' => 5 ] )->assertFailed();

    expect( Product::query()->count() )->toBe( 0 );
} );

it( 'refuses --fresh --force in production without the explicit flag', function (): void {
    $this->artisan( 'ecommerce:seed-demo', [ '--products' => 3, '--orders' => 0, '--force' => true ] )->assertSuccessful();

    app()->detectEnvironment( fn (): string => 'production' );

    $this->artisan( 'ecommerce:seed-demo', [ '--fresh' => true, '--force' => true, '--products' => 5, '--orders' => 0 ] )
        ->expectsOutputToContain( '--i-understand-this-deletes-production-data' )
        ->assertFailed();

    expect( Product::query()->count() )->toBe( 3 );

    $this->artisan( 'ecommerce:seed-demo', [ '--fresh' => true, '--force' => true, '--i-understand-this-deletes-production-data' => true, '--products' => 5, '--orders' => 0 ] )
        ->assertSuccessful();

    expect( Product::query()->count() )->toBe( 5 );
} );

it( 'rejects invalid counts', function (): void {
    $this->artisan( 'ecommerce:seed-demo', [ '--products' => 0, '--force' => true ] )->assertExitCode( 2 );
    $this->artisan( 'ecommerce:seed-demo', [ '--orders' => 'lots', '--force' => true ] )->assertExitCode( 2 );

    expect( Product::query()->count() )->toBe( 0 );
} );

it( 'seeds the default demo size', function (): void {
    $this->artisan( 'ecommerce:seed-demo', [ '--force' => true ] )->assertSuccessful();

    expect( Product::query()->count() )->toBe( 50 )
        ->and( Order::query()->count() )->toBe( 200 )
        ->and( DB::table( 'ecommerce_order_board_assignments' )->count() )->toBeGreaterThan( 50 );
} );
