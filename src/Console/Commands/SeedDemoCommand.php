<?php

/**
 * SeedDemoCommand.
 *
 * `php artisan ecommerce:seed-demo` — fills the engine with a rich demo
 * store for manual QA and the `artisanpack-ui-dev` app (parent plan §15.5).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Console\Commands;

use ArtisanPackUI\Ecommerce\Demo\DemoSeeder;
use Illuminate\Console\Command;

/**
 * Seeds the demo store.
 *
 * Without `--fresh` the command refuses to run against a store that
 * already has data — products, orders, customers, or store configuration
 * (tax rates, shipping zones, promotions, coupons, kanban boards) — so it
 * can never mix demo rows into real data. `--fresh` empties the engine's data tables first
 * (host tables such as `users` and `migrations` are never touched); it
 * asks for confirmation unless `--force` is given, and in production it
 * refuses to run at all without `--force`.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class SeedDemoCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ecommerce:seed-demo
        {--fresh : Empty the engine\'s data tables before seeding.}
        {--products=50 : Number of products to create.}
        {--orders=200 : Number of orders to create (placed over the last 90 days).}
        {--seed= : Random seed; the same seed produces the same store.}
        {--force : Skip the confirmation prompt and allow running in production.}';

    /**
     * @var string
     */
    protected $description = 'Seed a demo store (products, customers, ~200 orders, kanban board). Refuses to run on a non-empty store unless --fresh is given.';

    /**
     * @since 1.0.0
     *
     * @param  DemoSeeder  $seeder  Demo seeder.
     *
     * @return int
     */
    public function handle( DemoSeeder $seeder ): int
    {
        $force = (bool) $this->option( 'force' );
        $fresh = (bool) $this->option( 'fresh' );

        if ( $this->laravel->environment( 'production' ) && ! $force ) {
            $this->error( __( 'Refusing to seed demo data in production. Re-run with --force if you really mean it.' ) );

            return self::FAILURE;
        }

        $products = $this->positiveOption( 'products' );
        $orders   = $this->positiveOption( 'orders', true );
        $seed     = $this->option( 'seed' );

        if ( null === $products || null === $orders || ( null !== $seed && ! is_numeric( $seed ) ) ) {
            $this->error( __( '--products and --orders must be whole numbers (products at least 1) and --seed must be numeric.' ) );

            return self::INVALID;
        }

        if ( $fresh ) {
            if ( ! $force && ! $this->confirm( __( 'This deletes every product, customer, order, promotion, coupon, tax rate, shipping zone, webhook subscription, license key, and kanban board in the store. Continue?' ) ) ) {
                $this->warn( __( 'Aborted; nothing was changed.' ) );

                return self::FAILURE;
            }

            $seeder->wipe();
        } elseif ( $seeder->hasData() ) {
            $this->error( __( 'The store already has data (products, orders, customers, tax rates, shipping zones, promotions, coupons, or kanban boards). Re-run with --fresh to replace it with demo data.' ) );

            return self::FAILURE;
        }

        $started = microtime( true );
        $counts  = $seeder->seed( $products, $orders, null === $seed ? null : (int) $seed );

        $this->table(
            [ __( 'Table' ), __( 'Rows' ) ],
            array_map( static fn ( string $table, int $count ): array => [ $table, $count ], array_keys( $counts ), $counts ),
        );

        $this->info( __( 'Demo store seeded in :seconds s.', [ 'seconds' => number_format( microtime( true ) - $started, 2 ) ] ) );
        $this->line( __( 'Products are not pushed to Scout; run `php artisan scout:import` if you use a search engine.' ) );

        return self::SUCCESS;
    }

    /**
     * Reads a whole-number option, or null when it isn't one.
     *
     * @since 1.0.0
     *
     * @param  string  $name       Option name.
     * @param  bool    $allowZero  Whether 0 is accepted.
     *
     * @return int|null
     */
    protected function positiveOption( string $name, bool $allowZero = false ): ?int
    {
        $value = $this->option( $name );

        if ( ! is_numeric( $value ) || (string) (int) $value !== (string) $value ) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 || ( $allowZero && 0 === $value ) ? $value : null;
    }
}
