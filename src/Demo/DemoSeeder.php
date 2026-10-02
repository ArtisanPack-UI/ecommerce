<?php

/**
 * DemoSeeder.
 *
 * Builds the rich demo store behind `ecommerce:seed-demo` (parent plan
 * §15.5): a localized catalog of simple, variable, and digital products,
 * customers across the four day-1 locales, ~200 orders spread over the last
 * 90 days and every system status, and a pre-populated kanban board with
 * sample automations.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Demo;

use ArtisanPackUI\Ecommerce\Kanban\Triggers\SendEmailTrigger;
use ArtisanPackUI\Ecommerce\Kanban\Triggers\UpdateOrderFieldTrigger;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use ArtisanPackUI\Ecommerce\ProductTypes\DigitalProductType;
use ArtisanPackUI\Ecommerce\ProductTypes\SimpleProductType;
use ArtisanPackUI\Ecommerce\Registries\SubStatusRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Seeds (and wipes) the demo store.
 *
 * Rows are written with the query builder rather than Eloquent so seeding
 * stays fast and fires no engine hooks, events, notifications, webhooks,
 * or Scout syncs — the demo is data, not a replay of real traffic. Totals
 * follow the engine's own formulas (line total = unit × qty + tax +
 * shipping − discount; order total = subtotal + shipping + tax − discount)
 * so reports and refunds behave as they would on real orders.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class DemoSeeder
{
    /**
     * Engine data tables `--fresh` empties, children first. Store
     * configuration that isn't demo data (`notification_templates`) and
     * the rows migrations seed (`tax_classes`, the `order_substatuses`
     * defaults) are left alone.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const ENGINE_TABLES = [
        'order_board_assignments',
        'kanban_automations',
        'kanban_columns',
        'kanban_boards',
        'kanban_card_widgets',
        'license_activations',
        'license_keys',
        'digital_download_events',
        'digital_downloads',
        'digital_files',
        'product_review_media',
        'product_reviews',
        'promotion_usages',
        'coupons',
        'promotion_actions',
        'promotion_conditions',
        'promotions',
        'shipment_items',
        'shipments',
        'refund_items',
        'refunds',
        'order_edits',
        'order_timeline_entries',
        'order_notes',
        'order_items',
        'webhook_deliveries',
        'webhook_subscriptions',
        'inbound_webhook_deliveries',
        'idempotency_records',
        'orders',
        'cart_items',
        'carts',
        'inventory_reservations',
        'inventory_items',
        'customer_notification_preferences',
        'customer_claim_attempts',
        'customer_addresses',
        'customers',
        'shipping_methods',
        'shipping_zones',
        'tax_rates',
        'product_variant_option_values',
        'product_attribute_values',
        'product_attributes',
        'product_prices',
        'product_variants',
        'products',
    ];

    /**
     * The default sub-status rows migration `…000014` seeds, as
     * `system_status => key`. `--fresh` keeps these and removes the rest.
     *
     * @since 1.0.0
     *
     * @var array<string, string>
     */
    public const DEFAULT_SUBSTATUSES = [
        'pending'    => 'awaiting-payment',
        'processing' => 'in-progress',
        'complete'   => 'completed',
        'cancelled'  => 'cancelled',
        'refunded'   => 'refunded',
        'failed'     => 'failed',
    ];

    /**
     * Every core order system status (engine spec §3.15).
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const SYSTEM_STATUSES = [ 'pending', 'processing', 'complete', 'cancelled', 'refunded', 'failed' ];

    /**
     * Tables whose rows mean the store is already set up. Any of them
     * holding data makes a non-`--fresh` run refuse, since the seeder
     * inserts its own tax rates, zones, promotions, coupons (unique codes),
     * and boards unconditionally.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const STORE_TABLES = [
        'products',
        'orders',
        'customers',
        'tax_rates',
        'shipping_zones',
        'promotions',
        'coupons',
        'kanban_boards',
    ];

    /**
     * Rate from the base currency to the secondary currency, as E8
     * (1 base = 0.925 secondary).
     *
     * @since 1.0.0
     *
     * @var int
     */
    protected const SECONDARY_RATE_E8 = 92_500_000;

    /**
     * Seeded random source.
     *
     * @since 1.0.0
     *
     * @var Randomizer
     */
    protected Randomizer $random;

    /**
     * Reference "now" every generated date is relative to.
     *
     * @since 1.0.0
     *
     * @var Carbon
     */
    protected Carbon $now;

    /**
     * The store's base currency.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected string $baseCurrency = 'USD';

    /**
     * The second currency some prices and non-`en` orders use.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected string $secondaryCurrency = 'EUR';

    /**
     * Active sellable lines: one per simple/digital product, one per
     * variant of a variable product.
     *
     * @since 1.0.0
     *
     * @var array<int, array<string, mixed>>
     */
    protected array $sellables = [];

    /**
     * Seeded customers with their locale and default address.
     *
     * @since 1.0.0
     *
     * @var array<int, array<string, mixed>>
     */
    protected array $customers = [];

    /**
     * Sub-status ids keyed by `system_status.key`.
     *
     * @since 1.0.0
     *
     * @var array<string, int>
     */
    protected array $substatuses = [];

    /**
     * Promotion ids keyed by promotion key.
     *
     * @since 1.0.0
     *
     * @var array<string, int>
     */
    protected array $promotions = [];

    /**
     * Tax rates as `tax_class_key.country` => `[ rate_ubps, label ]`.
     *
     * @since 1.0.0
     *
     * @var array<string, array{0: int, 1: string}>
     */
    protected array $taxRates = [];

    /**
     * Issued order numbers, so generated numbers never collide.
     *
     * @since 1.0.0
     *
     * @var array<string, true>
     */
    protected array $orderNumbers = [];

    /**
     * Whether the engine already holds store data (catalog, orders,
     * customers, or store configuration).
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function hasData(): bool
    {
        foreach ( self::STORE_TABLES as $table ) {
            if ( Schema::hasTable( $table ) && DB::table( $table )->exists() ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Empties every engine data table and resets `order_substatuses` to
     * the migration defaults.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function wipe(): void
    {
        // SQLite only honours the pragma outside a transaction; Postgres
        // only honours `SET CONSTRAINTS ALL DEFERRED` inside one. Disable
        // on both sides so every driver gets it.
        Schema::disableForeignKeyConstraints();

        try {
            // All or nothing: if a host/satellite FK blocks a delete part-way
            // through, nothing is left half-wiped.
            DB::transaction( function (): void {
                Schema::disableForeignKeyConstraints();

                foreach ( self::ENGINE_TABLES as $table ) {
                    if ( Schema::hasTable( $table ) ) {
                        DB::table( $table )->delete();
                    }
                }

                DB::table( 'order_substatuses' )
                    ->get( [ 'id', 'system_status', 'key' ] )
                    ->reject( static fn ( object $row ): bool => ( self::DEFAULT_SUBSTATUSES[ $row->system_status ] ?? null ) === $row->key )
                    ->each( static function ( object $row ): void {
                        DB::table( 'order_substatuses' )->where( 'id', $row->id )->delete();
                    } );
            } );
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        app( SubStatusRegistry::class )->flush();
    }

    /**
     * Seeds the demo store and returns row counts per table.
     *
     * @since 1.0.0
     *
     * @param  int       $products  Number of products to create.
     * @param  int       $orders    Number of orders to create.
     * @param  int|null  $seed      Random seed; the same seed yields the same store.
     *
     * @return array<string, int>
     */
    public function seed( int $products = 50, int $orders = 200, ?int $seed = null ): array
    {
        $this->random            = new Randomizer( new Mt19937( $seed ?? random_int( 1, PHP_INT_MAX ) ) );
        $this->now               = Carbon::now()->startOfMinute();
        $this->baseCurrency      = strtoupper( (string) config( 'artisanpack.ecommerce.base_currency', 'USD' ) );
        $this->secondaryCurrency = 'EUR' === $this->baseCurrency ? 'USD' : 'EUR';
        $this->sellables         = [];
        $this->customers         = [];
        $this->orderNumbers      = [];

        DB::transaction( function () use ( $products, $orders ): void {
            $this->seedSubstatuses();
            $this->seedTaxes();
            $this->seedShipping();
            $this->seedPromotions();
            $this->seedProducts( max( 3, $products ) );
            $this->seedCustomers( max( 4, (int) ceil( $orders / 4 ) ) );
            $this->seedOrders( max( 0, $orders ) );
            $this->seedKanban();
            $this->refreshAggregates();
        } );

        return $this->counts();
    }

    /**
     * Row counts for the tables the demo populates.
     *
     * @since 1.0.0
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [];

        foreach ( [
            'products',
            'product_variants',
            'product_prices',
            'inventory_items',
            'digital_files',
            'customers',
            'customer_addresses',
            'orders',
            'order_items',
            'shipments',
            'refunds',
            'license_keys',
            'product_reviews',
            'promotions',
            'coupons',
            'tax_rates',
            'shipping_methods',
            'kanban_boards',
            'kanban_columns',
            'kanban_automations',
            'order_board_assignments',
        ] as $table ) {
            $counts[ $table ] = DB::table( $table )->count();
        }

        return $counts;
    }

    /**
     * Adds two extra `processing` sub-statuses ("Picking", "Packed") for
     * the fulfillment board and indexes every sub-status id.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function seedSubstatuses(): void
    {
        foreach ( self::DEFAULT_SUBSTATUSES as $status => $key ) {
            $exists = DB::table( 'order_substatuses' )->where( 'system_status', $status )->where( 'key', $key )->exists();

            if ( ! $exists ) {
                DB::table( 'order_substatuses' )->insert( $this->stamp( [
                    'system_status' => $status,
                    'key'           => $key,
                    'label'         => Str::headline( $key ),
                    'position'      => 0,
                    'is_terminal'   => ! in_array( $status, [ 'pending', 'processing' ], true ),
                ] ) );
            }
        }

        foreach ( [ [ 'picking', 'Picking', '#6366F1', 1 ], [ 'packed', 'Packed', '#0EA5E9', 2 ] ] as [ $key, $label, $color, $position ] ) {
            DB::table( 'order_substatuses' )->updateOrInsert(
                [ 'system_status' => 'processing', 'key' => $key ],
                $this->stamp( [ 'label' => $label, 'color' => $color, 'position' => $position, 'is_terminal' => false ] ),
            );
        }

        $this->substatuses = DB::table( 'order_substatuses' )
            ->get( [ 'id', 'system_status', 'key' ] )
            ->mapWithKeys( static fn ( object $row ): array => [ $row->system_status . '.' . $row->key => (int) $row->id ] )
            ->all();

        // These writes bypass the model, so the cached lookup is flushed by hand.
        app( SubStatusRegistry::class )->flush();
    }

    /**
     * Seeds the `standard` and `reduced` tax classes with per-country rates.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function seedTaxes(): void
    {
        // Migration …000024 seeds these; restore them if an operator removed one.
        foreach ( [ 'standard' => 'Standard', 'reduced' => 'Reduced rate' ] as $key => $label ) {
            if ( ! DB::table( 'tax_classes' )->where( 'key', $key )->exists() ) {
                DB::table( 'tax_classes' )->insert( $this->stamp( [ 'key' => $key, 'label' => $label ] ) );
            }
        }

        $reduced = [
            'US' => 82_500_000,
            'GB' => 0,
            'ES' => 40_000_000,
            'FR' => 55_000_000,
            'DE' => 70_000_000,
        ];

        $rows = [];

        foreach ( DemoCatalog::TAX_RATES as $country => [ $rate, $label ] ) {
            foreach ( [ 'standard' => $rate, 'reduced' => $reduced[ $country ] ] as $class => $classRate ) {
                $this->taxRates[ $class . '.' . $country ] = [ $classRate, $label ];

                $rows[] = $this->stamp( [
                    'tax_class_key'       => $class,
                    'country_code'        => $country,
                    'rate_ubps'           => $classRate,
                    'priority'            => 0,
                    'label'               => $label,
                    'is_shipping_taxable' => false,
                    'is_active'           => true,
                ] );
            }
        }

        DB::table( 'tax_rates' )->insert( $rows );
    }

    /**
     * Seeds a North America and a Europe shipping zone, each with flat
     * rate, free shipping over a threshold, and local pickup.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function seedShipping(): void
    {
        foreach ( [
            [ 'North America', [ 'US' ], 599 ],
            [ 'Europe', [ 'GB', 'ES', 'FR', 'DE' ], 899 ],
        ] as $position => [ $name, $countries, $flat ] ) {
            $zoneId = DB::table( 'shipping_zones' )->insertGetId( $this->stamp( [
                'name'          => $name,
                'country_codes' => json_encode( $countries ),
                'priority'      => $position,
                'is_active'     => true,
            ] ) );

            DB::table( 'shipping_methods' )->insert( [
                $this->stamp( [ 'zone_id' => $zoneId, 'key' => 'flat-rate', 'label' => 'Standard shipping', 'config' => json_encode( [ 'amount' => $flat ] ), 'position' => 0, 'is_active' => true ] ),
                $this->stamp( [ 'zone_id' => $zoneId, 'key' => 'free-shipping', 'label' => 'Free shipping', 'config' => json_encode( [ 'min_subtotal' => 7_500 ] ), 'position' => 1, 'is_active' => true ] ),
                $this->stamp( [ 'zone_id' => $zoneId, 'key' => 'local-pickup', 'label' => 'Local pickup', 'config' => json_encode( [ 'amount' => 0 ] ), 'position' => 2, 'is_active' => true ] ),
            ] );
        }
    }

    /**
     * Seeds an automatic sale, two coupon promotions, and an expired one.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function seedPromotions(): void
    {
        $definitions = [
            'demo-spring-sale'   => [ 'Spring sale — 10% off orders over 150', 'automatic', 'percent-off-cart', [ 'percent' => 10 ], 'min-subtotal', [ 'amount' => 15_000 ], -60, 30, null ],
            'demo-welcome'       => [ 'Welcome — 10 off your first order', 'coupon', 'fixed-off-cart', [ 'amount' => 1_000 ], 'customer-first-order', [], -120, null, 'WELCOME10' ],
            'demo-free-shipping' => [ 'Free shipping weekend', 'coupon', 'free-shipping', [], 'min-subtotal', [ 'amount' => 5_000 ], -30, 14, 'FREESHIP' ],
            'demo-black-friday'  => [ 'Black Friday — 25% off', 'coupon', 'percent-off-cart', [ 'percent' => 25 ], 'min-subtotal', [ 'amount' => 1_000 ], -320, -310, 'BLACKFRIDAY' ],
        ];

        foreach ( $definitions as $key => [ $name, $source, $action, $actionConfig, $condition, $conditionConfig, $startsIn, $endsIn, $code ] ) {
            $id = DB::table( 'promotions' )->insertGetId( $this->stamp( [
                'key'          => $key,
                'name'         => $name,
                'source_type'  => $source,
                'is_exclusive' => false,
                'priority'     => 0,
                'starts_at'    => $this->now->copy()->addDays( $startsIn ),
                'ends_at'      => null === $endsIn ? null : $this->now->copy()->addDays( $endsIn ),
                'is_active'    => true,
            ] ) );

            DB::table( 'promotion_actions' )->insert( [ 'promotion_id' => $id, 'type' => $action, 'config' => json_encode( (object) $actionConfig ) ] );
            DB::table( 'promotion_conditions' )->insert( [ 'promotion_id' => $id, 'type' => $condition, 'config' => json_encode( (object) $conditionConfig ) ] );

            if ( null !== $code ) {
                DB::table( 'coupons' )->insert( $this->stamp( [ 'promotion_id' => $id, 'code' => $code ] ) );
            }

            $this->promotions[ $key ] = (int) $id;
        }
    }

    /**
     * Seeds `$count` products: ~40% simple, ~30% variable (simple type with
     * size × color variants), ~30% digital (e-books, software with
     * licensing, music). Locales rotate so every locale is represented.
     *
     * @since 1.0.0
     *
     * @param  int  $count  Products to create (minimum three, one per kind).
     *
     * @return void
     */
    protected function seedProducts( int $count ): void
    {
        $simple   = max( 1, (int) round( $count * 0.4 ) );
        $variable = max( 1, (int) round( $count * 0.3 ) );
        $digital  = max( 1, $count - $simple - $variable );
        $kinds    = array_merge(
            array_fill( 0, $simple, 'simple' ),
            array_fill( 0, $variable, 'variable' ),
            array_fill( 0, $digital, 'digital' ),
        );

        $categoriesByKind = [];

        foreach ( DemoCatalog::CATEGORIES as $key => $category ) {
            $categoriesByKind[ $category['kind'] ][] = $key;
        }

        $seenPerKind = [];
        $usedNames   = [];
        $usedSlugs   = [];

        foreach ( $kinds as $index => $kind ) {
            $ordinal                = $seenPerKind[ $kind ] ?? 0;
            $seenPerKind[ $kind ]   = $ordinal + 1;
            $category               = $categoriesByKind[ $kind ][ $ordinal % count( $categoriesByKind[ $kind ] ) ];
            $locale                 = DemoCatalog::LOCALES[ $index % count( DemoCatalog::LOCALES ) ];
            $name                   = $this->uniqueName( $category, $locale, $usedNames );
            $slug                   = $this->uniqueSlug( $name, $usedSlugs );
            $sku                    = strtoupper( substr( $category, 0, 3 ) ) . '-' . str_pad( (string) ( $index + 1 ), 4, '0', STR_PAD_LEFT );
            $isDraft                = $ordinal > 0 && 11 === $index % 12;
            $productId              = $this->insertProduct( $kind, $category, $locale, $name, $slug, $sku, $isDraft );

            match ( $kind ) {
                'variable' => $this->seedVariants( $productId, $category, $locale, $name, $sku, $isDraft ),
                'digital'  => $this->seedDigital( $productId, $category, $locale, $name, $slug, $sku, $isDraft ),
                default    => $this->seedSimple( $productId, $category, $locale, $name, $sku, $isDraft ),
            };
        }
    }

    /**
     * Inserts the `products` row.
     *
     * @since 1.0.0
     *
     * @param  string  $kind      `simple`, `variable`, or `digital`.
     * @param  string  $category  Category key.
     * @param  string  $locale    Content locale.
     * @param  string  $name      Product name.
     * @param  string  $slug      Unique slug.
     * @param  string  $sku       Unique SKU.
     * @param  bool    $isDraft   Whether the product is unpublished.
     *
     * @return int
     */
    protected function insertProduct( string $kind, string $category, string $locale, string $name, string $slug, string $sku, bool $isDraft ): int
    {
        $digital     = 'digital' === $kind;
        $description = str_replace( ':name', $name, DemoCatalog::DESCRIPTIONS[ $category ][ $locale ] );
        $meta        = [
            'demo'       => true,
            'locale'     => $locale,
            'kind'       => $kind,
            'categories' => [ $category ],
            'category'   => DemoCatalog::CATEGORIES[ $category ]['labels'][ $locale ],
        ];

        if ( 'software' === $category ) {
            $meta['licensing'] = [ 'enabled' => true, 'activations_limit' => 3, 'expires_in_days' => 365 ];
        }

        return (int) DB::table( 'products' )->insertGetId( $this->stamp( [
            'type'              => $digital ? DigitalProductType::KEY : SimpleProductType::KEY,
            'name'              => $name,
            'slug'              => $slug,
            'sku'               => $sku,
            'description'       => $description,
            'short_description' => DemoCatalog::CATEGORIES[ $category ]['labels'][ $locale ],
            'status'            => $isDraft ? 'draft' : 'active',
            'is_taxable'        => true,
            'tax_class_key'     => 'ebooks' === $category ? 'reduced' : 'standard',
            'weight'            => $digital ? null : $this->random->getInt( 2, 30 ) / 10,
            'weight_unit'       => $digital ? null : 'kg',
            'meta'              => json_encode( $meta ),
            'published_at'      => $isDraft ? null : $this->now->copy()->subDays( $this->random->getInt( 100, 240 ) ),
        ] ) );
    }

    /**
     * Price, stock, and sellable entry for a simple product.
     *
     * @since 1.0.0
     *
     * @param  int     $productId  Product id.
     * @param  string  $category   Category key.
     * @param  string  $locale     Content locale.
     * @param  string  $name       Product name.
     * @param  string  $sku        SKU.
     * @param  bool    $isDraft    Whether the product is unpublished.
     *
     * @return void
     */
    protected function seedSimple( int $productId, string $category, string $locale, string $name, string $sku, bool $isDraft ): void
    {
        $price = $this->priceBetween( 15, 120 );

        $this->insertPrices( ( new Product() )->getMorphClass(), $productId, $price );
        $this->insertStock( ( new Product() )->getMorphClass(), $productId );

        if ( ! $isDraft ) {
            $this->sellables[] = $this->sellable( $productId, null, SimpleProductType::KEY, $category, $name, $sku, $price, true, [] );
        }
    }

    /**
     * Size × color attributes, variants (each with price and stock), and a
     * sellable entry per variant for a variable product.
     *
     * @since 1.0.0
     *
     * @param  int     $productId  Product id.
     * @param  string  $category   Category key.
     * @param  string  $locale     Content locale.
     * @param  string  $name       Product name.
     * @param  string  $sku        Product SKU; variant SKUs extend it.
     * @param  bool    $isDraft    Whether the product is unpublished.
     *
     * @return void
     */
    protected function seedVariants( int $productId, string $category, string $locale, string $name, string $sku, bool $isDraft ): void
    {
        $base = $this->priceBetween( 25, 90 );

        $this->insertPrices( ( new Product() )->getMorphClass(), $productId, $base );

        $colors  = array_keys( DemoCatalog::ATTRIBUTES['color']['values'] );
        $skipped = $colors[ $this->random->getInt( 0, count( $colors ) - 1 ) ];
        $chosen  = [
            'size'  => array_keys( DemoCatalog::ATTRIBUTES['size']['values'] ),
            'color' => array_values( array_diff( $colors, [ $skipped ] ) ),
        ];

        $attributeIds = [];
        $valueIds     = [];

        foreach ( $chosen as $attributeKey => $values ) {
            $definition                    = DemoCatalog::ATTRIBUTES[ $attributeKey ];
            $attributeIds[ $attributeKey ] = (int) DB::table( 'product_attributes' )->insertGetId( $this->stamp( [
                'product_id'   => $productId,
                'key'          => $attributeKey,
                'label'        => $definition['labels'][ $locale ],
                'position'     => count( $attributeIds ),
                'is_variation' => true,
            ] ) );

            foreach ( $values as $position => $value ) {
                $valueIds[ $attributeKey ][ $value ] = (int) DB::table( 'product_attribute_values' )->insertGetId( [
                    'product_attribute_id' => $attributeIds[ $attributeKey ],
                    'value'                => $value,
                    'label'                => $definition['values'][ $value ][ $locale ],
                    'swatch'               => DemoCatalog::SWATCHES[ $value ] ?? null,
                    'position'             => $position,
                ] );
            }
        }

        $morph    = ( new ProductVariant() )->getMorphClass();
        $position = 0;

        foreach ( $chosen['size'] as $size ) {
            foreach ( $chosen['color'] as $color ) {
                $label      = DemoCatalog::ATTRIBUTES['size']['values'][ $size ][ $locale ] . ' / ' . DemoCatalog::ATTRIBUTES['color']['values'][ $color ][ $locale ];
                $variantSku = $sku . '-' . strtoupper( $size . '-' . $color );
                $price      = 'l' === $size ? $base + 300 : $base;
                $variantId  = (int) DB::table( 'product_variants' )->insertGetId( $this->stamp( [
                    'product_id'  => $productId,
                    'sku'         => $variantSku,
                    'name'        => $label,
                    'weight'      => $this->random->getInt( 2, 12 ) / 10,
                    'weight_unit' => 'kg',
                    'position'    => $position++,
                    'meta'        => json_encode( [ 'size' => $size, 'color' => $color ] ),
                ] ) );

                foreach ( [ 'size' => $size, 'color' => $color ] as $attributeKey => $value ) {
                    DB::table( 'product_variant_option_values' )->insert( [
                        'product_variant_id'         => $variantId,
                        'product_attribute_id'       => $attributeIds[ $attributeKey ],
                        'product_attribute_value_id' => $valueIds[ $attributeKey ][ $value ],
                    ] );
                }

                $this->insertPrices( $morph, $variantId, $price );
                $this->insertStock( $morph, $variantId );

                if ( ! $isDraft ) {
                    $this->sellables[] = $this->sellable(
                        $productId,
                        $variantId,
                        SimpleProductType::KEY,
                        $category,
                        $name . ' — ' . $label,
                        $variantSku,
                        $price,
                        true,
                        [ 'size' => $size, 'color' => $color ],
                    );
                }
            }
        }
    }

    /**
     * Price, downloadable file, and sellable entry for a digital product.
     * Software products carry `meta.licensing` so orders issue keys.
     *
     * @since 1.0.0
     *
     * @param  int     $productId  Product id.
     * @param  string  $category   Category key.
     * @param  string  $locale     Content locale.
     * @param  string  $name       Product name.
     * @param  string  $slug       Product slug (used for the file path).
     * @param  string  $sku        SKU.
     * @param  bool    $isDraft    Whether the product is unpublished.
     *
     * @return void
     */
    protected function seedDigital( int $productId, string $category, string $locale, string $name, string $slug, string $sku, bool $isDraft ): void
    {
        [ $min, $max, $extension ] = match ( $category ) {
            'software' => [ 29, 99, 'zip' ],
            'music'    => [ 8, 15, 'zip' ],
            default    => [ 10, 25, 'pdf' ],
        };

        $price = $this->priceBetween( $min, $max );

        $this->insertPrices( ( new Product() )->getMorphClass(), $productId, $price );

        $fileId = (int) DB::table( 'digital_files' )->insertGetId( $this->stamp( [
            'product_id'        => $productId,
            'disk'              => 'local',
            'path'              => 'ecommerce-demo/' . $slug . '.' . $extension,
            'label'             => $name . ' (' . strtoupper( $extension ) . ')',
            'version'           => '1.' . $this->random->getInt( 0, 4 ) . '.0',
            'is_streaming_only' => false,
        ] ) );

        if ( ! $isDraft ) {
            $this->sellables[] = $this->sellable( $productId, null, DigitalProductType::KEY, $category, $name, $sku, $price, false, [], $fileId );
        }
    }

    /**
     * Seeds `$count` customers, rotating through the locales, each with a
     * default address (and some with a second one).
     *
     * @since 1.0.0
     *
     * @param  int  $count  Customers to create.
     *
     * @return void
     */
    protected function seedCustomers( int $count ): void
    {
        for ( $i = 0; $i < $count; $i++ ) {
            $locale  = DemoCatalog::LOCALES[ $i % count( DemoCatalog::LOCALES ) ];
            $first   = $this->pick( DemoCatalog::FIRST_NAMES[ $locale ] );
            $last    = $this->pick( DemoCatalog::LAST_NAMES[ $locale ] );
            $email   = Str::lower( Str::ascii( $first . '.' . $last ) ) . '.' . ( $i + 1 ) . '@example.test';
            $email   = (string) preg_replace( '/[^a-z0-9.@-]/', '', $email );
            $address = $this->address( $locale, $first, $last );
            $created = $this->now->copy()->subDays( $this->random->getInt( 91, 400 ) );
            $markets = $this->chance( 40 );

            $customerId = (int) DB::table( 'customers' )->insertGetId( [
                'email'                => $email,
                'first_name'           => $first,
                'last_name'            => $last,
                'phone'                => $address['phone'],
                'accepts_marketing'    => $markets,
                'accepts_marketing_at' => $markets ? $created : null,
                'total_spent_amount'   => 0,
                'total_spent_currency' => $this->baseCurrency,
                'orders_count'         => 0,
                'meta'                 => json_encode( [ 'demo' => true, 'locale' => $locale ] ),
                'created_at'           => $created,
                'updated_at'           => $created,
            ] );

            DB::table( 'customer_addresses' )->insert( $this->stamp( array_merge( $address, [
                'customer_id'         => $customerId,
                'label'               => 'Home',
                'is_default_shipping' => true,
                'is_default_billing'  => true,
            ] ) ) );

            if ( $this->chance( 20 ) ) {
                DB::table( 'customer_addresses' )->insert( $this->stamp( array_merge( $this->address( $locale, $first, $last ), [
                    'customer_id' => $customerId,
                    'label'       => 'Work',
                ] ) ) );
            }

            $this->customers[] = [ 'id' => $customerId, 'email' => $email, 'locale' => $locale, 'address' => $address ];
        }
    }

    /**
     * Seeds `$count` orders placed over the last 90 days. The first six
     * cover every system status; the rest are weighted by age (recent
     * orders skew pending/processing, older ones complete).
     *
     * @since 1.0.0
     *
     * @param  int  $count  Orders to create.
     *
     * @return void
     */
    protected function seedOrders( int $count ): void
    {
        $timeline = [];

        for ( $i = 0; $i < $count; $i++ ) {
            $placedAt = $this->now->copy()
                ->subDays( $this->random->getInt( 0, 89 ) )
                ->subMinutes( $this->random->getInt( 0, 23 * 60 ) );

            if ( $placedAt->greaterThan( $this->now ) ) {
                $placedAt = $this->now->copy();
            }

            $status = $i < count( self::SYSTEM_STATUSES )
                ? self::SYSTEM_STATUSES[ $i ]
                : $this->statusForAge( (int) $placedAt->diffInDays( $this->now ) );

            array_push( $timeline, ...$this->seedOrder( $placedAt, $status ) );

            if ( count( $timeline ) >= 500 ) {
                DB::table( 'order_timeline_entries' )->insert( $timeline );
                $timeline = [];
            }
        }

        if ( [] !== $timeline ) {
            DB::table( 'order_timeline_entries' )->insert( $timeline );
        }
    }

    /**
     * Seeds one order with its lines, shipments, refunds, license keys,
     * promotion usage, and review; returns its timeline rows.
     *
     * @since 1.0.0
     *
     * @param  Carbon  $placedAt  Placement time.
     * @param  string  $status    System status.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function seedOrder( Carbon $placedAt, string $status ): array
    {
        $guest    = $this->chance( 12 );
        $customer = $guest ? null : $this->pick( $this->customers );
        $locale   = $customer['locale'] ?? $this->pick( DemoCatalog::LOCALES );

        if ( null === $customer ) {
            $first   = $this->pick( DemoCatalog::FIRST_NAMES[ $locale ] );
            $last    = $this->pick( DemoCatalog::LAST_NAMES[ $locale ] );
            $address = $this->address( $locale, $first, $last );
            $email   = (string) preg_replace( '/[^a-z0-9.@-]/', '', Str::lower( Str::ascii( $first . '.' . $last ) ) . '.guest' . $this->random->getInt( 100, 999 ) . '@example.test' );
        } else {
            $address = $customer['address'];
            $email   = $customer['email'];
        }

        $currency = 'en' === $locale ? $this->baseCurrency : $this->secondaryCurrency;
        $country  = (string) $address['country_code'];
        $lines    = $this->pickLines();
        $physical = [] !== array_filter( $lines, static fn ( array $line ): bool => $line['sellable']['ships'] );

        // Processing means "waiting to ship", so it needs a line that ships;
        // digital-only orders complete as soon as they're paid.
        if ( ! $physical && 'processing' === $status ) {
            $shippable = array_values( array_filter( $this->sellables, static fn ( array $sellable ): bool => $sellable['ships'] ) );

            if ( [] === $shippable ) {
                $status = 'complete';
            } else {
                $lines[]  = [ 'sellable' => $this->pick( $shippable ), 'qty' => 1, 'discount' => 0, 'shipping' => 0 ];
                $physical = true;
            }
        }

        $paid          = in_array( $status, [ 'processing', 'complete', 'refunded' ], true );
        $partialRefund = 'complete' === $status && $this->chance( 10 );

        foreach ( $lines as &$line ) {
            $line['unit']     = $this->convert( (int) $line['sellable']['price'], $currency, (int) ( $line['sellable']['secondary'] ?? 0 ) );
            $line['subtotal'] = $line['unit'] * $line['qty'];
        }
        unset( $line );

        $subtotal = array_sum( array_column( $lines, 'subtotal' ) );
        $discount = 0;
        $promo    = null;

        if ( $subtotal >= $this->convert( 15_000, $currency ) && $placedAt->greaterThan( $this->now->copy()->subDays( 60 ) ) ) {
            $discount = intdiv( $subtotal, 10 );
            $promo    = 'demo-spring-sale';
        } elseif ( null !== $customer && $this->chance( 8 ) && $subtotal > $this->convert( 2_000, $currency ) ) {
            $discount = $this->convert( 1_000, $currency );
            $promo    = 'demo-welcome';
        }

        [ $methodKey, $shipping ] = $this->shippingFor( $physical, $subtotal - $discount, $currency, $country );

        $this->allocate( $lines, 'discount', $discount );
        $this->allocate( $lines, 'shipping', $shipping );

        $tax          = 0;
        $taxBreakdown = [];

        foreach ( $lines as &$line ) {
            [ $rate, $rateLabel ] = $this->taxRates[ $line['sellable']['tax_class'] . '.' . $country ] ?? [ 0, '' ];
            $line['tax']          = intdiv( ( $line['subtotal'] - $line['discount'] ) * $rate + 500_000_000, 1_000_000_000 );
            $line['total']        = $line['subtotal'] + $line['tax'] + $line['shipping'] - $line['discount'];
            $tax += $line['tax'];

            if ( $line['tax'] > 0 ) {
                $taxBreakdown[ $rateLabel . '|' . $rate ] ??= [ 'label' => $rateLabel, 'rate_ubps' => $rate, 'amount' => 0, 'country_code' => $country ];
                $taxBreakdown[ $rateLabel . '|' . $rate ]['amount'] += $line['tax'];
            }
        }
        unset( $line );

        $total           = $subtotal + $shipping + $tax - $discount;
        $refunded        = 'refunded' === $status ? $total : 0;
        $firstLineTotal  = (int) $lines[0]['total'];
        $refunded        = $partialRefund ? $firstLineTotal : $refunded;
        $paymentStatus   = match ( true ) {
            'refunded' === $status        => 'refunded',
            $partialRefund                => 'partially_refunded',
            'failed' === $status          => 'failed',
            $paid                         => 'paid',
            default                       => 'pending',
        };
        $shippedAll      = 'complete' === $status;
        $shippedPartial  = 'processing' === $status && $physical && $this->chance( 30 );
        $fulfillment     = match ( true ) {
            $shippedAll                   => 'fulfilled',
            $shippedPartial               => 'partial',
            ! $physical && $paid          => 'fulfilled',
            default                       => 'unfulfilled',
        };
        $substatusKey    = 'processing' === $status
            ? $this->pick( [ 'in-progress', 'picking', 'packed' ] )
            : self::DEFAULT_SUBSTATUSES[ $status ];
        $updatedAt       = $this->later( $placedAt, 1, 72 );

        $orderId = (int) DB::table( 'orders' )->insertGetId( [
            'order_number'            => $this->orderNumber(),
            'customer_id'             => $customer['id'] ?? null,
            'email'                   => $email,
            'phone'                   => $address['phone'],
            'system_status'           => $status,
            'substatus_id'            => $this->substatuses[ $status . '.' . $substatusKey ] ?? null,
            'payment_status'          => $paymentStatus,
            'fulfillment_status'      => $fulfillment,
            'currency'                => $currency,
            'base_currency'           => $this->baseCurrency,
            'fx_rate_to_base_e8'      => $this->fxToBase( $currency ),
            'subtotal_amount'         => $subtotal,
            'subtotal_currency'       => $currency,
            'discount_amount'         => $discount,
            'discount_currency'       => $currency,
            'tax_amount'              => $tax,
            'tax_currency'            => $currency,
            'shipping_amount'         => $shipping,
            'shipping_currency'       => $currency,
            'total_amount'            => $total,
            'total_currency'          => $currency,
            'total_refunded_amount'   => $refunded,
            'total_refunded_currency' => $currency,
            'shipping_address'        => $physical ? json_encode( $address ) : null,
            'billing_address'         => json_encode( $address ),
            'shipping_method_key'     => $methodKey,
            'payment_gateway_key'     => 'pending' === $paymentStatus ? null : 'stripe',
            'payment_reference'       => 'pending' === $paymentStatus ? null : 'pi_demo_' . bin2hex( $this->random->getBytes( 8 ) ),
            'ip_address'              => '203.0.113.' . $this->random->getInt( 1, 254 ),
            'customer_note'           => $this->chance( 10 ) ? $this->pick( DemoCatalog::CUSTOMER_NOTES[ $locale ] ) : null,
            'is_claimed'              => null !== $customer,
            'meta'                    => json_encode( [ 'demo' => true, 'locale' => $locale, 'tax_breakdown' => array_values( $taxBreakdown ) ] ),
            'placed_at'               => $placedAt,
            'created_at'              => $placedAt,
            'updated_at'              => $updatedAt,
        ] );

        foreach ( $lines as &$line ) {
            $itemStatus = match ( true ) {
                ! $line['sellable']['ships'] => $paid ? 'fulfilled' : 'unfulfilled',
                $shippedAll                  => 'fulfilled',
                default                      => 'unfulfilled',
            };

            $line['id'] = (int) DB::table( 'order_items' )->insertGetId( [
                'order_id'            => $orderId,
                'product_id'          => $line['sellable']['product_id'],
                'product_variant_id'  => $line['sellable']['variant_id'],
                'product_snapshot'    => json_encode( [
                    'type'    => $line['sellable']['type'],
                    'name'    => $line['sellable']['name'],
                    'sku'     => $line['sellable']['sku'],
                    'options' => (object) $line['sellable']['options'],
                ] ),
                'quantity'            => $line['qty'],
                'unit_price_amount'   => $line['unit'],
                'unit_price_currency' => $currency,
                'discount_amount'     => $line['discount'],
                'discount_currency'   => $currency,
                'tax_amount'          => $line['tax'],
                'tax_currency'        => $currency,
                'shipping_amount'     => $line['shipping'],
                'shipping_currency'   => $currency,
                'total_amount'        => $line['total'],
                'total_currency'      => $currency,
                'fulfillment_status'  => $itemStatus,
                'meta'                => json_encode( [ 'demo' => true ] ),
                'created_at'          => $placedAt,
                'updated_at'          => $updatedAt,
            ] );
        }
        unset( $line );

        $timeline = [ $this->timelineRow( $orderId, 'order.placed', [ 'total' => $total, 'currency' => $currency ], $placedAt ) ];

        if ( 'failed' === $status ) {
            $timeline[] = $this->timelineRow( $orderId, 'payment.capture_failed', [ 'reason' => 'card_declined' ], $placedAt->copy()->addMinute() );
            $timeline[] = $this->timelineRow( $orderId, 'order.status_changed', [ 'from' => 'pending', 'to' => 'failed', 'reason' => null ], $placedAt->copy()->addMinutes( 2 ) );
        }

        if ( 'cancelled' === $status ) {
            $timeline[] = $this->timelineRow( $orderId, 'order.status_changed', [ 'from' => 'pending', 'to' => 'cancelled', 'reason' => 'customer_request' ], $updatedAt );
        }

        if ( $paid ) {
            $timeline[] = $this->timelineRow( $orderId, 'payment.captured', [ 'amount' => $total, 'gateway' => 'stripe' ], $placedAt->copy()->addMinute() );
            $timeline[] = $this->timelineRow( $orderId, 'order.status_changed', [ 'from' => 'pending', 'to' => 'processing', 'reason' => null ], $placedAt->copy()->addMinutes( 2 ) );
            $this->issueLicenses( $lines, $placedAt );
        }

        if ( $physical && ( $shippedAll || $shippedPartial ) ) {
            $this->seedShipment( $orderId, $lines, $methodKey, $country, $placedAt, $shippedAll );
        }

        if ( in_array( $status, [ 'complete', 'refunded' ], true ) ) {
            $timeline[] = $this->timelineRow( $orderId, 'order.status_changed', [ 'from' => 'processing', 'to' => $status, 'reason' => null ], $updatedAt );
        }

        if ( $refunded > 0 ) {
            $this->seedRefund( $orderId, $partialRefund ? [ $lines[0] ] : $lines, $refunded, $currency, $updatedAt );
            $timeline[] = $this->timelineRow( $orderId, 'order.refunded', [ 'amount' => $refunded, 'currency' => $currency, 'payment_status' => $paymentStatus ], $updatedAt );
        }

        if ( null !== $promo ) {
            DB::table( 'promotion_usages' )->insert( [
                'promotion_id'      => $this->promotions[ $promo ],
                'order_id'          => $orderId,
                'customer_id'       => $customer['id'] ?? null,
                'amount_discounted' => $discount,
                'currency'          => $currency,
                'created_at'        => $placedAt,
            ] );
        }

        if ( 'complete' === $status && $this->chance( 35 ) ) {
            $this->seedReview( $orderId, $customer, $locale, $email, $lines[0]['sellable']['product_id'], $updatedAt );
        }

        return $timeline;
    }

    /**
     * Creates a shipment for every physical line (or just the first when
     * `$complete` is false) with the matching `shipment_items`.
     *
     * @since 1.0.0
     *
     * @param  int                               $orderId    Order id.
     * @param  array<int, array<string, mixed>>  $lines      Order lines.
     * @param  string|null                       $methodKey  Shipping method key.
     * @param  string                            $country    Destination country.
     * @param  Carbon                            $placedAt   Placement time.
     * @param  bool                              $complete   Whether every line shipped and was delivered.
     *
     * @return void
     */
    protected function seedShipment( int $orderId, array $lines, ?string $methodKey, string $country, Carbon $placedAt, bool $complete ): void
    {
        $carrier   = match ( $country ) {
            'US'    => 'UPS',
            'GB'    => 'Royal Mail',
            'ES'    => 'Correos',
            'FR'    => 'La Poste',
            default => 'DHL',
        };
        $shippedAt   = $this->later( $placedAt, 12, 72 );
        $deliveredAt = $complete ? $this->later( $shippedAt, 24, 120 ) : null;
        $tracking    = strtoupper( bin2hex( $this->random->getBytes( 6 ) ) );

        $shipmentId = (int) DB::table( 'shipments' )->insertGetId( [
            'order_id'        => $orderId,
            'method_key'      => $methodKey ?? 'flat-rate',
            'carrier'         => $carrier,
            'service'         => 'Standard',
            'tracking_number' => $tracking,
            'status'          => $complete ? 'delivered' : 'in_transit',
            'shipped_at'      => $shippedAt,
            'delivered_at'    => $deliveredAt,
            'meta'            => json_encode( [ 'demo' => true ] ),
            'created_at'      => $shippedAt,
            'updated_at'      => $deliveredAt ?? $shippedAt,
        ] );

        foreach ( $lines as $line ) {
            if ( ! $line['sellable']['ships'] ) {
                continue;
            }

            DB::table( 'shipment_items' )->insert( [
                'shipment_id'   => $shipmentId,
                'order_item_id' => $line['id'],
                'quantity'      => $line['qty'],
            ] );

            if ( ! $complete ) {
                DB::table( 'order_items' )->where( 'id', $line['id'] )->update( [ 'fulfillment_status' => 'fulfilled' ] );

                break;
            }
        }
    }

    /**
     * Records a refund covering `$lines`.
     *
     * @since 1.0.0
     *
     * @param  int                               $orderId   Order id.
     * @param  array<int, array<string, mixed>>  $lines     Refunded lines.
     * @param  int                               $amount    Refund amount.
     * @param  string                            $currency  Order currency.
     * @param  Carbon                            $at        Refund time.
     *
     * @return void
     */
    protected function seedRefund( int $orderId, array $lines, int $amount, string $currency, Carbon $at ): void
    {
        $refundId = (int) DB::table( 'refunds' )->insertGetId( [
            'order_id'          => $orderId,
            'amount'            => $amount,
            'currency'          => $currency,
            'reason'            => $this->pick( [ 'Customer request', 'Damaged in transit', 'Wrong size' ] ),
            'gateway_reference' => 're_demo_' . bin2hex( $this->random->getBytes( 8 ) ),
            'created_at'        => $at,
            'updated_at'        => $at,
        ] );

        foreach ( $lines as $line ) {
            DB::table( 'refund_items' )->insert( [
                'refund_id'     => $refundId,
                'order_item_id' => $line['id'],
                'quantity'      => $line['qty'],
                'amount'        => $line['total'],
                'currency'      => $currency,
                'restock'       => $line['sellable']['ships'],
            ] );
        }
    }

    /**
     * Issues license keys for paid lines whose product has licensing on.
     *
     * @since 1.0.0
     *
     * @param  array<int, array<string, mixed>>  $lines     Order lines.
     * @param  Carbon                            $placedAt  Placement time.
     *
     * @return void
     */
    protected function issueLicenses( array $lines, Carbon $placedAt ): void
    {
        foreach ( $lines as $line ) {
            if ( 'software' !== $line['sellable']['category'] ) {
                continue;
            }

            $issuedAt = $placedAt->copy()->addMinutes( 3 );

            DB::table( 'license_keys' )->insert( [
                'order_item_id'     => $line['id'],
                'digital_file_id'   => $line['sellable']['file_id'],
                'key'               => implode( '-', str_split( strtoupper( bin2hex( $this->random->getBytes( 8 ) ) ), 4 ) ),
                'activations_limit' => 3 * $line['qty'],
                'activations_count' => $this->random->getInt( 0, 2 ),
                'expires_at'        => $issuedAt->copy()->addDays( 365 ),
                'is_revoked'        => false,
                'meta'              => json_encode( [ 'demo' => true ] ),
                'created_at'        => $issuedAt,
                'updated_at'        => $issuedAt,
            ] );
        }
    }

    /**
     * Seeds a review on `$productId` in the shopper's locale.
     *
     * @since 1.0.0
     *
     * @param  int                        $orderId    Order the review comes from.
     * @param  array<string, mixed>|null  $customer   Customer, or null for a guest.
     * @param  string                     $locale     Shopper locale.
     * @param  string                     $email      Shopper email.
     * @param  int                        $productId  Reviewed product.
     * @param  Carbon                     $after      Earliest review time.
     *
     * @return void
     */
    protected function seedReview( int $orderId, ?array $customer, string $locale, string $email, int $productId, Carbon $after ): void
    {
        $rating           = $this->pick( [ 5, 5, 5, 4, 4, 4, 3, 2, 1 ] );
        $sentiment        = $rating >= 4 ? 'positive' : ( 3 === $rating ? 'mixed' : 'negative' );
        [ $title, $body ] = $this->pick( DemoCatalog::REVIEWS[ $locale ][ $sentiment ] );
        $status           = $this->chance( 85 ) ? 'approved' : 'pending';
        $at               = $this->later( $after, 24, 240 );
        $author           = explode( '@', $email )[0];

        DB::table( 'product_reviews' )->insert( [
            'product_id'           => $productId,
            'customer_id'          => $customer['id'] ?? null,
            'order_id'             => $orderId,
            'author_name'          => Str::headline( (string) preg_replace( '/[.\d]+/', ' ', $author ) ),
            'author_email'         => $email,
            'rating'               => $rating,
            'title'                => $title,
            'body'                 => $body,
            'is_verified_purchase' => true,
            'status'               => $status,
            'approved_at'          => 'approved' === $status ? $at : null,
            'created_at'           => $at,
            'updated_at'           => $at,
        ] );
    }

    /**
     * Seeds the "Fulfillment" board (physical orders) and the "Downloads"
     * board (digital-only orders) with columns, card widgets, sample
     * automations, and a card for every order whose status has a column.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function seedKanban(): void
    {
        $boards = [
            'demo-fulfillment' => [
                'name'     => 'Fulfillment',
                'rules'    => [ 'type' => 'cart-contains-product-type', 'config' => [ 'types' => [ SimpleProductType::KEY ] ] ],
                'columns'  => [
                    [ 'pending.awaiting-payment', null, [ 'total', 'customer', 'payment-status' ], null ],
                    [ 'processing.in-progress', 'To pick', [ 'total', 'item-count', 'shipping-method', 'days-in-column' ], 25 ],
                    [ 'processing.picking', null, [ 'item-count', 'shipping-method', 'days-in-column' ], 15 ],
                    [ 'processing.packed', null, [ 'shipping-method', 'fulfillment-status', 'days-in-column' ], null ],
                    [ 'complete.completed', 'Shipped', [ 'total', 'fulfillment-status' ], null ],
                ],
                'physical' => true,
            ],
            'demo-downloads'   => [
                'name'     => 'Downloads',
                'rules'    => [ 'type' => 'cart-contains-product-type', 'config' => [ 'types' => [ DigitalProductType::KEY ], 'match' => 'only' ] ],
                'columns'  => [
                    [ 'pending.awaiting-payment', 'Queued', [ 'total', 'customer' ], null ],
                    [ 'complete.completed', 'Delivered', [ 'total', 'payment-status' ], null ],
                ],
                'physical' => false,
            ],
        ];

        $position = 0;

        foreach ( $boards as $key => $definition ) {
            $boardId = (int) DB::table( 'kanban_boards' )->insertGetId( $this->stamp( [
                'key'           => $key,
                'name'          => $definition['name'],
                'description'   => 'Demo board seeded by ecommerce:seed-demo.',
                'routing_rules' => json_encode( $definition['rules'] ),
                'is_default'    => false,
                'is_active'     => true,
                'position'      => $position++,
                'settings'      => json_encode( [ 'default_card_widgets' => [ 'total', 'item-count', 'customer' ] ] ),
            ] ) );

            $columns = [];

            foreach ( $definition['columns'] as $index => [ $substatus, $label, $widgets, $wip ] ) {
                $columns[ $substatus ] = (int) DB::table( 'kanban_columns' )->insertGetId( $this->stamp( [
                    'board_id'       => $boardId,
                    'substatus_id'   => $this->substatuses[ $substatus ],
                    'label_override' => $label,
                    'position'       => $index,
                    'wip_limit'      => $wip,
                    'card_widgets'   => json_encode( $widgets ),
                ] ) );
            }

            $this->seedAutomations( $boardId, $columns, $definition['physical'] );
            $this->seedCards( $boardId, $definition['physical'] );
        }
    }

    /**
     * Sample automations for a board, using the core trigger keys.
     *
     * @since 1.0.0
     *
     * @param  int                 $boardId   Board id.
     * @param  array<string, int>  $columns   Column ids keyed by `status.substatus`.
     * @param  bool                $physical  Whether this is the fulfillment board.
     *
     * @return void
     */
    protected function seedAutomations( int $boardId, array $columns, bool $physical ): void
    {
        $automations = $physical
            ? [
                [ 'processing.in-progress', 'processing.picking', UpdateOrderFieldTrigger::KEY, [ 'field' => 'meta.picking_started', 'value' => true ] ],
                [ 'processing.picking', 'processing.packed', UpdateOrderFieldTrigger::KEY, [ 'field' => 'meta.packed', 'value' => true ] ],
                [ 'processing.packed', 'complete.completed', SendEmailTrigger::KEY, [ 'to' => 'customer', 'subject' => 'Order {order_number} is on its way', 'body' => 'Good news — order {order_number} has shipped.' ] ],
            ]
            : [
                [ null, 'complete.completed', SendEmailTrigger::KEY, [ 'to' => 'customer', 'subject' => 'Your downloads for order {order_number} are ready', 'body' => 'Sign in to your account to download your files.' ] ],
            ];

        foreach ( $automations as [ $from, $to, $trigger, $config ] ) {
            DB::table( 'kanban_automations' )->insert( $this->stamp( [
                'board_id'       => $boardId,
                'from_column_id' => null === $from ? null : $columns[ $from ],
                'to_column_id'   => $columns[ $to ],
                'trigger_key'    => $trigger,
                'trigger_config' => json_encode( $config ),
                'conditions'     => json_encode( [] ),
                'is_active'      => true,
            ] ) );
        }
    }

    /**
     * Puts every matching open or completed order on the board at its
     * current sub-status. Digital-only orders go to the Downloads board,
     * orders with a physical line to the Fulfillment board; cancelled,
     * refunded, and failed orders have no column and stay off the boards.
     *
     * @since 1.0.0
     *
     * @param  int   $boardId   Board id.
     * @param  bool  $physical  Whether this is the fulfillment board.
     *
     * @return void
     */
    protected function seedCards( int $boardId, bool $physical ): void
    {
        $columnSubstatuses = DB::table( 'kanban_columns' )->where( 'board_id', $boardId )->pluck( 'substatus_id' )->map( static fn ( mixed $id ): int => (int) $id )->all();
        $simpleType        = SimpleProductType::KEY;
        $withPhysical      = DB::table( 'order_items' )
            ->join( 'products', 'products.id', '=', 'order_items.product_id' )
            ->where( 'products.type', $simpleType )
            ->distinct()
            ->pluck( 'order_items.order_id' )
            ->mapWithKeys( static fn ( mixed $id ): array => [ (int) $id => true ] );

        $rows = [];

        foreach ( DB::table( 'orders' )->whereIn( 'substatus_id', $columnSubstatuses )->orderBy( 'id' )->get( [ 'id', 'substatus_id', 'placed_at', 'updated_at' ] ) as $order ) {
            if ( $physical !== $withPhysical->has( (int) $order->id ) ) {
                continue;
            }

            $rows[] = [
                'order_id'     => $order->id,
                'board_id'     => $boardId,
                'substatus_id' => $order->substatus_id,
                'assigned_at'  => $order->placed_at,
                'moved_at'     => $order->updated_at,
            ];
        }

        foreach ( array_chunk( $rows, 200 ) as $chunk ) {
            DB::table( 'order_board_assignments' )->insert( $chunk );
        }
    }

    /**
     * Recomputes customer order counts / spend / last order, product
     * rating aggregates, and promotion usage counts from the seeded rows.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function refreshAggregates(): void
    {
        $stats = DB::table( 'orders' )
            ->whereNotNull( 'customer_id' )
            ->whereIn( 'payment_status', [ 'paid', 'partially_refunded' ] )
            ->groupBy( 'customer_id' )
            ->selectRaw( 'customer_id, count(*) as orders_count, max(placed_at) as last_ordered_at' )
            ->get()
            ->keyBy( 'customer_id' );

        $spend = [];

        foreach ( DB::table( 'orders' )->whereNotNull( 'customer_id' )->whereIn( 'payment_status', [ 'paid', 'partially_refunded' ] )->get( [ 'customer_id', 'total_amount', 'total_refunded_amount', 'fx_rate_to_base_e8' ] ) as $order ) {
            $net                                = (int) $order->total_amount - (int) $order->total_refunded_amount;
            $spend[ (int) $order->customer_id ] = ( $spend[ (int) $order->customer_id ] ?? 0 ) + intdiv( $net * (int) $order->fx_rate_to_base_e8, 100_000_000 );
        }

        foreach ( $stats as $customerId => $row ) {
            DB::table( 'customers' )->where( 'id', $customerId )->update( [
                'orders_count'       => (int) $row->orders_count,
                'last_ordered_at'    => $row->last_ordered_at,
                'total_spent_amount' => $spend[ (int) $customerId ] ?? 0,
            ] );
        }

        $ratings = DB::table( 'product_reviews' )
            ->where( 'status', 'approved' )
            ->groupBy( 'product_id' )
            ->selectRaw( 'product_id, avg(rating) as avg_rating, count(*) as reviews_count' )
            ->get();

        foreach ( $ratings as $row ) {
            DB::table( 'products' )->where( 'id', $row->product_id )->update( [
                'avg_rating'    => round( (float) $row->avg_rating, 2 ),
                'reviews_count' => (int) $row->reviews_count,
            ] );
        }

        foreach ( $this->promotions as $promotionId ) {
            DB::table( 'promotions' )->where( 'id', $promotionId )->update( [
                'times_used' => DB::table( 'promotion_usages' )->where( 'promotion_id', $promotionId )->count(),
            ] );
        }
    }

    /**
     * Picks 1–4 distinct sellables with quantities (digital lines are
     * always quantity 1).
     *
     * @since 1.0.0
     *
     * @return array<int, array{sellable: array<string, mixed>, qty: int}>
     */
    protected function pickLines(): array
    {
        $count = min( count( $this->sellables ), $this->pick( [ 1, 1, 2, 2, 2, 3, 3, 4 ] ) );
        $keys  = $this->random->pickArrayKeys( $this->sellables, $count );
        $lines = [];

        foreach ( $keys as $key ) {
            $sellable = $this->sellables[ $key ];
            $lines[]  = [
                'sellable' => $sellable,
                'qty'      => $sellable['ships'] ? $this->pick( [ 1, 1, 1, 2, 2, 3 ] ) : 1,
                'discount' => 0,
                'shipping' => 0,
            ];
        }

        return $lines;
    }

    /**
     * Shipping method + amount for an order: free over 75, flat rate
     * otherwise, occasionally local pickup; nothing for digital-only.
     *
     * @since 1.0.0
     *
     * @param  bool    $physical    Whether any line ships.
     * @param  int     $afterPromo  Subtotal after discounts.
     * @param  string  $currency    Order currency.
     * @param  string  $country     Destination country.
     *
     * @return array{0: string|null, 1: int}
     */
    protected function shippingFor( bool $physical, int $afterPromo, string $currency, string $country ): array
    {
        if ( ! $physical ) {
            return [ null, 0 ];
        }

        if ( $this->chance( 5 ) ) {
            return [ 'local-pickup', 0 ];
        }

        if ( $afterPromo >= $this->convert( 7_500, $currency ) ) {
            return [ 'free-shipping', 0 ];
        }

        return [ 'flat-rate', $this->convert( 'US' === $country ? 599 : 899, $currency ) ];
    }

    /**
     * Spreads `$amount` over the lines proportionally by line subtotal,
     * pushing the rounding residual onto the last line (parent plan §16.7).
     *
     * @since 1.0.0
     *
     * @param  array<int, array<string, mixed>>  $lines   Order lines (by reference).
     * @param  string                            $field   Line field to write.
     * @param  int                               $amount  Amount to allocate.
     *
     * @return void
     */
    protected function allocate( array &$lines, string $field, int $amount ): void
    {
        $subtotal  = max( 1, array_sum( array_column( $lines, 'subtotal' ) ) );
        $remaining = $amount;
        $last      = array_key_last( $lines );

        foreach ( $lines as $key => &$line ) {
            $share          = $key === $last ? $remaining : intdiv( $amount * (int) $line['subtotal'], $subtotal );
            $line[ $field ] = $share;
            $remaining -= $share;
        }
        unset( $line );
    }

    /**
     * Status for an order placed `$daysAgo` days ago.
     *
     * @since 1.0.0
     *
     * @param  int  $daysAgo  Order age in days.
     *
     * @return string
     */
    protected function statusForAge( int $daysAgo ): string
    {
        $weights = match ( true ) {
            $daysAgo <= 3  => [ 'pending' => 30, 'processing' => 55, 'failed' => 10, 'cancelled' => 5 ],
            $daysAgo <= 14 => [ 'processing' => 35, 'complete' => 45, 'cancelled' => 8, 'refunded' => 7, 'failed' => 5 ],
            default        => [ 'complete' => 75, 'cancelled' => 8, 'refunded' => 12, 'failed' => 5 ],
        };

        $roll = $this->random->getInt( 1, array_sum( $weights ) );

        foreach ( $weights as $status => $weight ) {
            $roll -= $weight;

            if ( $roll <= 0 ) {
                return $status;
            }
        }

        return 'complete';
    }

    /**
     * Inserts the base-currency price row, sometimes a compare-at price,
     * and for every third item a secondary-currency row.
     *
     * @since 1.0.0
     *
     * @param  string  $morph   Priceable morph class.
     * @param  int     $id      Priceable id.
     * @param  int     $amount  Base-currency price.
     *
     * @return void
     */
    protected function insertPrices( string $morph, int $id, int $amount ): void
    {
        $onSale = $this->chance( 20 );
        $rows   = [
            $this->stamp( [
                'priceable_type'    => $morph,
                'priceable_id'      => $id,
                'currency'          => $this->baseCurrency,
                'price_amount'      => $amount,
                'compare_at_amount' => $onSale ? $this->roundPrice( (int) ( $amount * 1.25 ) ) : null,
                'cost_amount'       => intdiv( $amount * 45, 100 ),
            ] ),
        ];

        if ( 0 === $id % 3 ) {
            $rows[] = $this->stamp( [
                'priceable_type'    => $morph,
                'priceable_id'      => $id,
                'currency'          => $this->secondaryCurrency,
                'price_amount'      => $this->roundPrice( intdiv( $amount * self::SECONDARY_RATE_E8, 100_000_000 ) ),
                'compare_at_amount' => null,
                'cost_amount'       => null,
            ] );
        }

        DB::table( 'product_prices' )->insert( $rows );
    }

    /**
     * Inserts a tracked inventory row; a few items are low or out of stock.
     *
     * @since 1.0.0
     *
     * @param  string  $morph  Stockable morph class.
     * @param  int     $id     Stockable id.
     *
     * @return void
     */
    protected function insertStock( string $morph, int $id ): void
    {
        $roll = $this->random->getInt( 1, 20 );

        DB::table( 'inventory_items' )->insert( $this->stamp( [
            'stockable_type'      => $morph,
            'stockable_id'        => $id,
            'track_inventory'     => true,
            'quantity_on_hand'    => match ( true ) {
                1 === $roll => 0,
                2 === $roll => $this->random->getInt( 1, 4 ),
                default     => $this->random->getInt( 10, 150 ),
            },
            'quantity_reserved'   => 0,
            'allow_backorder'     => 3 === $roll,
            'low_stock_threshold' => 5,
        ] ) );
    }

    /**
     * Builds a sellable entry.
     *
     * @since 1.0.0
     *
     * @param  int                   $productId  Product id.
     * @param  int|null              $variantId  Variant id.
     * @param  string                $type       Product type key.
     * @param  string                $category   Category key.
     * @param  string                $name       Line name.
     * @param  string                $sku        Line SKU.
     * @param  int                   $price      Base-currency unit price.
     * @param  bool                  $ships      Whether the line needs shipping.
     * @param  array<string, mixed>  $options    Variant options.
     * @param  int|null              $fileId     Digital file id.
     *
     * @return array<string, mixed>
     */
    protected function sellable( int $productId, ?int $variantId, string $type, string $category, string $name, string $sku, int $price, bool $ships, array $options, ?int $fileId = null ): array
    {
        $priceableId = $variantId ?? $productId;

        return [
            'product_id' => $productId,
            'variant_id' => $variantId,
            'type'       => $type,
            'category'   => $category,
            'name'       => $name,
            'sku'        => $sku,
            'price'      => $price,
            'secondary'  => 0 === $priceableId % 3 ? $this->roundPrice( intdiv( $price * self::SECONDARY_RATE_E8, 100_000_000 ) ) : 0,
            'ships'      => $ships,
            'options'    => $options,
            'file_id'    => $fileId,
            'tax_class'  => 'ebooks' === $category ? 'reduced' : 'standard',
        ];
    }

    /**
     * A unique product name for the category and locale, suffixed with a
     * collection name once the base names are used up.
     *
     * @since 1.0.0
     *
     * @param  string                $category  Category key.
     * @param  string                $locale    Locale.
     * @param  array<string, true>   $used      Names already taken (by reference).
     *
     * @return string
     */
    protected function uniqueName( string $category, string $locale, array &$used ): string
    {
        $names = DemoCatalog::PRODUCT_NAMES[ $category ][ $locale ];

        for ( $attempt = 0; ; $attempt++ ) {
            $base = $names[ $attempt % count( $names ) ];
            $lap  = intdiv( $attempt, count( $names ) );
            $name = 0 === $lap ? $base : $base . ' ' . DemoCatalog::COLLECTIONS[ ( $lap - 1 ) % count( DemoCatalog::COLLECTIONS ) ]
                . ( $lap > count( DemoCatalog::COLLECTIONS ) ? ' ' . $lap : '' );

            if ( ! isset( $used[ $name ] ) ) {
                $used[ $name ] = true;

                return $name;
            }
        }
    }

    /**
     * A unique slug for `$name`.
     *
     * @since 1.0.0
     *
     * @param  string               $name  Product name.
     * @param  array<string, true>  $used  Slugs already taken (by reference).
     *
     * @return string
     */
    protected function uniqueSlug( string $name, array &$used ): string
    {
        $base = Str::slug( $name );
        $slug = $base;

        for ( $i = 2; isset( $used[ $slug ] ); $i++ ) {
            $slug = $base . '-' . $i;
        }

        $used[ $slug ] = true;

        return $slug;
    }

    /**
     * A random address in one of the locale's markets.
     *
     * @since 1.0.0
     *
     * @param  string  $locale  Locale.
     * @param  string  $first   First name.
     * @param  string  $last    Last name.
     *
     * @return array<string, string|null>
     */
    protected function address( string $locale, string $first, string $last ): array
    {
        $market            = DemoCatalog::MARKETS[ $locale ];
        $country           = $this->pick( $market['countries'] );
        $pool              = DemoCatalog::ADDRESSES[ $country ];
        $street            = $this->pick( $pool['streets'] );
        $number            = (string) $this->random->getInt( 1, 250 );
        [ $city, $region ] = $this->pick( $pool['cities'] );

        return [
            'first_name'   => $first,
            'last_name'    => $last,
            'company'      => null,
            'phone'        => $market['phone'] . ' ' . $this->random->getInt( 200, 999 ) . ' ' . $this->random->getInt( 100, 999 ) . ' ' . $this->random->getInt( 1000, 9999 ),
            'address1'     => $pool['street_first'] ? $street . ' ' . $number : $number . ' ' . $street,
            'address2'     => null,
            'city'         => $city,
            'region'       => $region,
            'region_code'  => $region,
            'postal_code'  => (string) preg_replace_callback( '/#/', fn (): string => (string) $this->random->getInt( 0, 9 ), $pool['postal'] ),
            'country_code' => $country,
        ];
    }

    /**
     * Converts a base-currency amount into `$currency`, preferring an
     * explicit secondary price when one exists.
     *
     * @since 1.0.0
     *
     * @param  int     $amount     Base-currency amount.
     * @param  string  $currency   Target currency.
     * @param  int     $explicit   Explicit secondary-currency price, or 0.
     *
     * @return int
     */
    protected function convert( int $amount, string $currency, int $explicit = 0 ): int
    {
        if ( $currency === $this->baseCurrency ) {
            return $amount;
        }

        return $explicit > 0 ? $explicit : intdiv( $amount * self::SECONDARY_RATE_E8, 100_000_000 );
    }

    /**
     * FX rate from `$currency` to the base currency, as E8.
     *
     * @since 1.0.0
     *
     * @param  string  $currency  Order currency.
     *
     * @return int
     */
    protected function fxToBase( string $currency ): int
    {
        return $currency === $this->baseCurrency
            ? 100_000_000
            : (int) round( 100_000_000 * 100_000_000 / self::SECONDARY_RATE_E8 );
    }

    /**
     * A retail price between `$min` and `$max` whole units, ending in .99.
     *
     * @since 1.0.0
     *
     * @param  int  $min  Minimum whole units.
     * @param  int  $max  Maximum whole units.
     *
     * @return int
     */
    protected function priceBetween( int $min, int $max ): int
    {
        return $this->random->getInt( $min, $max ) * 100 - 1;
    }

    /**
     * Rounds a minor-unit amount to the nearest .99 price point.
     *
     * @since 1.0.0
     *
     * @param  int  $amount  Minor units.
     *
     * @return int
     */
    protected function roundPrice( int $amount ): int
    {
        return max( 99, (int) round( $amount / 100 ) * 100 - 1 );
    }

    /**
     * A unique eight-character order number.
     *
     * @since 1.0.0
     *
     * @return string
     */
    protected function orderNumber(): string
    {
        do {
            $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            $number   = '';

            for ( $i = 0; $i < 8; $i++ ) {
                $number .= $alphabet[ $this->random->getInt( 0, strlen( $alphabet ) - 1 ) ];
            }
        } while ( isset( $this->orderNumbers[ $number ] ) );

        $this->orderNumbers[ $number ] = true;

        return $number;
    }

    /**
     * A time `$minHours`–`$maxHours` after `$from`, never after "now".
     *
     * @since 1.0.0
     *
     * @param  Carbon  $from      Start time.
     * @param  int     $minHours  Minimum offset.
     * @param  int     $maxHours  Maximum offset.
     *
     * @return Carbon
     */
    protected function later( Carbon $from, int $minHours, int $maxHours ): Carbon
    {
        $at = $from->copy()->addMinutes( $this->random->getInt( $minHours * 60, $maxHours * 60 ) );

        return $at->greaterThan( $this->now ) ? $this->now->copy()->max( $from ) : $at;
    }

    /**
     * A timeline row.
     *
     * @since 1.0.0
     *
     * @param  int                   $orderId  Order id.
     * @param  string                $type     Event type.
     * @param  array<string, mixed>  $payload  Payload.
     * @param  Carbon                $at       Event time.
     *
     * @return array<string, mixed>
     */
    protected function timelineRow( int $orderId, string $type, array $payload, Carbon $at ): array
    {
        return [
            'order_id'      => $orderId,
            'actor_user_id' => null,
            'event_type'    => $type,
            'payload'       => json_encode( $payload ),
            'created_at'    => $at,
        ];
    }

    /**
     * Adds `created_at` / `updated_at` stamps of "now".
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $row  Row values.
     *
     * @return array<string, mixed>
     */
    protected function stamp( array $row ): array
    {
        return $row + [ 'created_at' => $this->now, 'updated_at' => $this->now ];
    }

    /**
     * A random element of `$items`.
     *
     * @since 1.0.0
     *
     * @template T
     *
     * @param  array<array-key, T>  $items  Non-empty list.
     *
     * @return T
     */
    protected function pick( array $items ): mixed
    {
        $values = array_values( $items );

        return $values[ $this->random->getInt( 0, count( $values ) - 1 ) ];
    }

    /**
     * True `$percent`% of the time.
     *
     * @since 1.0.0
     *
     * @param  int  $percent  Probability, 0–100.
     *
     * @return bool
     */
    protected function chance( int $percent): bool
    {
        return $this->random->getInt( 1, 100 ) <= $percent;
    }
}
