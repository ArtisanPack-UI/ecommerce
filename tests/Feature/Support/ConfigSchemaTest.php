<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Registries\KanbanAutomationRegistry;
use ArtisanPackUI\Ecommerce\Registries\KanbanCardWidgetRegistry;
use ArtisanPackUI\Ecommerce\Registries\PromotionActionRegistry;
use ArtisanPackUI\Ecommerce\Registries\PromotionConditionRegistry;
use ArtisanPackUI\Ecommerce\Registries\ShippingMethodTypeRegistry;
use ArtisanPackUI\Ecommerce\Shipping\Methods\WeightBasedMethod;
use ArtisanPackUI\Ecommerce\Support\ConfigField;
use ArtisanPackUI\Ecommerce\Support\ConfigSchema;
use Illuminate\Validation\ValidationException;

it( 'declares a well-formed schema on every core registry entry', function ( string $registry ): void {
    $registry = app( $registry );

    foreach ( $registry->keys() as $key ) {
        $schema = ConfigSchema::of( $registry->get( $key ) );

        expect( $schema )->not->toBeNull( "{$key} declares no schema" )
            ->and( ConfigSchema::problems( $registry->get( $key )->configSchema() ) )->toBe( [], "{$key} schema is malformed" );
    }
} )->with( [
    PromotionConditionRegistry::class,
    PromotionActionRegistry::class,
    ShippingMethodTypeRegistry::class,
    KanbanAutomationRegistry::class,
    KanbanCardWidgetRegistry::class,
] );

it( 'reports what is wrong with a malformed schema', function ( mixed $schema, string $problem ): void {
    expect( implode( "\n", ConfigSchema::problems( $schema ) ) )->toContain( $problem );
} )->with( [
    'not a list'         => [ [ 'amount' => [] ], 'must be a list' ],
    'field not an array' => [ [ 'amount' ], 'must be an array' ],
    'bad name'           => [ [ ConfigField::make( 'Amount', 'money', 'Amount' ) ], 'snake_case "name"' ],
    'duplicate name'     => [ [ ConfigField::make( 'amount', 'money', 'A' ), ConfigField::make( 'amount', 'money', 'B' ) ], 'repeats the name "amount"' ],
    'unknown type'       => [ [ ConfigField::make( 'amount', 'currency', 'Amount' ) ], 'unknown type' ],
    'missing label'      => [ [ ConfigField::make( 'amount', 'money', '' ) ], 'needs a "label"' ],
    'unknown key'        => [ [ ConfigField::make( 'amount', 'money', 'Amount', [ 'placeholder' => 'x' ] ) ], 'unknown key "placeholder"' ],
    'non-bool required'  => [ [ ConfigField::make( 'amount', 'money', 'Amount', [ 'required' => 'yes' ] ) ], '"required" must be a boolean' ],
    'multiple on money'  => [ [ ConfigField::make( 'amount', 'money', 'Amount', [ 'multiple' => true ] ) ], '"multiple" only applies' ],
    'closure rule'       => [ [ ConfigField::make( 'amount', 'money', 'Amount', [ 'rules' => [ fn () => true ] ] ) ], 'list of rule strings' ],
    'select no options'  => [ [ ConfigField::make( 'unit', 'select', 'Unit' ) ], 'non-empty list of "options"' ],
    'bad option'         => [ [ ConfigField::make( 'unit', 'select', 'Unit', [ 'options' => [ [ 'value' => 'kg' ] ] ] ) ], 'scalar "value" and a string "label"' ],
    'empty repeater'     => [ [ ConfigField::make( 'tiers', 'repeater', 'Tiers' ) ], 'needs "fields"' ],
    'bad nested field'   => [ [ ConfigField::make( 'tiers', 'repeater', 'Tiers', [ 'fields' => [ ConfigField::make( 'amount', 'nope', 'Amount' ) ] ] ) ], '[0](tiers).fields[0](amount) has an unknown type' ],
] );

it( 'accepts a well-formed schema', function (): void {
    expect( ConfigSchema::problems( [
        ConfigField::make( 'amount', 'money', 'Amount', [ 'required' => true, 'rules' => [ 'max:100000' ], 'help' => 'Minor units.' ] ),
        ConfigField::make( 'product_ids', 'product', 'Products', [ 'multiple' => true ] ),
        ConfigField::make( 'unit', 'select', 'Unit', [ 'options' => ConfigField::options( [ 'kg' => 'Kilograms' ] ), 'default' => 'kg' ] ),
        ConfigField::make( 'subject', 'template', 'Subject', [ 'tokens' => [ '{order_number}' ] ] ),
    ] ) )->toBe( [] );
} );

it( 'validates a config in-process against the entry\'s schema', function (): void {
    $method = app( WeightBasedMethod::class );

    ConfigSchema::validate( $method, [ 'unit' => 'kg', 'tiers' => [ [ 'max_weight' => 2, 'amount' => 500 ] ] ] );

    try {
        ConfigSchema::validate( $method, [ 'tiers' => [ [ 'max_weight' => -1, 'amount' => -5 ] ] ], 'methods.0.config.' );
        $this->fail( 'Expected a validation failure.' );
    } catch ( ValidationException $exception ) {
        expect( array_keys( $exception->errors() ) )->toBe( [ 'methods.0.config.tiers.0.max_weight', 'methods.0.config.tiers.0.amount' ] );
    }
} );

it( 'skips validation for an entry without a schema', function (): void {
    ConfigSchema::validate( new stdClass(), [ 'anything' => 'goes' ] );

    expect( ConfigSchema::of( new stdClass() ) )->toBeNull();
} );
