<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Exceptions\ProductWriteException;
use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductAttribute;
use ArtisanPackUI\Ecommerce\Models\ProductCategory;
use ArtisanPackUI\Ecommerce\Models\ProductChild;
use ArtisanPackUI\Ecommerce\Models\ProductImage;
use ArtisanPackUI\Ecommerce\Models\ProductPrice;
use ArtisanPackUI\Ecommerce\Models\ProductTag;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use ArtisanPackUI\Ecommerce\Models\TaxClass;
use ArtisanPackUI\Ecommerce\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses( RefreshDatabase::class );

/**
 * The error a write throws, as `field => code`.
 *
 * @param  Closure  $write  Write that should fail.
 *
 * @return array<string, string>
 */
function productWriteError( Closure $write ): array
{
    try {
        $write();
    } catch ( ProductWriteException $exception ) {
        return [ (string) $exception->errors[0]['field'] => $exception->errors[0]['code'] ];
    }

    throw new RuntimeException( 'Expected a ProductWriteException.' );
}

beforeEach( function (): void {
    $this->products = app( ProductService::class );
} );

it( 'creates a product with prices, taxonomy, gallery, and opening stock in one call', function (): void {
    $category = ProductCategory::factory()->create();
    $tag      = ProductTag::factory()->create();

    $product = $this->products->create( [
        'type'         => 'simple',
        'name'         => 'Linen Shirt',
        'status'       => 'active',
        'description'  => '<p>Soft</p><script>alert(1)</script>',
        'prices'       => [
            [ 'currency' => 'USD', 'price_amount' => 4500, 'compare_at_amount' => 5000 ],
            [ 'currency' => 'eur', 'price_amount' => 4200 ],
            [ 'currency' => 'USD', 'price_amount' => 3900, 'starts_at' => '2026-11-01 00:00:00', 'ends_at' => '2026-11-08 00:00:00' ],
        ],
        'category_ids' => [ $category->id ],
        'tag_ids'      => [ $tag->id ],
        'images'       => [ [ 'image_url' => 'https://example.test/a.jpg', 'alt_text' => 'Front' ] ],
        'inventory'    => [ 'track_inventory' => true, 'low_stock_threshold' => 3, 'quantity_on_hand' => 12 ],
    ] );

    expect( $product->slug )->toBe( 'linen-shirt' )
        ->and( $product->description )->not->toContain( '<script>' )
        ->and( $product->prices()->count() )->toBe( 3 )
        ->and( $product->prices()->where( 'currency', 'EUR' )->value( 'price_amount' ) )->toBe( 4200 )
        ->and( $product->categories->pluck( 'id' )->all() )->toBe( [ $category->id ] )
        ->and( $product->tags->pluck( 'id' )->all() )->toBe( [ $tag->id ] )
        ->and( $product->images->first()->alt_text )->toBe( 'Front' );

    $stock = InventoryItem::query()->where( 'stockable_id', $product->id )->first();

    expect( $stock->quantity_on_hand )->toBe( 12 )->and( $stock->low_stock_threshold )->toBe( 3 );
} );

it( 'fills unique slugs and refuses taken slugs and SKUs across products and variants', function (): void {
    Product::factory()->create( [ 'slug' => 'mug', 'sku' => 'MUG-1' ] );
    ProductVariant::factory()->create( [ 'sku' => 'VAR-1' ] );

    expect( $this->products->create( [ 'type' => 'simple', 'name' => 'Mug' ] )->slug )->toBe( 'mug-2' )
        ->and( productWriteError( fn () => $this->products->create( [ 'type' => 'simple', 'name' => 'X', 'slug' => 'mug' ] ) ) )->toBe( [ 'slug' => 'slug-taken' ] )
        ->and( productWriteError( fn () => $this->products->create( [ 'type' => 'simple', 'name' => 'X', 'sku' => 'MUG-1' ] ) ) )->toBe( [ 'sku' => 'sku-taken' ] )
        ->and( productWriteError( fn () => $this->products->create( [ 'type' => 'simple', 'name' => 'X', 'sku' => 'VAR-1' ] ) ) )->toBe( [ 'sku' => 'sku-taken' ] );
} );

it( 'validates the type, tax class, status, and price rows', function ( array $data, array $error ): void {
    TaxClass::query()->firstOrCreate( [ 'key' => 'standard' ], [ 'label' => 'Standard' ] );

    expect( productWriteError( fn () => $this->products->create( $data + [ 'type' => 'simple', 'name' => 'X' ] ) ) )->toBe( $error );
} )->with( [
    'unknown type'      => [ [ 'type' => 'nope' ], [ 'type' => 'unknown-type' ] ],
    'blank name'        => [ [ 'name' => ' ' ], [ 'name' => 'required' ] ],
    'unknown tax class' => [ [ 'tax_class_key' => 'luxury' ], [ 'tax_class_key' => 'unknown-tax-class' ] ],
    'bad status'        => [ [ 'status' => 'live' ], [ 'status' => 'invalid-status' ] ],
    'bad currency'      => [ [ 'prices' => [ [ 'currency' => 'X', 'price_amount' => 1 ] ] ], [ 'prices.0.currency' => 'invalid-currency' ] ],
    'negative amount'   => [ [ 'prices' => [ [ 'currency' => 'USD', 'price_amount' => -1 ] ] ], [ 'prices.0.price_amount' => 'invalid-amount' ] ],
    'missing amount'    => [ [ 'prices' => [ [ 'currency' => 'USD' ] ] ], [ 'prices.0.price_amount' => 'required' ] ],
    'backwards window'  => [ [ 'prices' => [ [ 'currency' => 'USD', 'price_amount' => 1, 'starts_at' => '2026-02-01', 'ends_at' => '2026-01-01' ] ] ], [ 'prices.0.ends_at' => 'invalid-window' ] ],
    'duplicate price'   => [ [ 'prices' => [ [ 'currency' => 'USD', 'price_amount' => 1 ], [ 'currency' => 'USD', 'price_amount' => 2 ] ] ], [ 'prices.1.currency' => 'duplicate-price' ] ],
    'image source'      => [ [ 'images' => [ [ 'alt_text' => 'x' ] ] ], [ 'images.0.image_url' => 'image-source' ] ],
    'image scheme'      => [ [ 'images' => [ [ 'image_url' => 'javascript:alert(1)' ] ] ], [ 'images.0.image_url' => 'invalid-url' ] ],
] );

it( 'rolls back the whole create when a related row fails', function (): void {
    productWriteError( fn () => $this->products->create( [
        'type'   => 'simple',
        'name'   => 'Half Made',
        'prices' => [ [ 'currency' => 'USD', 'price_amount' => 'abc' ] ],
    ] ) );

    expect( Product::query()->where( 'name', 'Half Made' )->exists() )->toBeFalse();
} );

it( 'updates only the sets that are present and adjusts stock with a reason', function (): void {
    $product = $this->products->create( [
        'type'      => 'simple',
        'name'      => 'Poster',
        'prices'    => [ [ 'currency' => 'USD', 'price_amount' => 1000 ] ],
        'inventory' => [ 'quantity_on_hand' => 5 ],
    ] );

    $this->products->update( $product, [ 'name' => 'Big Poster', 'stock_adjustment' => [ 'delta' => -2, 'reason' => 'Damaged' ] ] );

    expect( $product->refresh()->name )->toBe( 'Big Poster' )
        ->and( $product->prices()->count() )->toBe( 1 )
        ->and( $this->products->inventoryItemFor( $product )->quantity_on_hand )->toBe( 3 )
        ->and( productWriteError( fn () => $this->products->update( $product, [ 'stock_adjustment' => [ 'delta' => 1, 'reason' => '' ] ] ) ) )
        ->toBe( [ 'stock_adjustment.reason' => 'reason-required' ] );
} );

it( 'refuses to edit a product whose type is missing', function (): void {
    $product = Product::factory()->create( [ 'type' => 'subscription' ] );

    expect( productWriteError( fn () => $this->products->update( $product, [ 'name' => 'X' ] ) ) )->toBe( [ 'type' => 'type-missing' ] )
        ->and( productWriteError( fn () => $this->products->createVariant( $product, [] ) ) )->toBe( [ 'type' => 'type-missing' ] );
} );

it( 'fires the product lifecycle hooks', function (): void {
    $fired = [];

    foreach ( [ 'saving', 'saved', 'published', 'unpublished', 'deleted' ] as $hook ) {
        addAction( "ap.ecommerce.product.{$hook}", function () use ( &$fired, $hook ): void {
            $fired[] = $hook;
        } );
    }

    addAction( 'ap.ecommerce.variant.saved', function () use ( &$fired ): void {
        $fired[] = 'variant.saved';
    } );

    $product = $this->products->create( [ 'type' => 'variable', 'name' => 'Tee', 'status' => 'active' ] );
    $this->products->createVariant( $product, [ 'sku' => 'TEE-S' ] );
    $this->products->update( $product, [ 'status' => 'archived' ] );
    $this->products->delete( $product );

    expect( $fired )->toContain( 'saving', 'saved', 'published', 'variant.saved', 'unpublished', 'deleted' );
} );

it( 'deletes a product with its variants, prices, and stock rows', function (): void {
    $product = $this->products->create( [ 'type' => 'variable', 'name' => 'Tee', 'prices' => [ [ 'currency' => 'USD', 'price_amount' => 1 ] ] ] );
    $this->products->createVariant( $product, [ 'prices' => [ [ 'currency' => 'USD', 'price_amount' => 2 ] ], 'inventory' => [ 'quantity_on_hand' => 4 ] ] );

    $this->products->delete( $product );

    expect( ProductPrice::query()->count() )->toBe( 0 )
        ->and( InventoryItem::query()->count() )->toBe( 0 )
        ->and( ProductVariant::query()->count() )->toBe( 0 );
} );

it( 'builds attributes and generates every missing variant from the matrix', function (): void {
    $product = $this->products->create( [
        'type'       => 'variable',
        'name'       => 'Tee',
        'attributes' => [
            [ 'label' => 'Size', 'values' => [ [ 'label' => 'S' ], [ 'label' => 'M' ], [ 'label' => 'L' ] ] ],
            [ 'label' => 'Colour', 'values' => [ [ 'label' => 'Red', 'swatch' => '#f00' ], [ 'label' => 'Blue' ] ] ],
            [ 'label' => 'Material', 'is_variation' => false, 'values' => [ [ 'label' => 'Cotton' ] ] ],
        ],
    ] );

    expect( $this->products->variantMatrixSize( $product ) )->toBe( 6 );

    $first = $this->products->generateVariants( $product, [ 'prices' => [ [ 'currency' => 'USD', 'price_amount' => 1500 ] ] ] );
    $again = $this->products->generateVariants( $product );

    expect( $first )->toHaveCount( 6 )
        ->and( $again )->toHaveCount( 0 )
        ->and( $first->first()->name )->toBe( 'S / Red' )
        ->and( $first->first()->prices()->value( 'price_amount' ) )->toBe( 1500 )
        ->and( $product->productAttributes()->where( 'key', 'colour' )->first()->values->firstWhere( 'value', 'red' )->swatch )->toBe( '#f00' );
} );

it( 'refuses a duplicate option combination and options from another product', function (): void {
    $product = $this->products->create( [ 'type' => 'variable', 'name' => 'Tee', 'attributes' => [ [ 'label' => 'Size', 'values' => [ [ 'label' => 'S' ] ] ] ] ] );
    $other   = $this->products->create( [ 'type' => 'variable', 'name' => 'Cap', 'attributes' => [ [ 'label' => 'Size', 'values' => [ [ 'label' => 'S' ] ] ] ] ] );

    $size  = $product->productAttributes()->first();
    $small = $size->values()->first();
    $this->products->createVariant( $product, [ 'option_values' => [ $size->id => $small->id ] ] );

    $foreign = $other->productAttributes()->first();

    expect( productWriteError( fn () => $this->products->createVariant( $product, [ 'option_values' => [ $size->id => $small->id ] ] ) ) )->toBe( [ 'option_values' => 'duplicate-combination' ] )
        ->and( productWriteError( fn () => $this->products->createVariant( $product, [ 'option_values' => [ $foreign->id => $foreign->values()->first()->id ] ] ) ) )->toBe( [ "option_values.{$foreign->id}" => 'option-value' ] );
} );

it( 'syncs attributes by key and drops removed values', function (): void {
    $product = $this->products->create( [ 'type' => 'variable', 'name' => 'Tee', 'attributes' => [ [ 'label' => 'Size', 'values' => [ [ 'label' => 'S' ], [ 'label' => 'M' ] ] ] ] ] );
    $size    = $product->productAttributes()->first();

    $this->products->syncAttributes( $product, [ [ 'key' => 'size', 'label' => 'Shirt size', 'values' => [ [ 'label' => 'M' ], [ 'label' => 'XL' ] ] ] ] );

    $size->refresh();

    expect( ProductAttribute::query()->count() )->toBe( 1 )
        ->and( $size->label )->toBe( 'Shirt size' )
        ->and( $size->values()->orderBy( 'position' )->pluck( 'label' )->all() )->toBe( [ 'M', 'XL' ] );
} );

it( 'reorders variants and images', function (): void {
    $product = $this->products->create( [ 'type' => 'variable', 'name' => 'Tee', 'images' => [ [ 'image_url' => 'https://e.test/1.jpg' ], [ 'image_url' => 'https://e.test/2.jpg' ] ] ] );
    $a       = $this->products->createVariant( $product, [] );
    $b       = $this->products->createVariant( $product, [] );

    expect( $this->products->reorderVariants( $product, [ $b->id, $a->id ] )->pluck( 'id' )->all() )->toBe( [ $b->id, $a->id ] );

    $images = $product->images()->pluck( 'id' )->all();

    expect( $this->products->reorderImages( $product, array_reverse( $images ) )->pluck( 'id' )->all() )->toBe( array_reverse( $images ) )
        ->and( productWriteError( fn () => $this->products->reorderVariants( $product, [ 999 ] ) ) )->toBe( [ 'ids' => 'unknown-id' ] );
} );

it( 'upserts and updates single price rows', function (): void {
    $product = Product::factory()->create();

    $row = $this->products->upsertPrice( $product, [ 'currency' => 'USD', 'price_amount' => 100 ] );
    $this->products->upsertPrice( $product, [ 'currency' => 'USD', 'price_amount' => 150 ] );
    $sale = $this->products->upsertPrice( $product, [ 'currency' => 'USD', 'price_amount' => 90, 'starts_at' => '2026-12-01' ] );

    expect( $product->prices()->count() )->toBe( 2 )
        ->and( $row->refresh()->price_amount )->toBe( 150 )
        ->and( productWriteError( fn () => $this->products->updatePrice( $sale, [ 'starts_at' => null ] ) ) )->toBe( [ 'currency' => 'duplicate-price' ] );
} );

it( 'stores bundle members in order and refuses self, duplicates, foreign variants, and cycles', function (): void {
    $bundle = $this->products->create( [ 'type' => 'bundled', 'name' => 'Starter kit' ] );
    $mug    = Product::factory()->create();
    $tee    = Product::factory()->variable()->create();
    $small  = ProductVariant::factory()->create( [ 'product_id' => $tee->id ] );
    $other  = ProductVariant::factory()->create();

    $this->products->syncChildren( $bundle, [
        [ 'product_id' => $tee->id, 'variant_id' => $small->id, 'quantity' => 2 ],
        [ 'product_id' => $mug->id ],
    ] );

    expect( $bundle->children()->pluck( 'child_product_id' )->all() )->toBe( [ $tee->id, $mug->id ] )
        ->and( $bundle->children()->first()->quantity )->toBe( 2 )
        ->and( productWriteError( fn () => $this->products->syncChildren( $bundle, [ [ 'product_id' => $bundle->id ] ] ) ) )->toBe( [ 'children.0.product_id' => 'child-self' ] )
        ->and( productWriteError( fn () => $this->products->syncChildren( $bundle, [ [ 'product_id' => $mug->id ], [ 'product_id' => $mug->id ] ] ) ) )->toBe( [ 'children.1.product_id' => 'duplicate-child' ] )
        ->and( productWriteError( fn () => $this->products->syncChildren( $bundle, [ [ 'product_id' => $tee->id, 'variant_id' => $other->id ] ] ) ) )->toBe( [ 'children.0.variant_id' => 'child-variant' ] )
        ->and( productWriteError( fn () => $this->products->syncChildren( $bundle, [ [ 'product_id' => $mug->id, 'quantity' => 0 ] ] ) ) )->toBe( [ 'children.0.quantity' => 'invalid-quantity' ] );

    $outer = $this->products->create( [ 'type' => 'grouped', 'name' => 'Everything', 'children' => [ [ 'product_id' => $bundle->id ] ] ] );

    expect( productWriteError( fn () => $this->products->syncChildren( $bundle, [ [ 'product_id' => $outer->id ] ] ) ) )->toBe( [ 'children.0.product_id' => 'child-cycle' ] )
        ->and( productWriteError( fn () => $this->products->syncChildren( $mug, [ [ 'product_id' => $tee->id ] ] ) ) )->toBe( [ 'children' => 'not-a-parent' ] )
        ->and( ProductChild::query()->where( 'parent_product_id', $bundle->id )->count() )->toBe( 2 );
} );

it( 'stores a URL featured image in meta when there is no media library item', function (): void {
    $product = $this->products->create( [ 'type' => 'simple', 'name' => 'Lamp', 'featured_image_url' => 'https://e.test/lamp.jpg' ] );

    expect( $product->meta['featured_image_url'] )->toBe( 'https://e.test/lamp.jpg' );

    $this->products->update( $product, [ 'featured_image_url' => '' ] );

    expect( $product->refresh()->meta )->not->toHaveKey( 'featured_image_url' );
} );

it( 'adds, updates, and removes single gallery images', function (): void {
    $product = Product::factory()->create();
    $image   = $this->products->addImage( $product, [ 'media_id' => 7, 'alt_text' => 'Side' ] );

    $this->products->updateImage( $image, [ 'alt_text' => 'Back' ] );

    expect( $image->refresh()->alt_text )->toBe( 'Back' )->and( $image->image_url )->toBeNull();

    $this->products->removeImage( $image );

    expect( ProductImage::query()->count() )->toBe( 0 );
} );
