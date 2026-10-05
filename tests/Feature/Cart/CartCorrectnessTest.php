<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Checkout\CheckoutState;
use ArtisanPackUI\Ecommerce\Exceptions\CartOperationException;
use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\Ecommerce\Models\Coupon;
use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\Models\InventoryReservation;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductChild;
use ArtisanPackUI\Ecommerce\Models\ProductPrice;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use ArtisanPackUI\Ecommerce\Models\Promotion;
use ArtisanPackUI\Ecommerce\Models\PromotionAction;
use ArtisanPackUI\Ecommerce\Models\PromotionCondition;
use ArtisanPackUI\Ecommerce\Models\ShippingMethod;
use ArtisanPackUI\Ecommerce\Models\ShippingZone;
use ArtisanPackUI\Ecommerce\Models\TaxRate;
use ArtisanPackUI\Ecommerce\Services\StorefrontCartService;
use ArtisanPackUI\Ecommerce\ValueObjects\Address;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses( RefreshDatabase::class );

/**
 * A storefront-visible product priced at `$amount` in each given currency.
 *
 * @param  array<string, int>  $prices  Currency => amount.
 */
function correctnessProduct( array $prices = [ 'USD' => 1_000 ], array $attributes = [] ): Product
{
    $product = Product::factory()->create( $attributes );

    foreach ( $prices as $currency => $amount ) {
        ProductPrice::factory()->forPriceable( $product )->create( [ 'currency' => $currency, 'price_amount' => $amount ] );
    }

    return $product;
}

/**
 * A promotion with `type => config` conditions and actions.
 *
 * @param  array<int, array{0: string, 1: array}>  $conditions
 * @param  array<int, array{0: string, 1: array}>  $actions
 */
function correctnessPromotion( array $conditions, array $actions, ?string $coupon = null ): Promotion
{
    $promotion = Promotion::factory()->create( [ 'source_type' => null === $coupon ? 'automatic' : 'coupon' ] );

    foreach ( $conditions as [ $type, $config ] ) {
        PromotionCondition::factory()->create( [ 'promotion_id' => $promotion->id, 'type' => $type, 'config' => $config ] );
    }

    foreach ( $actions as [ $type, $config ] ) {
        PromotionAction::factory()->create( [ 'promotion_id' => $promotion->id, 'type' => $type, 'config' => $config ] );
    }

    if ( null !== $coupon ) {
        Coupon::factory()->create( [ 'promotion_id' => $promotion->id, 'code' => $coupon ] );
    }

    return $promotion;
}

/**
 * A US zone with the given shipping methods.
 *
 * @param  array<int, array<string, mixed>>  $methods
 */
function correctnessZone( array $methods ): ShippingZone
{
    $zone = ShippingZone::factory()->create( [ 'name' => 'US', 'country_codes' => [ 'US' ] ] );

    foreach ( $methods as $position => $method ) {
        ShippingMethod::factory()->create( array_merge( [ 'zone_id' => $zone->id, 'position' => $position ], $method ) );
    }

    return $zone;
}

function chicago(): Address
{
    return new Address( address1: '1 Main', city: 'Chicago', countryCode: 'US', regionCode: 'IL', postalCode: '60601' );
}

beforeEach( function (): void {
    $this->service = app( StorefrontCartService::class );
    $this->cart    = $this->service->create( 'USD' );
} );

describe( 'cart lifecycle', function (): void {
    it( 'sets an expiry on new carts and pushes it back on every change', function (): void {
        config()->set( 'artisanpack.ecommerce.cart.ttl_days', 7 );
        Carbon::setTestNow( '2026-01-01 12:00:00' );

        $cart = $this->service->create( 'USD', null, null, 'fr' );

        expect( $cart->expires_at->toDateTimeString() )->toBe( '2026-01-08 12:00:00' )
            ->and( $cart->locale )->toBe( 'fr' )
            ->and( $cart->checkout_state )->toBe( CheckoutState::NOT_STARTED );

        Carbon::setTestNow( '2026-01-05 12:00:00' );
        $cart->forceFill( [ 'abandoned_at' => Carbon::now() ] )->save();

        $this->service->addItem( $cart, correctnessProduct()->id, null, 1 );

        expect( $cart->refresh()->expires_at->toDateTimeString() )->toBe( '2026-01-12 12:00:00' )
            ->and( $cart->abandoned_at )->toBeNull();

        Carbon::setTestNow();
    } );

    it( 'refuses to create a cart in a currency the store does not sell in', function (): void {
        expect( fn () => $this->service->create( 'JPY' ) )
            ->toThrow( fn ( CartOperationException $e ) => expect( $e->errorCode )->toBe( 'currency-unavailable' ) );
    } );

    it( 'caps the number of distinct lines', function (): void {
        config()->set( 'artisanpack.ecommerce.cart.max_lines', 2 );

        $first = correctnessProduct();
        $this->service->addItem( $this->cart, $first->id, null, 1 );
        $this->service->addItem( $this->cart, correctnessProduct()->id, null, 1 );

        expect( fn () => $this->service->addItem( $this->cart, correctnessProduct()->id, null, 1 ) )
            ->toThrow( fn ( CartOperationException $e ) => expect( $e->errorCode )->toBe( 'line-limit' ) );

        // Adding more of an existing line is still fine.
        $this->service->addItem( $this->cart, $first->id, null, 1 );

        expect( $this->cart->items()->count() )->toBe( 2 );
    } );
} );

describe( 'options', function (): void {
    it( 'requires a variant for a variable product', function (): void {
        $product = correctnessProduct( [ 'USD' => 1_000 ], [ 'type' => 'variable' ] );

        expect( fn () => $this->service->addItem( $this->cart, $product->id, null, 1 ) )
            ->toThrow( fn ( CartOperationException $e ) => expect( $e->errorCode )->toBe( 'options-invalid' ) );
    } );

    it( 'rejects oversized option payloads at the API', function (): void {
        $product = correctnessProduct();
        $options = array_fill_keys( array_map( static fn ( int $i ): string => "o{$i}", range( 1, 21 ) ), 'x' );

        $this->postJson( "/api/ecommerce/v1/carts/{$this->cart->token}/items", [ 'product_id' => $product->id, 'quantity' => 1, 'options' => $options ], [ 'Idempotency-Key' => (string) Illuminate\Support\Str::uuid() ] )
            ->assertStatus( 422 );

        $this->postJson( "/api/ecommerce/v1/carts/{$this->cart->token}/items", [ 'product_id' => $product->id, 'quantity' => 1, 'options' => [ 'note' => str_repeat( 'x', 256 ) ] ], [ 'Idempotency-Key' => (string) Illuminate\Support\Str::uuid() ] )
            ->assertStatus( 422 );

        expect( CartItem::query()->count() )->toBe( 0 );
    } );
} );

describe( 'stock', function (): void {
    it( 'refuses more units than are in stock, counting reservations held by others', function (): void {
        $product = correctnessProduct();
        $item    = InventoryItem::factory()->create( [ 'stockable_type' => $product->getMorphClass(), 'stockable_id' => $product->id, 'quantity_on_hand' => 5, 'quantity_reserved' => 2 ] );
        InventoryReservation::factory()->create( [ 'inventory_item_id' => $item->id, 'quantity' => 2 ] );

        expect( fn () => $this->service->addItem( $this->cart, $product->id, null, 4 ) )
            ->toThrow( fn ( CartOperationException $e ) => expect( $e->errorCode )->toBe( 'insufficient-stock' )->and( $e->getMessage() )->toContain( '3' ) );

        $line = $this->service->addItem( $this->cart, $product->id, null, 3 );

        expect( fn () => $this->service->updateItem( $this->cart, $line, 4 ) )
            ->toThrow( CartOperationException::class );
    } );

    it( 'checks the variant\'s own stock', function (): void {
        $product = correctnessProduct( [ 'USD' => 1_000 ], [ 'type' => 'variable' ] );
        $variant = ProductVariant::factory()->create( [ 'product_id' => $product->id ] );
        InventoryItem::factory()->create( [ 'stockable_type' => $variant->getMorphClass(), 'stockable_id' => $variant->id, 'quantity_on_hand' => 0 ] );

        expect( fn () => $this->service->addItem( $this->cart, $product->id, $variant->id, 1 ) )
            ->toThrow( fn ( CartOperationException $e ) => expect( $e->getMessage() )->toBe( 'That item is out of stock.' ) );
    } );

    it( 'limits a bundle by its scarcest member', function (): void {
        $bundle = Product::factory()->bundled()->create();
        ProductPrice::factory()->forPriceable( $bundle )->create( [ 'currency' => 'USD', 'price_amount' => 5_000 ] );
        $mug = Product::factory()->create();
        InventoryItem::factory()->create( [ 'stockable_type' => $mug->getMorphClass(), 'stockable_id' => $mug->id, 'quantity_on_hand' => 5 ] );
        ProductChild::factory()->create( [ 'parent_product_id' => $bundle->id, 'child_product_id' => $mug->id, 'quantity' => 2 ] );

        expect( $this->service->addItem( $this->cart, $bundle->id, null, 2 )->quantity )->toBe( 2 );

        expect( fn () => $this->service->addItem( $this->cart, $bundle->id, null, 1 ) )
            ->toThrow( fn ( CartOperationException $e ) => expect( $e->errorCode )->toBe( 'insufficient-stock' ) );
    } );

    it( 'does not limit untracked or backorderable items', function ( array $attributes ): void {
        $product = correctnessProduct();
        InventoryItem::factory()->create( [ 'stockable_type' => $product->getMorphClass(), 'stockable_id' => $product->id, 'quantity_on_hand' => 0 ] + $attributes );

        expect( $this->service->addItem( $this->cart, $product->id, null, 50 )->quantity )->toBe( 50 );
    } )->with( [
        'untracked'     => [ [ 'track_inventory' => false ] ],
        'backorderable' => [ [ 'allow_backorder' => true ] ],
    ] );
} );

describe( 'promotions', function (): void {
    it( 're-prices lines before checking a coupon', function (): void {
        $product = correctnessProduct( [ 'USD' => 4_000 ] );
        correctnessPromotion( [ [ 'min-subtotal', [ 'amount' => 5_000 ] ] ], [ [ 'percent-off-cart', [ 'percent' => 10 ] ] ], 'BIG10' );

        $this->service->addItem( $this->cart, $product->id, null, 1 );
        ProductPrice::query()->where( 'priceable_id', $product->id )->update( [ 'price_amount' => 6_000 ] );

        $this->service->applyCoupon( $this->cart, 'big10' );

        expect( $this->cart->refresh()->subtotal_amount )->toBe( 6_000 )
            ->and( $this->cart->discount_amount )->toBe( 600 );
    } );

    it( 'stores each line\'s share of the discount', function (): void {
        $a = correctnessProduct( [ 'USD' => 3_000 ] );
        $b = correctnessProduct( [ 'USD' => 1_000 ] );
        correctnessPromotion( [], [ [ 'percent-off-cart', [ 'percent' => 10 ] ] ] );

        $lineA = $this->service->addItem( $this->cart, $a->id, null, 1 );
        $lineB = $this->service->addItem( $this->cart, $b->id, null, 1 );

        expect( $lineA->refresh()->discount_amount + $lineB->refresh()->discount_amount )->toBe( 400 )
            ->and( $lineA->discount_amount )->toBe( 300 )
            ->and( $this->cart->refresh()->discount_amount )->toBe( 400 );
    } );

    it( 'adds a free-item promotion\'s gift as a locked zero-priced line and removes it when the cart stops qualifying', function (): void {
        $product = correctnessProduct( [ 'USD' => 3_000 ] );
        $gift    = correctnessProduct( [ 'USD' => 800 ] );
        $promo   = correctnessPromotion( [ [ 'min-subtotal', [ 'amount' => 5_000 ] ] ], [ [ 'add-free-item', [ 'product_id' => $gift->id, 'quantity' => 1 ] ] ] );

        $line = $this->service->addItem( $this->cart, $product->id, null, 2 );

        $free = $this->cart->items()->get()->first( fn ( CartItem $item ): bool => $item->isFreeItem() );

        expect( $free )->not->toBeNull()
            ->and( $free->product_id )->toBe( $gift->id )
            ->and( $free->unit_price_amount )->toBe( 0 )
            ->and( $free->meta['promotion_id'] )->toBe( $promo->id )
            ->and( $this->cart->refresh()->subtotal_amount )->toBe( 6_000 )
            ->and( $this->cart->total_amount )->toBe( 6_000 );

        expect( fn () => $this->service->updateItem( $this->cart, $free, 3 ) )
            ->toThrow( fn ( CartOperationException $e ) => expect( $e->errorCode )->toBe( 'item-locked' ) );

        $this->service->updateItem( $this->cart, $line, 1 );

        expect( $this->cart->items()->count() )->toBe( 1 );
    } );

    it( 'zeroes shipping when a promotion grants free shipping and does not count free lines toward the line limit', function (): void {
        correctnessZone( [ [ 'key' => 'flat-rate', 'label' => 'Standard', 'config' => [ 'amount' => 500 ] ] ] );
        correctnessPromotion( [ [ 'min-subtotal', [ 'amount' => 5_000 ] ] ], [ [ 'free-shipping', [] ] ] );
        $product = correctnessProduct( [ 'USD' => 3_000 ] );

        $line = $this->service->addItem( $this->cart, $product->id, null, 1 );
        $rate = $this->service->quoteShipping( $this->cart, chicago() )->first();
        $this->service->selectShippingMethod( $this->cart, chicago(), $rate->id() );

        expect( $this->cart->refresh()->shipping_amount )->toBe( 500 );

        $this->service->updateItem( $this->cart, $line, 2 );

        expect( $this->cart->refresh()->shipping_amount )->toBe( 0 )
            ->and( $this->cart->meta )->toHaveKey( StorefrontCartService::FREE_SHIPPING_META_KEY )
            ->and( $this->cart->total_amount )->toBe( 6_000 );
    } );
} );

describe( 'shipping re-validation', function (): void {
    it( 'keeps the chosen rate when it is still offered at the same price', function (): void {
        correctnessZone( [ [ 'key' => 'flat-rate', 'label' => 'Standard', 'config' => [ 'amount' => 500 ] ] ] );
        $product = correctnessProduct();

        $line = $this->service->addItem( $this->cart, $product->id, null, 1 );
        $this->service->selectShippingMethod( $this->cart, chicago(), $this->service->quoteShipping( $this->cart, chicago() )->first()->id() );
        $this->service->updateItem( $this->cart, $line, 3 );

        expect( $this->cart->refresh()->shipping_amount )->toBe( 500 )
            ->and( $this->cart->meta )->toHaveKey( StorefrontCartService::SHIPPING_RATE_META_KEY )
            ->and( $this->cart->meta )->not->toHaveKey( StorefrontCartService::SHIPPING_INVALIDATED_META_KEY );
    } );

    it( 'drops a rate whose price changed and steps checkout back to shipping selection', function (): void {
        correctnessZone( [ [ 'key' => 'price-based', 'label' => 'Tiered', 'config' => [ 'tiers' => [
            [ 'min_subtotal' => 0, 'amount' => 500 ],
            [ 'min_subtotal' => 5_000, 'amount' => 900 ],
        ] ] ] ] );
        $product = correctnessProduct( [ 'USD' => 2_000 ] );

        $line = $this->service->addItem( $this->cart, $product->id, null, 1 );
        $this->service->selectShippingMethod( $this->cart, chicago(), $this->service->quoteShipping( $this->cart, chicago() )->first()->id() );
        $this->cart->forceFill( [ 'checkout_state' => CheckoutState::PAYMENT_SELECTION ] )->save();

        $this->service->updateItem( $this->cart, $line, 3 );

        expect( $this->cart->refresh()->shipping_amount )->toBe( 0 )
            ->and( $this->cart->meta )->not->toHaveKey( StorefrontCartService::SHIPPING_RATE_META_KEY )
            ->and( $this->cart->meta[ StorefrontCartService::SHIPPING_INVALIDATED_META_KEY ] )->toBeTrue()
            ->and( $this->cart->checkout_state )->toBe( CheckoutState::SHIPPING_SELECTION );
    } );

    it( 'qualifies free shipping thresholds on the discounted subtotal', function (): void {
        correctnessZone( [ [ 'key' => 'free-shipping', 'label' => 'Free', 'config' => [ 'min_subtotal' => 5_000 ] ] ] );
        correctnessPromotion( [], [ [ 'fixed-off-cart', [ 'amount' => 1_000 ] ] ] );
        $product = correctnessProduct( [ 'USD' => 5_500 ] );

        $this->service->addItem( $this->cart, $product->id, null, 1 );

        expect( $this->service->quoteShipping( $this->cart, chicago() ) )->toBeEmpty();
    } );
} );

describe( 'tax', function (): void {
    it( 'estimates zero tax until a destination is known, then taxes each line after its discount', function (): void {
        TaxRate::factory()->create( [ 'rate_ubps' => 100_000_000 ] );
        correctnessPromotion( [], [ [ 'percent-off-cart', [ 'percent' => 50 ] ] ] );
        $product = correctnessProduct( [ 'USD' => 2_000 ] );

        $line = $this->service->addItem( $this->cart, $product->id, null, 1 );

        expect( $this->cart->refresh()->tax_amount )->toBe( 0 )
            ->and( $this->cart->meta[ StorefrontCartService::TAX_META_KEY ]['estimated'] )->toBeTrue();

        $this->service->updateDetails( $this->cart, [ 'shipping_address' => chicago()->toArray() ] );

        expect( $this->cart->refresh()->tax_amount )->toBe( 100 )
            ->and( $line->refresh()->tax_amount )->toBe( 100 )
            ->and( $this->cart->meta[ StorefrontCartService::TAX_META_KEY ]['estimated'] )->toBeFalse()
            ->and( $this->cart->total_amount )->toBe( 2_000 - 1_000 + 100 );
    } );

    it( 'does not add tax on top of tax-inclusive prices', function (): void {
        config()->set( 'artisanpack.ecommerce.tax.prices_include_tax', true );
        TaxRate::factory()->create( [ 'rate_ubps' => 250_000_000 ] );

        $this->service->addItem( $this->cart, correctnessProduct( [ 'USD' => 1_250 ] )->id, null, 1 );
        $this->service->updateDetails( $this->cart, [ 'billing_address' => chicago()->toArray() ] );

        expect( $this->cart->refresh()->tax_amount )->toBe( 250 )
            ->and( $this->cart->total_amount )->toBe( 1_250 );
    } );

    it( 'rejects an address without a valid country', function (): void {
        expect( fn () => $this->service->updateDetails( $this->cart, [ 'shipping_address' => [ 'address1' => '1 Main', 'country_code' => 'USA' ] ] ) )
            ->toThrow( fn ( CartOperationException $e ) => expect( $e->errorCode )->toBe( 'country-invalid' ) );
    } );
} );

describe( 'currency switch', function (): void {
    beforeEach( function (): void {
        config()->set( 'artisanpack.ecommerce.currency.enabled', [ 'EUR' ] );
    } );

    it( 're-prices every line and drops shipping and the payment session', function (): void {
        correctnessZone( [ [ 'key' => 'flat-rate', 'label' => 'Standard', 'config' => [ 'amount' => 500 ] ] ] );
        $product = correctnessProduct( [ 'USD' => 1_000, 'EUR' => 900 ] );

        $this->service->addItem( $this->cart, $product->id, null, 2 );
        $this->service->selectShippingMethod( $this->cart, chicago(), $this->service->quoteShipping( $this->cart, chicago() )->first()->id() );
        $this->cart->forceFill( [ 'payment_reference' => 'pi_1', 'payment_gateway_key' => 'stripe', 'checkout_state' => CheckoutState::PAYMENT_PENDING ] )->save();

        $this->service->changeCurrency( $this->cart, 'eur' );

        $cart = $this->cart->refresh();

        expect( $cart->currency )->toBe( 'EUR' )
            ->and( $cart->total_currency )->toBe( 'EUR' )
            ->and( $cart->subtotal_amount )->toBe( 1_800 )
            ->and( $cart->shipping_amount )->toBe( 0 )
            ->and( $cart->payment_reference )->toBeNull()
            ->and( $cart->checkout_state )->toBe( CheckoutState::SHIPPING_SELECTION )
            ->and( $cart->items()->sole()->unit_price_currency )->toBe( 'EUR' );
    } );

    it( 'refuses a currency that is not enabled', function (): void {
        expect( fn () => $this->service->changeCurrency( $this->cart, 'GBP' ) )
            ->toThrow( fn ( CartOperationException $e ) => expect( $e->errorCode )->toBe( 'currency-unavailable' ) );
    } );

    it( 'leaves the cart unchanged when a line has no price in the new currency', function (): void {
        $product = correctnessProduct( [ 'USD' => 1_000 ] );
        $this->service->addItem( $this->cart, $product->id, null, 1 );

        expect( fn () => $this->service->changeCurrency( $this->cart, 'EUR' ) )
            ->toThrow( fn ( CartOperationException $e ) => expect( $e->errorCode )->toBe( 'price-unavailable' ) );

        expect( $this->cart->refresh()->currency )->toBe( 'USD' );
    } );
} );

describe( 'coupon errors', function (): void {
    it( 'hides whether an inactive coupon exists unless verbose errors are on', function ( bool $verbose, string $code ): void {
        config()->set( 'artisanpack.ecommerce.promotions.verbose_coupon_errors', $verbose );
        $promotion = correctnessPromotion( [], [ [ 'percent-off-cart', [ 'percent' => 10 ] ] ], 'OLD' );
        $promotion->forceFill( [ 'is_active' => false ] )->save();

        $this->service->addItem( $this->cart, correctnessProduct()->id, null, 1 );

        expect( fn () => $this->service->applyCoupon( $this->cart, 'OLD' ) )
            ->toThrow( fn ( CartOperationException $e ) => expect( $e->errorCode )->toBe( $code ) );
    } )->with( [
        'default' => [ false, 'coupon-invalid' ],
        'verbose' => [ true, 'coupon-inactive' ],
    ] );
} );
