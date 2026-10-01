<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductChild;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use ArtisanPackUI\Ecommerce\ProductTypes\BundledProductType;
use ArtisanPackUI\Ecommerce\ProductTypes\GroupedProductType;
use ArtisanPackUI\Ecommerce\ProductTypes\VariableProductType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses( RefreshDatabase::class );

// The grouped type is checked here rather than through the shared
// ProductTypeContractTest: that suite prices and snapshots a cart line, and
// a grouped product can never be a cart line (validateCartOptions() always
// throws).
it( 'never lets a grouped product into a cart', function (): void {
    $type = new GroupedProductType();

    expect( $type->key() )->toBe( 'grouped' )
        ->and( $type->label() )->not->toBe( '' )
        ->and( $type->requiresFulfillment() )->toBeFalse()
        ->and( $type->isInventoryTracked() )->toBeFalse()
        ->and( fn () => $type->validateCartOptions( Product::factory()->grouped()->create(), [] ) )->toThrow( InvalidArgumentException::class );
} );

it( 'requires a variant for a variable product', function (): void {
    expect( fn () => ( new VariableProductType() )->validateCartOptions( Product::factory()->variable()->create(), [] ) )
        ->toThrow( InvalidArgumentException::class );
} );

it( 'copies the bundle members into the order snapshot', function (): void {
    $bundle  = Product::factory()->bundled()->create();
    $mug     = Product::factory()->create( [ 'name' => 'Mug', 'sku' => 'MUG' ] );
    $tee     = Product::factory()->variable()->create( [ 'name' => 'Tee' ] );
    $variant = ProductVariant::factory()->create( [ 'product_id' => $tee->id, 'name' => 'Tee / M', 'sku' => 'TEE-M' ] );

    ProductChild::factory()->create( [ 'parent_product_id' => $bundle->id, 'child_product_id' => $tee->id, 'child_variant_id' => $variant->id, 'quantity' => 2, 'position' => 1 ] );
    ProductChild::factory()->create( [ 'parent_product_id' => $bundle->id, 'child_product_id' => $mug->id, 'position' => 0 ] );

    $item             = new CartItem();
    $item->product_id = $bundle->id;
    $item->options    = [];

    expect( ( new BundledProductType() )->buildOrderSnapshot( $item )['bundle'] )->toBe( [
        [ 'product_id' => $mug->id, 'variant_id' => null, 'name' => 'Mug', 'sku' => 'MUG', 'quantity' => 1 ],
        [ 'product_id' => $tee->id, 'variant_id' => $variant->id, 'name' => 'Tee / M', 'sku' => 'TEE-M', 'quantity' => 2 ],
    ] );
} );
