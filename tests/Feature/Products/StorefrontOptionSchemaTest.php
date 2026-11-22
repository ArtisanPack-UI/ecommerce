<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Contracts\ProvidesStorefrontOptions;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductChild;
use ArtisanPackUI\Ecommerce\Models\ProductPrice;
use ArtisanPackUI\Ecommerce\ProductTypes\SimpleProductType;
use ArtisanPackUI\Ecommerce\Registries\ProductTypeRegistry;
use ArtisanPackUI\Ecommerce\Support\StorefrontOptionSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

require_once __DIR__ . '/../GraphQL/GraphQLTestHelpers.php';

uses( RefreshDatabase::class );

/**
 * A framed print: a swatch colour field with option metadata, a frame
 * select, and a mat that only shows once a frame is chosen.
 */
class SchemaFramedPrintType extends SimpleProductType implements ProvidesStorefrontOptions
{
    /**
     * Extra (possibly malformed) fields a test appends.
     *
     * @var array<int, mixed>
     */
    public static array $extra = [];

    public function key(): string
    {
        return 'schema-framed-print';
    }

    public function storefrontOptions( Product $product ): array
    {
        return [
            [
                'type'    => 'swatch',
                'name'    => 'color',
                'label'   => 'Color',
                'options' => [
                    [ 'value' => 'black', 'label' => 'Black', 'swatch' => [ 'hex' => '#000000', 'alt' => 'Black' ] ],
                    [ 'value' => 'gold', 'label' => 'Metallic gold', 'swatch' => [ 'image_url' => 'https://cdn.test/gold.png', 'alt' => 'Gold' ], 'price_delta' => [ 'amount' => 500, 'currency' => 'usd' ], 'hint' => '+$5', 'image' => [ 'url' => 'https://cdn.test/gold-card.png', 'srcset' => 'https://cdn.test/gold-card@2x.png 2x', 'alt' => 'Gold frame' ] ],
                    [ 'value' => 'silver', 'label' => 'Silver', 'disabled' => true ],
                ],
                'meta'    => [ 'disables' => [ 'gold' => [ 'frame' => [ 'none' ] ] ] ],
            ],
            [
                'type'     => 'select',
                'name'     => 'frame',
                'label'    => 'Frame',
                'required' => true,
                'rules'    => [ 'in:none,oak' ],
                'options'  => [ [ 'value' => 'none', 'label' => 'No frame' ], [ 'value' => 'oak', 'label' => 'Black oak' ] ],
            ],
            [
                'type'         => 'select',
                'name'         => 'frame_mat',
                'label'        => 'Mat',
                'required'     => true,
                'visible_when' => [ [ 'field' => 'frame', 'in' => [ 'oak' ] ] ],
                'options'      => [ [ 'value' => 'white', 'label' => 'White' ] ],
            ],
            ...self::$extra,
        ];
    }

    public function validateCartOptions( Product $product, array $options ): array
    {
        return app( StorefrontOptionSchema::class )->validate( $this->storefrontOptions( $product ), $options );
    }
}

beforeEach( function (): void {
    SchemaFramedPrintType::$extra = [];
    app( ProductTypeRegistry::class )->register( 'schema-framed-print', SchemaFramedPrintType::class );

    $this->schema = app( StorefrontOptionSchema::class );
} );

it( 'keeps valid fields and option metadata, normalizing currency codes', function (): void {
    $fields = $this->schema->normalize( ( new SchemaFramedPrintType() )->storefrontOptions( Product::factory()->make() ) );

    expect( $fields )->toHaveCount( 3 )
        ->and( $fields[0]['type'] )->toBe( 'swatch' )
        ->and( $fields[0]['options'][1]['price_delta'] )->toBe( [ 'amount' => 500, 'currency' => 'USD' ] )
        ->and( $fields[0]['options'][1]['image'] )->toBe( [ 'url' => 'https://cdn.test/gold-card.png', 'srcset' => 'https://cdn.test/gold-card@2x.png 2x', 'alt' => 'Gold frame' ] )
        ->and( $fields[0]['options'][1]['swatch'] )->toBe( [ 'image_url' => 'https://cdn.test/gold.png', 'alt' => 'Gold' ] )
        ->and( $fields[0]['options'][1]['hint'] )->toBe( '+$5' )
        ->and( $fields[0]['options'][2]['disabled'] )->toBeTrue()
        ->and( $fields[0]['meta']['disables'] )->toBe( [ 'gold' => [ 'frame' => [ 'none' ] ] ] )
        ->and( $fields[2]['visible_when'] )->toBe( [ [ 'field' => 'frame', 'in' => [ 'oak' ] ] ] );
} );

it( 'drops malformed fields and options with a logged warning instead of throwing', function (): void {
    Log::shouldReceive( 'channel' )->with( 'ecommerce' )->andReturnSelf();
    Log::shouldReceive( 'warning' )->atLeast()->once();

    $fields = $this->schema->normalize( [
        'not a field',
        [ 'type' => 'carousel', 'name' => 'x', 'label' => 'X' ],
        [ 'type' => 'text', 'name' => 'bad name!', 'label' => 'X' ],
        [ 'type' => 'text', 'name' => 'label_not_string', 'label' => [ 'x' ] ],
        [ 'type' => 'select', 'name' => 'no_options', 'label' => 'X', 'options' => [ [ 'value' => [ 'x' ], 'label' => 'Bad' ] ] ],
        [ 'type' => 'radio', 'name' => 'missing_options', 'label' => 'X' ],
        [ 'type' => 'text', 'name' => 'bad_condition', 'label' => 'X', 'visible_when' => [ [ 'field' => 'frame' ] ] ],
        [ 'type' => 'text', 'name' => 'ok', 'label' => 'OK', 'meta' => [ 'disables' => 'nope', 'keep' => 1 ] ],
        [ 'type' => 'text', 'name' => 'ok', 'label' => 'Duplicate' ],
        [ 'type' => 'radio', 'name' => 'pick', 'label' => 'Pick', 'options' => [
            [ 'value' => 'a', 'label' => 'A', 'price_delta' => [ 'amount' => '5', 'currency' => 'USD' ], 'swatch' => [ 'alt' => 'No colour' ], 'image' => [ 'alt' => 'No url' ], 'hint' => 5, 'disabled' => 'yes' ],
            [ 'value' => 'b' ],
        ] ],
    ] );

    expect( array_column( $fields, 'name' ) )->toBe( [ 'ok', 'pick' ] )
        ->and( $fields[0]['meta'] )->toBe( [ 'keep' => 1 ] )
        ->and( $fields[1]['options'] )->toBe( [ [ 'value' => 'a', 'label' => 'A' ] ] );
} );

it( 'evaluates visible_when, with every condition needing a match and hiding cascading', function (): void {
    $fields = $this->schema->normalize( [
        [ 'type' => 'select', 'name' => 'frame', 'label' => 'Frame', 'options' => [ [ 'value' => 'oak', 'label' => 'Oak' ], [ 'value' => 'none', 'label' => 'None' ] ] ],
        [ 'type' => 'select', 'name' => 'mat', 'label' => 'Mat', 'visible_when' => [ [ 'field' => 'frame', 'in' => [ 'oak' ] ] ], 'options' => [ [ 'value' => 'white', 'label' => 'White' ] ] ],
        [ 'type' => 'text', 'name' => 'mat_note', 'label' => 'Note', 'visible_when' => [ [ 'field' => 'mat', 'in' => [ 'white' ] ] ] ],
        [ 'type' => 'checkbox', 'name' => 'extras', 'label' => 'Extras', 'options' => [ [ 'value' => 'glass', 'label' => 'Glass' ], [ 'value' => 'hanger', 'label' => 'Hanger' ] ] ],
        [ 'type' => 'text', 'name' => 'glass.coating', 'label' => 'Coating', 'visible_when' => [ [ 'field' => 'extras', 'in' => [ 'glass' ] ], [ 'field' => 'frame', 'in' => [ 'oak' ] ] ] ],
    ] );

    expect( $this->schema->isVisible( $fields[1], [ 'frame' => 'oak' ] ) )->toBeTrue()
        ->and( $this->schema->isVisible( $fields[1], [ 'frame' => 'none' ] ) )->toBeFalse()
        ->and( $this->schema->isVisible( $fields[1], [] ) )->toBeFalse()
        ->and( array_column( $this->schema->visibleFields( $fields, [ 'frame' => 'oak', 'mat' => 'white', 'extras' => [ 'hanger', 'glass' ] ] ), 'name' ) )->toBe( [ 'frame', 'mat', 'mat_note', 'extras', 'glass.coating' ] )
        // `mat` is hidden, so `mat_note` (conditioned on it) is hidden too.
        ->and( array_column( $this->schema->visibleFields( $fields, [ 'frame' => 'none', 'mat' => 'white', 'extras' => [ 'glass' ] ] ), 'name' ) )->toBe( [ 'frame', 'extras' ] );
} );

it( 'strips hidden fields\' values, with dot and bracket names', function (): void {
    $fields = $this->schema->normalize( [
        [ 'type' => 'select', 'name' => 'frame', 'label' => 'Frame', 'options' => [ [ 'value' => 'oak', 'label' => 'Oak' ], [ 'value' => 'none', 'label' => 'None' ] ] ],
        [ 'type' => 'text', 'name' => 'frame_opts.mat', 'label' => 'Mat', 'visible_when' => [ [ 'field' => 'frame', 'in' => [ 'oak' ] ] ] ],
        [ 'type' => 'text', 'name' => 'frame_opts[glass]', 'label' => 'Glass', 'visible_when' => [ [ 'field' => 'frame', 'in' => [ 'oak' ] ] ] ],
    ] );

    $submitted = [ 'frame' => 'none', 'frame_opts' => [ 'mat' => 'white', 'glass' => 'museum', 'other' => 'kept' ], 'note' => 'hi' ];

    expect( $this->schema->stripHidden( $fields, $submitted ) )->toBe( [ 'frame' => 'none', 'frame_opts' => [ 'other' => 'kept' ], 'note' => 'hi' ] )
        ->and( $this->schema->stripHidden( $fields, [ 'frame' => 'oak' ] + $submitted ) )->toBe( [ 'frame' => 'oak' ] + $submitted );
} );

it( 'validates only visible fields and keys errors under options', function (): void {
    $product = Product::factory()->create( [ 'type' => 'schema-framed-print' ] );
    $type    = $product->productType();

    // The mat is hidden without a frame, so it isn't required and its value is dropped.
    expect( $type->validateCartOptions( $product, [ 'frame' => 'none', 'frame_mat' => 'white' ] ) )->toBe( [ 'frame' => 'none' ] );

    expect( fn () => $type->validateCartOptions( $product, [ 'frame' => 'oak' ] ) )
        ->toThrow( fn ( ValidationException $e ) => expect( array_keys( $e->errors() ) )->toBe( [ 'options.frame_mat' ] ) );

    expect( fn () => $type->validateCartOptions( $product, [] ) )
        ->toThrow( fn ( ValidationException $e ) => expect( array_keys( $e->errors() ) )->toBe( [ 'options.frame' ] ) );
} );

it( 'returns the normalized fields with the new keys from purchase-options', function (): void {
    SchemaFramedPrintType::$extra = [ [ 'type' => 'swatch', 'name' => 'broken', 'label' => 'Broken', 'options' => [] ] ];
    $product                      = Product::factory()->create( [ 'type' => 'schema-framed-print' ] );
    ProductPrice::factory()->forPriceable( $product )->create( [ 'currency' => 'USD', 'price_amount' => 12_000 ] );

    $options = $this->getJson( "/api/ecommerce/v1/products/{$product->id}/purchase-options" )->assertOk()->json( 'data.options' );

    expect( array_column( $options, 'name' ) )->toBe( [ 'color', 'frame', 'frame_mat' ] )
        ->and( $options[0]['type'] )->toBe( 'swatch' )
        ->and( $options[0]['options'][1]['price_delta'] )->toBe( [ 'amount' => 500, 'currency' => 'USD' ] )
        ->and( $options[0]['options'][0]['swatch'] )->toBe( [ 'hex' => '#000000', 'alt' => 'Black' ] )
        ->and( $options[0]['meta']['disables'] )->toBe( [ 'gold' => [ 'frame' => [ 'none' ] ] ] )
        ->and( $options[2]['visible_when'] )->toBe( [ [ 'field' => 'frame', 'in' => [ 'oak' ] ] ] );
} );

it( 'returns the same purchase options over GraphQL', function (): void {
    $product = Product::factory()->create( [ 'type' => 'schema-framed-print' ] );
    ProductPrice::factory()->forPriceable( $product )->create( [ 'currency' => 'USD', 'price_amount' => 12_000 ] );

    $data = gql( $this, 'query ($id: ID!) { productPurchaseOptions(product: $id, currency: "usd") }', [ 'id' => $product->id ] )
        ->assertOk()
        ->json( 'data.productPurchaseOptions' );

    expect( $data['currency'] )->toBe( 'USD' )
        ->and( array_column( $data['options'], 'name' ) )->toBe( [ 'color', 'frame', 'frame_mat' ] )
        ->and( $data['options'][0]['options'][1]['hint'] )->toBe( '+$5' );

    gql( $this, '{ productPurchaseOptions(product: 999999) }' )->assertJsonPath( 'data.productPurchaseOptions', null );
} );

it( 'keeps grouped and bundled fields unchanged (backwards compatible)', function (): void {
    $group  = Product::factory()->create( [ 'type' => 'grouped' ] );
    $bundle = Product::factory()->create( [ 'type' => 'bundled' ] );
    $child  = Product::factory()->create( [ 'name' => 'Poster' ] );
    ProductChild::factory()->create( [ 'parent_product_id' => $group->id, 'child_product_id' => $child->id ] );
    ProductChild::factory()->create( [ 'parent_product_id' => $bundle->id, 'child_product_id' => $child->id, 'quantity' => 2 ] );

    foreach ( [ $group, $bundle ] as $product ) {
        $raw = $product->productType()->storefrontOptions( $product );

        expect( $this->schema->normalize( $raw ) )->toBe( $raw );
    }

    $grouped = $this->getJson( "/api/ecommerce/v1/products/{$group->id}/purchase-options" )->assertOk()->json( 'data.options' );

    expect( $grouped[0]['type'] )->toBe( 'quantity' )
        ->and( $grouped[0]['name'] )->toStartWith( 'items.' );
} );

it( 'keeps the first of two fields that name the same option in dot and bracket notation', function (): void {
    $fields = app( StorefrontOptionSchema::class )->normalize( [
        [ 'type' => 'text', 'name' => 'frame.mat', 'label' => 'Mat', 'rules' => [ 'max:5' ] ],
        [ 'type' => 'text', 'name' => 'frame[mat]', 'label' => 'Mat again', 'rules' => [ 'max:500' ] ],
    ] );

    expect( $fields )->toHaveCount( 1 )
        ->and( $fields[0]['label'] )->toBe( 'Mat' )
        ->and( app( StorefrontOptionSchema::class )->rules( $fields, [] ) )->toBe( [ 'frame.mat' => [ 'max:5' ] ] );
} );

it( 'drops a field whose rules the validator can\'t take, keeping valid rule forms', function (): void {
    $fields = app( StorefrontOptionSchema::class )->normalize( [
        [ 'type' => 'text', 'name' => 'a', 'rules' => 'required|max:5' ],
        [ 'type' => 'text', 'name' => 'b', 'rules' => [ 'max:5', Illuminate\Validation\Rule::in( [ 'x' ] ), static fn (): bool => true ] ],
        [ 'type' => 'text', 'name' => 'c', 'rules' => 42 ],
        [ 'type' => 'text', 'name' => 'd', 'rules' => [ 'max:5', [ 'nested' ] ] ],
    ] );

    expect( array_column( $fields, 'name' ) )->toBe( [ 'a', 'b' ] );
} );

it( 'never matches an empty value in visible_when, ignoring an empty string in `in`', function (): void {
    $schema = app( StorefrontOptionSchema::class );
    $fields = $schema->normalize( [
        [ 'type' => 'text', 'name' => 'frame' ],
        [ 'type' => 'text', 'name' => 'mat', 'required' => true, 'visible_when' => [ [ 'field' => 'frame', 'in' => [ '', 'oak' ] ] ] ],
        [ 'type' => 'text', 'name' => 'note', 'visible_when' => [ [ 'field' => 'frame', 'in' => [ '' ] ] ] ],
    ] );

    expect( array_column( $fields, 'name' ) )->toBe( [ 'frame', 'mat' ] )
        ->and( $fields[1]['visible_when'][0]['in'] )->toBe( [ 'oak' ] )
        ->and( array_column( $schema->visibleFields( $fields, [ 'frame' => '' ] ), 'name' ) )->toBe( [ 'frame' ] )
        ->and( $schema->rules( $fields, [ 'frame' => '' ] ) )->not->toHaveKey( 'mat' )
        ->and( array_column( $schema->visibleFields( $fields, [ 'frame' => 'oak' ] ), 'name' ) )->toBe( [ 'frame', 'mat' ] );
} );
