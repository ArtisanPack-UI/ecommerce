<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductAttribute;
use ArtisanPackUI\Ecommerce\Models\ProductAttributeValue;
use ArtisanPackUI\Ecommerce\Models\ProductPrice;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use ArtisanPackUI\Ecommerce\Models\ProductVariantOptionValue;
use ArtisanPackUI\Ecommerce\ProductTypes\DigitalProductType;
use ArtisanPackUI\Ecommerce\ProductTypes\MissingProductType;
use ArtisanPackUI\Ecommerce\ProductTypes\SimpleProductType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses( RefreshDatabase::class );

it( 'runs the products migration end-to-end', function (): void {
    $product = Product::factory()->simple()->create( [ 'slug' => 'a-thing' ] );

    expect( $product->exists )->toBeTrue();
    expect( $product->type )->toBe( 'simple' );
    expect( $product->slug )->toBe( 'a-thing' );
    expect( $product->is_taxable )->toBeTrue();
    expect( $product->status )->toBe( 'active' );
} );

it( 'resolves the ProductType for a row via the registry', function (): void {
    $simple  = Product::factory()->simple()->create();
    $digital = Product::factory()->digital()->create();

    expect( $simple->productType() )->toBeInstanceOf( SimpleProductType::class );
    expect( $digital->productType() )->toBeInstanceOf( DigitalProductType::class );
} );

it( 'returns MissingProductType for an unregistered type on a row', function (): void {
    $product       = Product::factory()->create();
    $product->type = 'ghost-type';
    $product->save();

    $type = $product->productType();

    expect( $type )->toBeInstanceOf( MissingProductType::class );
    expect( $type->key() )->toBe( 'ghost-type' );
} );

it( 'builds variants and attribute-value option pivots', function (): void {
    $product = Product::factory()->simple()->create();

    $attribute = ProductAttribute::factory()
        ->for( $product )
        ->create( [ 'key' => 'size', 'label' => 'Size' ] );

    $small = ProductAttributeValue::factory()
        ->for( $attribute, 'attribute' )
        ->create( [ 'value' => 'small', 'label' => 'Small' ] );

    $variant = ProductVariant::factory()
        ->for( $product )
        ->create();

    ProductVariantOptionValue::create( [
        'product_variant_id'         => $variant->id,
        'product_attribute_id'       => $attribute->id,
        'product_attribute_value_id' => $small->id,
    ] );

    $variant->refresh();
    expect( $variant->optionValues )->toHaveCount( 1 );
    expect( $variant->optionValues->first()->value->value )->toBe( 'small' );

    $product->refresh();
    expect( $product->productAttributes )->toHaveCount( 1 );
    expect( $product->variants )->toHaveCount( 1 );
} );

it( 'cascades deletes from a product to its variants and attributes', function (): void {
    $product   = Product::factory()->create();
    $variant   = ProductVariant::factory()->for( $product )->create();
    $attribute = ProductAttribute::factory()->for( $product )->create();

    $product->delete();

    expect( ProductVariant::find( $variant->id ) )->toBeNull();
    expect( ProductAttribute::find( $attribute->id ) )->toBeNull();
} );

it( 'stores per-currency prices polymorphically and hydrates them as Money', function (): void {
    $product = Product::factory()->create();

    ProductPrice::factory()
        ->forPriceable( $product )
        ->create( [ 'currency' => 'USD', 'price_amount' => 1999 ] );

    $row = $product->prices()->first();

    expect( $row->price->getAmount() )->toBe( '1999' );
    expect( $row->price->getCurrency()->getCode() )->toBe( 'USD' );
} );
