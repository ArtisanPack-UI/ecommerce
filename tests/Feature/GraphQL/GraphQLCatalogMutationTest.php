<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductCategory;
use ArtisanPackUI\Ecommerce\Models\ProductPrice;
use ArtisanPackUI\Ecommerce\Models\ProductTag;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

require_once __DIR__ . '/GraphQLTestHelpers.php';

uses( RefreshDatabase::class );

beforeEach( function (): void {
    Gate::define( 'ecommerce.admin', fn ( $user ): bool => 1 === (int) $user->getAuthIdentifier() );
} );

const CREATE_PRODUCT = 'mutation ($input: CreateProductInput!) {
    createProduct(input: $input) { product { id name slug status prices { currency price { amount } } } errors { field code message } }
}';

it( 'refuses catalog mutations without the product abilities', function (): void {
    $this->actingAs( ecommerceShopperUser(), 'sanctum' );

    gql( $this, CREATE_PRODUCT, [ 'input' => [ 'type' => 'simple', 'name' => 'Mug' ] ] )
        ->assertJsonPath( 'errors.0.extensions.code', 'FORBIDDEN' );

    expect( Product::query()->count() )->toBe( 0 );
} );

it( 'creates, updates, and deletes a product', function (): void {
    $this->actingAs( new GenericUser( [ 'id' => 1 ] ), 'sanctum' );

    $id = gql( $this, CREATE_PRODUCT, [ 'input' => [
        'type'   => 'simple',
        'name'   => 'Desk Lamp',
        'status' => 'active',
        'prices' => [ [ 'currency' => 'USD', 'price_amount' => 4_900 ] ],
    ] ] )
        ->assertJsonMissingPath( 'errors' )
        ->assertJsonPath( 'data.createProduct.errors', [] )
        ->assertJsonPath( 'data.createProduct.product.slug', 'desk-lamp' )
        ->assertJsonPath( 'data.createProduct.product.prices.0.price.amount', 4_900 )
        ->json( 'data.createProduct.product.id' );

    gql( $this, 'mutation ($input: UpdateProductInput!) { updateProduct(input: $input) { product { name } errors { code } } }', [
        'input' => [ 'id' => $id, 'name' => 'Floor Lamp' ],
    ] )->assertJsonPath( 'data.updateProduct.product.name', 'Floor Lamp' );

    gql( $this, 'mutation ($input: DeleteProductInput!) { deleteProduct(input: $input) { deleted_id } }', [ 'input' => [ 'id' => $id ] ] )
        ->assertJsonPath( 'data.deleteProduct.deleted_id', (string) $id );

    expect( Product::query()->count() )->toBe( 0 );
} );

it( 'returns service refusals and validation failures as user errors', function (): void {
    $this->actingAs( new GenericUser( [ 'id' => 1 ] ), 'sanctum' );
    Product::factory()->create( [ 'slug' => 'taken' ] );

    gql( $this, CREATE_PRODUCT, [ 'input' => [ 'type' => 'simple', 'name' => 'X', 'slug' => 'taken' ] ] )
        ->assertJsonMissingPath( 'errors' )
        ->assertJsonPath( 'data.createProduct.errors.0.field', 'slug' )
        ->assertJsonPath( 'data.createProduct.errors.0.code', 'slug-taken' );

    gql( $this, CREATE_PRODUCT, [ 'input' => [ 'type' => 'simple', 'name' => 'X', 'status' => 'live' ] ] )
        ->assertJsonPath( 'data.createProduct.errors.0.field', 'status' );
} );

it( 'manages variants and prices', function (): void {
    $this->actingAs( new GenericUser( [ 'id' => 1 ] ), 'sanctum' );
    $product = Product::factory()->variable()->create();

    $variantId = gql( $this, 'mutation ($input: CreateProductVariantInput!) { createProductVariant(input: $input) { variant { id sku } errors { code } } }', [
        'input' => [ 'product_id' => $product->id, 'sku' => 'TEE-M', 'prices' => [ [ 'currency' => 'USD', 'price_amount' => 2_000 ] ] ],
    ] )->assertJsonPath( 'data.createProductVariant.variant.sku', 'TEE-M' )->json( 'data.createProductVariant.variant.id' );

    gql( $this, 'mutation ($input: UpdateProductVariantInput!) { updateProductVariant(input: $input) { variant { name } } }', [
        'input' => [ 'id' => $variantId, 'name' => 'Medium' ],
    ] )->assertJsonPath( 'data.updateProductVariant.variant.name', 'Medium' );

    $priceId = gql( $this, 'mutation ($input: CreateProductPriceInput!) { createProductPrice(input: $input) { price { id currency } errors { code } } }', [
        'input' => [ 'product_id' => $product->id, 'product_variant_id' => $variantId, 'currency' => 'EUR', 'price_amount' => 1_800 ],
    ] )->assertJsonPath( 'data.createProductPrice.price.currency', 'EUR' )->json( 'data.createProductPrice.price.id' );

    gql( $this, 'mutation ($input: UpdateProductPriceInput!) { updateProductPrice(input: $input) { price { price { amount } } } }', [
        'input' => [ 'id' => $priceId, 'price_amount' => 1_700 ],
    ] )->assertJsonPath( 'data.updateProductPrice.price.price.amount', 1_700 );

    gql( $this, 'mutation ($input: DeleteProductPriceInput!) { deleteProductPrice(input: $input) { deleted_id } }', [ 'input' => [ 'id' => $priceId ] ] );
    gql( $this, 'mutation ($input: DeleteProductVariantInput!) { deleteProductVariant(input: $input) { deleted_id } }', [ 'input' => [ 'id' => $variantId ] ] );

    expect( ProductVariant::query()->count() )->toBe( 0 )
        ->and( ProductPrice::query()->where( 'currency', 'EUR' )->count() )->toBe( 0 );
} );

it( 'manages categories and tags', function (): void {
    $this->actingAs( new GenericUser( [ 'id' => 1 ] ), 'sanctum' );

    $categoryId = gql( $this, 'mutation ($input: CreateCategoryInput!) { createCategory(input: $input) { category { id slug } } }', [
        'input' => [ 'name' => 'Prints' ],
    ] )->assertJsonPath( 'data.createCategory.category.slug', 'prints' )->json( 'data.createCategory.category.id' );

    gql( $this, 'mutation ($input: UpdateCategoryInput!) { updateCategory(input: $input) { category { name } } }', [
        'input' => [ 'id' => $categoryId, 'name' => 'Art prints' ],
    ] )->assertJsonPath( 'data.updateCategory.category.name', 'Art prints' );

    $tagId = gql( $this, 'mutation ($input: CreateTagInput!) { createTag(input: $input) { tag { id name } } }', [
        'input' => [ 'name' => 'Sale' ],
    ] )->assertJsonPath( 'data.createTag.tag.name', 'Sale' )->json( 'data.createTag.tag.id' );

    gql( $this, 'mutation ($input: UpdateTagInput!) { updateTag(input: $input) { tag { name } } }', [
        'input' => [ 'id' => $tagId, 'name' => 'On sale' ],
    ] )->assertJsonPath( 'data.updateTag.tag.name', 'On sale' );

    gql( $this, 'mutation ($input: DeleteCategoryInput!) { deleteCategory(input: $input) { deleted_id } }', [ 'input' => [ 'id' => $categoryId ] ] );
    gql( $this, 'mutation ($input: DeleteTagInput!) { deleteTag(input: $input) { deleted_id } }', [ 'input' => [ 'id' => $tagId ] ] );

    expect( ProductCategory::query()->count() )->toBe( 0 )->and( ProductTag::query()->count() )->toBe( 0 );
} );
