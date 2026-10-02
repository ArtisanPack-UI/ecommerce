<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductCategory;
use ArtisanPackUI\Ecommerce\Models\ProductPrice;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use ArtisanPackUI\Ecommerce\Models\Refund;
use ArtisanPackUI\Ecommerce\Models\RefundItem;

if ( ! function_exists( 'configureReportStore' ) ) {
    /**
     * Base USD, store time zone New York (app time zone UTC), and a
     * GBP → USD cross-rate of 1.25 for orders placed under the old base.
     */
    function configureReportStore(): void
    {
        config()->set( 'app.timezone', 'UTC' );
        config()->set( 'artisanpack.ecommerce.base_currency', 'USD' );
        config()->set( 'artisanpack.ecommerce.timezone', 'America/New_York' );
        config()->set( 'artisanpack.ecommerce.currency.provider', 'config' );
        config()->set( 'artisanpack.ecommerce.currency.rates', [ 'GBP' => [ 'USD' => 125_000_000 ] ] );
    }
}

if ( ! function_exists( 'reportOrder' ) ) {
    /**
     * An order whose every money column is in `$currency`.
     *
     * @param  array<string, mixed>  $attributes  Overrides.
     */
    function reportOrder( string $currency, array $attributes ): Order
    {
        $subtotal = (int) ( $attributes['subtotal_amount'] ?? 0 );
        $discount = (int) ( $attributes['discount_amount'] ?? 0 );
        $tax      = (int) ( $attributes['tax_amount'] ?? 0 );
        $shipping = (int) ( $attributes['shipping_amount'] ?? 0 );

        return Order::factory()->create( [
            'currency'                => $currency,
            'base_currency'           => 'USD',
            'fx_rate_to_base_e8'      => 100_000_000,
            'payment_status'          => 'paid',
            'system_status'           => 'processing',
            'fulfillment_status'      => 'fulfilled',
            'subtotal_currency'       => $currency,
            'discount_currency'       => $currency,
            'tax_currency'            => $currency,
            'shipping_currency'       => $currency,
            'total_currency'          => $currency,
            'total_refunded_currency' => $currency,
            'total_amount'            => $subtotal - $discount + $tax + $shipping,
            ...$attributes,
        ] );
    }
}

if ( ! function_exists( 'reportLine' ) ) {
    /**
     * An order line.
     */
    function reportLine( Order $order, Product $product, int $quantity, int $unit, int $discount = 0, ?ProductVariant $variant = null, array $options = [] ): OrderItem
    {
        return OrderItem::factory()->create( [
            'order_id'            => $order->id,
            'product_id'          => $product->id,
            'product_variant_id'  => $variant?->id,
            'product_snapshot'    => [ 'name' => $product->name, 'sku' => $variant?->sku ?? $product->sku, 'type' => 'simple', 'options' => $options ],
            'quantity'            => $quantity,
            'unit_price_amount'   => $unit,
            'unit_price_currency' => $order->currency,
            'discount_amount'     => $discount,
            'discount_currency'   => $order->currency,
            'tax_currency'        => $order->currency,
            'shipping_currency'   => $order->currency,
            'total_amount'        => $unit * $quantity - $discount,
            'total_currency'      => $order->currency,
        ] );
    }
}

if ( ! function_exists( 'seedReportFixtures' ) ) {
    /**
     * The fixed store the report tests assert against. Range
     * 2026-03-01 … 2026-03-03 (New York), base USD:
     *
     * | Order | Placed (UTC)      | NY day | Currency / base     | Subtotal | Disc | Tax  | Ship | Total  | In USD                         |
     * |-------|-------------------|--------|---------------------|----------|------|------|------|--------|--------------------------------|
     * | A     | 03-01 15:00       | 03-01  | USD / USD           | 10000    | 1000 | 900  | 500  | 10400  | as is                          |
     * | B     | 03-02 03:00       | 03-01  | EUR / USD @ 1.10    | 5000     | 0    | 0    | 0    | 5000   | 5500                           |
     * | C     | 03-02 15:00       | 03-02  | GBP / GBP (old base)| 2400     | 0    | 0    | 0    | 2400   | 3000 at today's 1.25, flagged  |
     * | D     | 03-02 16:00       | 03-02  | USD, payment pending| 99999    |      |      |      |        | excluded                       |
     * | E     | 03-03 12:00       | 03-03  | JPY / USD @ 0.0067  | 15000    | 0    | 1500 | 0    | 16500  | 10050 / tax 1005 / total 11055 |
     *
     * Refunds: A 2000 USD on 03-03 (NY); B 1000 EUR on 02-27 (previous period) = 1100 USD.
     *
     * @return array<string, mixed>
     */
    function seedReportFixtures(): array
    {
        $shirts = ProductCategory::factory()->create( [ 'name' => 'Shirts' ] );
        $mugs   = ProductCategory::factory()->create( [ 'name' => 'Mugs' ] );

        $tee = Product::factory()->create( [ 'name' => 'Tee', 'sku' => 'TEE' ] );
        $mug = Product::factory()->create( [ 'name' => 'Mug', 'sku' => 'MUG' ] );
        $pin = Product::factory()->create( [ 'name' => 'Pin', 'sku' => 'PIN' ] );

        $tee->categories()->attach( $shirts->id );
        $mug->categories()->attach( [ $shirts->id, $mugs->id ] );

        $blue  = ProductVariant::factory()->create( [ 'product_id' => $mug->id, 'name' => 'Blue', 'sku' => 'MUG-B' ] );
        $green = ProductVariant::factory()->create( [ 'product_id' => $mug->id, 'name' => 'Green', 'sku' => 'MUG-G' ] );

        $a = reportOrder( 'USD', [
            'placed_at'        => '2026-03-01 15:00:00',
            'subtotal_amount'  => 10_000,
            'discount_amount'  => 1_000,
            'tax_amount'       => 900,
            'shipping_amount'  => 500,
            'shipping_address' => [ 'country_code' => 'US', 'region_code' => 'NY', 'city' => 'Albany', 'address1' => '1 Main St' ],
            'meta'             => [ 'tax_breakdown' => [
                [ 'label' => 'State tax', 'rate_ubps' => 400_000, 'amount' => 600 ],
                [ 'label' => 'City tax', 'rate_ubps' => 200_000, 'amount' => 300 ],
            ] ],
            'system_status'      => 'processing',
            'fulfillment_status' => 'unfulfilled',
        ] );
        $b = reportOrder( 'EUR', [ 'placed_at' => '2026-03-02 03:00:00', 'fx_rate_to_base_e8' => 110_000_000, 'subtotal_amount' => 5_000 ] );
        $c = reportOrder( 'GBP', [ 'placed_at' => '2026-03-02 15:00:00', 'base_currency' => 'GBP', 'subtotal_amount' => 2_400 ] );
        reportOrder( 'USD', [ 'placed_at' => '2026-03-02 16:00:00', 'payment_status' => 'pending', 'subtotal_amount' => 99_999 ] );
        $e = reportOrder( 'JPY', [
            'placed_at'          => '2026-03-03 12:00:00',
            'fx_rate_to_base_e8' => 670_000,
            'subtotal_amount'    => 15_000,
            'tax_amount'         => 1_500,
            'shipping_address'   => [ 'country_code' => 'JP', 'region_code' => '13', 'city' => 'Tokyo', 'address1' => '1-1' ],
        ] );

        $teeLine = reportLine( $a, $tee, 2, 5_000, 1_000 );
        reportLine( $b, $mug, 1, 5_000, 0, $blue, [ 'Colour' => 'Blue' ] );
        reportLine( $c, $tee, 1, 2_000 );
        reportLine( $c, $pin, 1, 400 );
        reportLine( $e, $mug, 3, 5_000, 0, $green, [ 'Colour' => 'Green' ] );

        $refund = Refund::factory()->create( [ 'order_id' => $a->id, 'amount' => 2_000, 'currency' => 'USD', 'created_at' => '2026-03-03 14:00:00' ] );
        RefundItem::factory()->create( [ 'refund_id' => $refund->id, 'order_item_id' => $teeLine->id, 'quantity' => 1, 'amount' => 2_000, 'currency' => 'USD' ] );
        Refund::factory()->create( [ 'order_id' => $b->id, 'amount' => 1_000, 'currency' => 'EUR', 'created_at' => '2026-02-27 12:00:00' ] );

        // Inventory.
        InventoryItem::factory()->create( [ 'stockable_type' => $tee->getMorphClass(), 'stockable_id' => $tee->id, 'quantity_on_hand' => 10, 'quantity_reserved' => 2, 'low_stock_threshold' => 5 ] );
        InventoryItem::factory()->create( [ 'stockable_type' => $blue->getMorphClass(), 'stockable_id' => $blue->id, 'quantity_on_hand' => 3, 'quantity_reserved' => 1, 'low_stock_threshold' => 5 ] );
        InventoryItem::factory()->create( [ 'stockable_type' => $pin->getMorphClass(), 'stockable_id' => $pin->id, 'quantity_on_hand' => -2, 'quantity_reserved' => 0, 'low_stock_threshold' => null ] );
        InventoryItem::factory()->create( [ 'stockable_type' => $green->getMorphClass(), 'stockable_id' => $green->id, 'track_inventory' => false, 'quantity_on_hand' => 0, 'low_stock_threshold' => 5 ] );

        ProductPrice::factory()->create( [ 'priceable_type' => $tee->getMorphClass(), 'priceable_id' => $tee->id, 'currency' => 'USD', 'price_amount' => 2_500, 'cost_amount' => 1_500 ] );
        ProductPrice::factory()->create( [ 'priceable_type' => $mug->getMorphClass(), 'priceable_id' => $mug->id, 'currency' => 'USD', 'price_amount' => 1_800, 'cost_amount' => 800 ] );
        ProductPrice::factory()->create( [ 'priceable_type' => $pin->getMorphClass(), 'priceable_id' => $pin->id, 'currency' => 'EUR', 'price_amount' => 500, 'cost_amount' => 100 ] );

        return compact( 'shirts', 'mugs', 'tee', 'mug', 'pin', 'blue', 'green', 'a', 'b', 'c', 'e' );
    }
}
