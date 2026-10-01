<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductAttribute;
use ArtisanPackUI\Ecommerce\Models\ProductCategory;
use ArtisanPackUI\Ecommerce\Models\ProductImage;
use ArtisanPackUI\Ecommerce\Models\ProductPrice;
use ArtisanPackUI\Ecommerce\Models\ProductTag;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/ApiTestHelpers.php';

uses( RefreshDatabase::class );

const PRODUCTS_ADMIN_API = '/api/ecommerce/v1/admin/products';

it( 'requires auth, the product abilities, and an Idempotency-Key', function (): void {
    $this->getJson( PRODUCTS_ADMIN_API )->assertUnauthorized();
    $this->actingAs( ecommerceShopper(), 'sanctum' )->getJson( PRODUCTS_ADMIN_API )->assertForbidden();
    $this->actingAs( ecommerceShopper(), 'sanctum' )->postJson( PRODUCTS_ADMIN_API, [ 'type' => 'simple', 'name' => 'X' ], idem() )->assertForbidden();

    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->postJson( PRODUCTS_ADMIN_API, [ 'type' => 'simple', 'name' => 'X' ], [ 'Accept' => 'application/json' ] )
        ->assertStatus( 400 );
} );

it( 'creates a product with its related sets, lists drafts, shows, updates, and deletes', function (): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' );
    $category = ProductCategory::factory()->create();

    $id = $this->postJson( PRODUCTS_ADMIN_API, [
        'type'         => 'simple',
        'name'         => 'Field Notebook',
        'status'       => 'draft',
        'prices'       => [ [ 'currency' => 'USD', 'price_amount' => 1200 ] ],
        'category_ids' => [ $category->id ],
        'inventory'    => [ 'quantity_on_hand' => 7 ],
    ], idem() )
        ->assertCreated()
        ->assertJsonPath( 'data.type', 'product' )
        ->assertJsonPath( 'data.slug', 'field-notebook' )
        ->json( 'data.id' );

    $this->getJson( PRODUCTS_ADMIN_API . '?filter[status]=draft' )->assertOk()->assertJsonCount( 1, 'data' );
    $this->getJson( PRODUCTS_ADMIN_API . '?filter[search]=notebook' )->assertOk()->assertJsonCount( 1, 'data' );
    $this->getJson( PRODUCTS_ADMIN_API . "/{$id}?include=prices,categories" )
        ->assertOk()
        ->assertJsonPath( 'data.prices.0.currency', 'USD' )
        ->assertJsonPath( 'data.categories.0.id', $category->id );

    $this->patchJson( PRODUCTS_ADMIN_API . "/{$id}", [ 'name' => 'Pocket Notebook', 'stock_adjustment' => [ 'delta' => 3, 'reason' => 'Recount' ] ], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.name', 'Pocket Notebook' );

    expect( InventoryItem::query()->where( 'stockable_id', $id )->value( 'quantity_on_hand' ) )->toBe( 10 );

    $this->deleteJson( PRODUCTS_ADMIN_API . "/{$id}", [], idem() )->assertOk();

    expect( Product::query()->count() )->toBe( 0 );
} );

it( 'returns a 422 problem when the service refuses the write', function (): void {
    Product::factory()->create( [ 'slug' => 'taken' ] );

    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->postJson( PRODUCTS_ADMIN_API, [ 'type' => 'simple', 'name' => 'X', 'slug' => 'taken' ], idem() )
        ->assertStatus( 422 )
        ->assertJsonPath( 'errors.0.field', 'slug' )
        ->assertJsonPath( 'errors.0.code', 'slug-taken' );

    $this->postJson( PRODUCTS_ADMIN_API, [ 'name' => 'No type' ], idem() )->assertStatus( 422 )->assertJsonPath( 'errors.0.field', 'type' );
} );

it( 'refuses to update a product whose type is missing', function (): void {
    $product = Product::factory()->create( [ 'type' => 'subscription' ] );

    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->patchJson( PRODUCTS_ADMIN_API . "/{$product->id}", [ 'name' => 'Y' ], idem() )
        ->assertStatus( 422 )
        ->assertJsonPath( 'errors.0.code', 'type-missing' );
} );

it( 'manages variants, generation, and order', function (): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' );
    $product = Product::factory()->variable()->create();
    $base    = PRODUCTS_ADMIN_API . "/{$product->id}";

    $this->postJson( "{$base}/attributes", [ 'label' => 'Size', 'values' => [ [ 'label' => 'S' ], [ 'label' => 'M' ] ] ], idem() )
        ->assertCreated()
        ->assertJsonPath( 'data.key', 'size' );

    $this->postJson( "{$base}/variants/generate", [ 'prices' => [ [ 'currency' => 'USD', 'price_amount' => 900 ] ] ], idem() )
        ->assertCreated()
        ->assertJsonCount( 2, 'data' );

    $variant = $this->postJson( "{$base}/variants", [ 'sku' => 'EXTRA', 'prices' => [ [ 'currency' => 'USD', 'price_amount' => 1000 ] ] ], idem() )
        ->assertCreated()
        ->assertJsonPath( 'data.sku', 'EXTRA' )
        ->json( 'data.id' );

    $this->patchJson( "{$base}/variants/{$variant}", [ 'name' => 'Extra' ], idem() )->assertOk()->assertJsonPath( 'data.name', 'Extra' );

    $ids = $product->variants()->orderByDesc( 'id' )->pluck( 'id' )->all();
    $this->postJson( "{$base}/variants/reorder", [ 'ids' => $ids ], idem() )->assertOk()->assertJsonPath( 'data.0.id', $ids[0] );

    $this->deleteJson( "{$base}/variants/{$variant}", [], idem() )->assertOk();

    expect( $product->variants()->count() )->toBe( 2 );
} );

it( 'sets, updates, and deletes prices for the product and its variants', function (): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' );
    $product = Product::factory()->variable()->create();
    $variant = ProductVariant::factory()->create( [ 'product_id' => $product->id ] );
    $base    = PRODUCTS_ADMIN_API . "/{$product->id}/prices";

    $price = $this->postJson( $base, [ 'currency' => 'USD', 'price_amount' => 500 ], idem() )->assertCreated()->json( 'data.id' );
    $this->postJson( $base, [ 'product_variant_id' => $variant->id, 'currency' => 'EUR', 'price_amount' => 450 ], idem() )->assertCreated();

    $this->patchJson( "{$base}/{$price}", [ 'price_amount' => 550 ], idem() )->assertOk()->assertJsonPath( 'data.price.amount', 550 );
    $this->deleteJson( "{$base}/{$price}", [], idem() )->assertOk();

    expect( ProductPrice::query()->count() )->toBe( 1 );
} );

it( 'manages gallery images', function (): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' );
    $product = Product::factory()->create();
    $base    = PRODUCTS_ADMIN_API . "/{$product->id}/images";

    $first  = $this->postJson( $base, [ 'image_url' => 'https://e.test/1.jpg', 'alt_text' => 'One' ], idem() )->assertCreated()->json( 'data.id' );
    $second = $this->postJson( $base, [ 'media_id' => 12 ], idem() )->assertCreated()->json( 'data.id' );

    $this->patchJson( "{$base}/{$first}", [ 'alt_text' => 'Front' ], idem() )->assertOk()->assertJsonPath( 'data.alt_text', 'Front' );
    $this->postJson( "{$base}/reorder", [ 'ids' => [ $second, $first ] ], idem() )->assertOk()->assertJsonPath( 'data.0.id', $second );
    $this->postJson( $base, [ 'image_url' => 'ftp://e.test/x.jpg' ], idem() )->assertStatus( 422 );
    $this->deleteJson( "{$base}/{$first}", [], idem() )->assertOk();

    expect( ProductImage::query()->count() )->toBe( 1 );
} );

it( 'updates and deletes attributes', function (): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' );
    $product   = Product::factory()->variable()->create();
    $attribute = ProductAttribute::factory()->create( [ 'product_id' => $product->id, 'key' => 'size', 'label' => 'Size' ] );
    $base      = PRODUCTS_ADMIN_API . "/{$product->id}/attributes/{$attribute->id}";

    $this->patchJson( $base, [ 'label' => 'Shirt size', 'values' => [ [ 'label' => 'L' ] ] ], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.label', 'Shirt size' );

    $this->deleteJson( $base, [], idem() )->assertOk();

    expect( ProductAttribute::query()->count() )->toBe( 0 );
} );

it( 'links categories and tags, replaces children, and adjusts stock', function (): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' );
    $bundle   = Product::factory()->bundled()->create();
    $member   = Product::factory()->create();
    $category = ProductCategory::factory()->create();
    $tag      = ProductTag::factory()->create();
    $base     = PRODUCTS_ADMIN_API . "/{$bundle->id}";

    $this->postJson( "{$base}/categories", [ 'ids' => [ $category->id ] ], idem() )->assertOk()->assertJsonCount( 1, 'data' );
    $this->postJson( "{$base}/tags", [ 'ids' => [ $tag->id ], 'mode' => 'attach' ], idem() )->assertOk()->assertJsonCount( 1, 'data' );
    $this->postJson( "{$base}/tags", [ 'ids' => [ $tag->id ], 'mode' => 'detach' ], idem() )->assertOk()->assertJsonCount( 0, 'data' );

    $this->postJson( "{$base}/children", [ 'children' => [ [ 'product_id' => $member->id, 'quantity' => 2 ] ] ], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.0.quantity', 2 );

    $this->postJson( "{$base}/children", [ 'children' => [ [ 'product_id' => $bundle->id ] ] ], idem() )
        ->assertStatus( 422 )
        ->assertJsonPath( 'errors.0.code', 'child-self' );

    $this->postJson( "{$base}/stock", [ 'delta' => 4, 'reason' => 'Received' ], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.quantity_on_hand', 4 );
} );

it( '404s for variants, prices, images, and attributes of another product', function (): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' );
    $mine      = Product::factory()->variable()->create();
    $other     = Product::factory()->variable()->create();
    $variant   = ProductVariant::factory()->create( [ 'product_id' => $other->id ] );
    $price     = ProductPrice::factory()->forPriceable( $other )->create();
    $image     = ProductImage::factory()->create( [ 'product_id' => $other->id ] );
    $attribute = ProductAttribute::factory()->create( [ 'product_id' => $other->id ] );
    $base      = PRODUCTS_ADMIN_API . "/{$mine->id}";

    $this->patchJson( "{$base}/variants/{$variant->id}", [ 'name' => 'x' ], idem() )->assertNotFound();
    $this->patchJson( "{$base}/prices/{$price->id}", [ 'price_amount' => 1 ], idem() )->assertNotFound();
    $this->patchJson( "{$base}/images/{$image->id}", [ 'alt_text' => 'x' ], idem() )->assertNotFound();
    $this->patchJson( "{$base}/attributes/{$attribute->id}", [ 'label' => 'x' ], idem() )->assertNotFound();
    $this->postJson( "{$base}/prices", [ 'product_variant_id' => $variant->id, 'currency' => 'USD', 'price_amount' => 1 ], idem() )->assertNotFound();
    $this->postJson( "{$base}/stock", [ 'product_variant_id' => $variant->id, 'delta' => 1, 'reason' => 'x' ], idem() )->assertNotFound();
} );

it( 'manages categories', function (): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' );
    $api = '/api/ecommerce/v1/admin/product-categories';

    $parent = $this->postJson( $api, [ 'name' => 'Prints' ], idem() )->assertCreated()->assertJsonPath( 'data.slug', 'prints' )->json( 'data.id' );
    $a      = $this->postJson( $api, [ 'name' => 'A3', 'parent_id' => $parent ], idem() )->assertCreated()->json( 'data.id' );
    $b      = $this->postJson( $api, [ 'name' => 'A4', 'parent_id' => $parent ], idem() )->assertCreated()->json( 'data.id' );

    $this->getJson( "{$api}?filter[parent_id]={$parent}" )->assertOk()->assertJsonCount( 2, 'data' );
    $this->postJson( "{$api}/reorder", [ 'parent_id' => $parent, 'ids' => [ $b, $a ] ], idem() )->assertOk()->assertJsonPath( 'data.0.id', $b );
    $this->patchJson( "{$api}/{$parent}", [ 'parent_id' => $a ], idem() )->assertStatus( 422 )->assertJsonPath( 'errors.0.code', 'category-cycle' );
    $this->patchJson( "{$api}/{$a}", [ 'name' => 'A3 posters' ], idem() )->assertOk()->assertJsonPath( 'data.name', 'A3 posters' );
    $this->deleteJson( "{$api}/{$parent}", [], idem() )->assertOk();

    expect( ProductCategory::query()->whereNull( 'parent_id' )->count() )->toBe( 2 );
} );

it( 'manages and merges tags', function (): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' );
    $api     = '/api/ecommerce/v1/admin/product-tags';
    $product = Product::factory()->create();

    $sale  = $this->postJson( $api, [ 'name' => 'Sale' ], idem() )->assertCreated()->json( 'data.id' );
    $deals = $this->postJson( $api, [ 'name' => 'Deals' ], idem() )->assertCreated()->json( 'data.id' );
    $product->tags()->attach( $deals );

    $this->getJson( "{$api}?filter[search]=sal" )->assertOk()->assertJsonCount( 1, 'data' );
    $this->patchJson( "{$api}/{$sale}", [ 'name' => 'On sale' ], idem() )->assertOk()->assertJsonPath( 'data.name', 'On sale' );
    $this->postJson( "{$api}/{$deals}/merge", [ 'target_id' => $sale ], idem() )->assertOk()->assertJsonPath( 'data.id', $sale );
    $this->postJson( "{$api}/{$sale}/merge", [ 'target_id' => $sale ], idem() )->assertStatus( 422 )->assertJsonPath( 'errors.0.code', 'merge-self' );

    expect( $product->tags()->pluck( 'product_tags.id' )->all() )->toBe( [ $sale ] );

    $this->deleteJson( "{$api}/{$sale}", [], idem() )->assertOk();

    expect( ProductTag::query()->count() )->toBe( 0 );
} );

it( 'requires product.delete as well as product.update to merge tags', function (): void {
    $source = ProductTag::factory()->create();
    $target = ProductTag::factory()->create();
    $editor = new Illuminate\Auth\GenericUser( [ 'id' => 7, 'name' => 'Editor' ] );

    Illuminate\Support\Facades\Gate::define( 'ecommerce.product.update', static fn (): bool => true );

    $this->actingAs( $editor, 'sanctum' )
        ->postJson( "/api/ecommerce/v1/admin/product-tags/{$source->id}/merge", [ 'target_id' => $target->id ], idem() )
        ->assertForbidden();

    expect( ProductTag::query()->whereKey( $source->id )->exists() )->toBeTrue();

    Illuminate\Support\Facades\Gate::define( 'ecommerce.product.delete', static fn (): bool => true );

    $this->actingAs( $editor, 'sanctum' )
        ->postJson( "/api/ecommerce/v1/admin/product-tags/{$source->id}/merge", [ 'target_id' => $target->id ], idem() )
        ->assertOk();
} );
