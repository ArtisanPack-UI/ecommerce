<?php

/**
 * EcommerceSchema.
 *
 * Builds the `/graphql/ecommerce` schema (engine spec §10) served through
 * rebing/graphql-laravel:
 *
 * - one object type per REST resource, generated from
 *   {@see ResourceSchemas} (§10.1), each with a Relay `{Type}Connection`
 *   and `{Type}Edge`;
 * - the `Money`, `PageInfo`, and `UserError` support types and the
 *   `DateTime`, `JSON`, and `BigInt` scalars;
 * - the root `Query`, `Mutation`, and `Subscription` fields from
 *   {@see Fields\Queries}, {@see Fields\Mutations}, and
 *   {@see Fields\Subscriptions}.
 *
 * Before the types are built, the whole definition array runs through
 * `ap.ecommerce.graphql.extend` (engine spec §6.17 / §10.5):
 *
 * ```php
 * addFilter( 'ap.ecommerce.graphql.extend', function ( array $schema ): array {
 *     $schema['types']['Product']['fields']['brand'] = [
 *         'type'    => 'String',
 *         'resolve' => fn ( array $product ) => Brand::forProduct( $product['id'] )?->name,
 *     ];
 *     $schema['query']['brands'] = [ 'type' => '[String!]!', 'resolve' => fn () => Brand::pluck( 'name' )->all() ];
 *
 *     return $schema;
 * } );
 * ```
 *
 * Field definitions are either an SDL type string (`'String!'`) or an array
 * with `type`, and optionally `args`, `resolve`, `description`, `complexity`
 * (a graphql-php `fn ( int $childrenComplexity, array $args ): int`). Types are
 * `[ 'kind' => 'object'|'input', 'description' => …, 'fields' => … ]`.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\GraphQL;

use ArtisanPackUI\Ecommerce\Api\ResourceSchemas;
use ArtisanPackUI\Ecommerce\GraphQL\Fields\Mutations;
use ArtisanPackUI\Ecommerce\GraphQL\Fields\Queries;
use ArtisanPackUI\Ecommerce\GraphQL\Fields\Subscriptions;
use ArtisanPackUI\Ecommerce\GraphQL\Support\Resolvers;
use Closure;
use DateTimeInterface;
use GraphQL\Error\Error;
use GraphQL\Language\AST\IntValueNode;
use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\StringValueNode;
use GraphQL\Type\Definition\CustomScalarType;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use GraphQL\Type\SchemaConfig;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class EcommerceSchema
{
    /**
     * rebing/graphql-laravel schema name (served at `/graphql/ecommerce`).
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'ecommerce';

    /**
     * @since 1.0.0
     *
     * @param  Resolvers  $resolvers  Shared resolver plumbing.
     */
    public function __construct( protected Resolvers $resolvers )
    {
    }

    /**
     * Builds the schema.
     *
     * @since 1.0.0
     *
     * @return Schema
     */
    public function build(): Schema
    {
        $registry   = new TypeRegistry();
        $definition = (array) applyFilters( 'ap.ecommerce.graphql.extend', $this->definition(), $registry );

        $this->defineScalars( $registry );

        foreach ( (array) ( $definition['types'] ?? [] ) as $name => $type ) {
            $this->defineType( $registry, (string) $name, (array) $type );
        }

        // Root types go through the registry too: the type loader must hand
        // back the very instance the schema holds for every name.
        $roots = [];

        foreach ( [ 'Query' => 'query', 'Mutation' => 'mutation', 'Subscription' => 'subscription' ] as $root => $key ) {
            $fields = (array) ( $definition[ $key ] ?? [] );

            if ( 'Query' === $root || [] !== $fields ) {
                $registry->define( $root, fn (): ObjectType => $this->rootType( $registry, $root, $fields ) );
                $roots[ $root ] = true;
            }
        }

        $config = SchemaConfig::create()
            ->setQuery( $registry->named( 'Query' ) )
            ->setTypeLoader( static fn ( string $name ): ?Type => $registry->has( $name ) ? $registry->named( $name ) : null )
            ->setTypes( static fn (): array => $registry->all() );

        if ( isset( $roots['Mutation'] ) ) {
            $config->setMutation( $registry->named( 'Mutation' ) );
        }

        if ( isset( $roots['Subscription'] ) ) {
            $config->setSubscription( $registry->named( 'Subscription' ) );
        }

        return new Schema( $config );
    }

    /**
     * The definition array passed through `ap.ecommerce.graphql.extend`.
     *
     * @since 1.0.0
     *
     * @return array{types: array<string, array<string, mixed>>, query: array<string, mixed>, mutation: array<string, mixed>, subscription: array<string, mixed>}
     */
    public function definition(): array
    {
        $types = [
            'Money' => [
                'description' => 'An amount in minor units (e.g. cents) and its ISO 4217 currency.',
                'fields'      => [ 'amount' => 'BigInt!', 'currency' => 'String' ],
            ],
            'PageInfo' => [
                'description' => 'Relay pagination state.',
                'fields'      => [
                    'hasNextPage'     => 'Boolean!',
                    'hasPreviousPage' => 'Boolean!',
                    'startCursor'     => 'String',
                    'endCursor'       => 'String',
                ],
            ],
            'UserError' => [
                'description' => 'An expected, recoverable mutation failure.',
                'fields'      => [ 'field' => 'String', 'code' => 'String!', 'message' => 'String!' ],
            ],
        ];

        foreach ( ResourceSchemas::all() as $name => $schema ) {
            $fields = $schema['fields'];

            foreach ( $schema['relations'] as $field => [ $related, $isList ] ) {
                $fields[ $field ] = $isList
                    ? [ 'type' => '[' . $related . '!]', 'complexity' => self::listComplexity() ]
                    : $related;
            }

            $types[ $name ] = [ 'description' => $schema['description'], 'fields' => $fields ];

            $types[ $name . 'Edge' ] = [
                'description' => sprintf( 'A %s in a connection.', $name ),
                'fields'      => [ 'cursor' => 'String!', 'node' => $name . '!' ],
            ];

            $types[ $name . 'Connection' ] = [
                'description' => sprintf( 'A page of %s results.', $name ),
                'fields'      => [
                    'edges'    => '[' . $name . 'Edge!]!',
                    'nodes'    => '[' . $name . '!]!',
                    'pageInfo' => 'PageInfo!',
                ],
            ];
        }

        $queries       = new Queries( $this->resolvers );
        $mutations     = new Mutations( $this->resolvers );
        $subscriptions = new Subscriptions();

        return [
            'types'        => $types + $queries->types() + $mutations->types() + $subscriptions->types(),
            'query'        => $queries->fields(),
            'mutation'     => $mutations->fields(),
            'subscription' => $subscriptions->fields(),
        ];
    }

    /**
     * Cost of a relation list: its selection counted once per assumed item
     * (`graphql.list_complexity_factor`), so nested lists multiply instead
     * of adding (`QueryComplexity` otherwise only counts fields).
     *
     * @since 1.0.0
     *
     * @return Closure(int, array<string, mixed>): int
     */
    public static function listComplexity(): Closure
    {
        return static fn ( int $children, array $args ): int => 1 + $children * max( 1, (int) config( 'artisanpack.ecommerce.graphql.list_complexity_factor', 5 ) );
    }

    /**
     * Cost of a connection: its selection counted once per requested node
     * (`first`, capped at `api.max_per_page`).
     *
     * @since 1.0.0
     *
     * @return Closure(int, array<string, mixed>): int
     */
    public static function connectionComplexity(): Closure
    {
        return static function ( int $children, array $args ): int {
            $max   = max( 1, (int) config( 'artisanpack.ecommerce.api.max_per_page', 100 ) );
            $first = (int) ( $args['first'] ?? config( 'artisanpack.ecommerce.api.default_per_page', 25 ) );

            return 1 + $children * max( 1, min( $max, $first ) );
        };
    }

    /**
     * Registers the custom scalars.
     *
     * @since 1.0.0
     *
     * @param  TypeRegistry  $registry  Registry.
     *
     * @return void
     */
    protected function defineScalars( TypeRegistry $registry ): void
    {
        $registry->define( 'DateTime', static fn (): CustomScalarType => new CustomScalarType( [
            'name'         => 'DateTime',
            'description'  => 'An ISO 8601 date-time string.',
            'serialize'    => static fn ( mixed $value ): ?string => match ( true ) {
                null === $value                     => null,
                $value instanceof DateTimeInterface => Carbon::instance( $value )->toIso8601String(),
                default                             => Carbon::parse( (string) $value )->toIso8601String(),
            },
            'parseValue'   => static function ( mixed $value ): Carbon {
                try {
                    return Carbon::parse( (string) $value );
                } catch ( Throwable ) {
                    throw new Error( 'DateTime must be an ISO 8601 string.' );
                }
            },
            'parseLiteral' => static function ( Node $node ): Carbon {
                if ( ! $node instanceof StringValueNode ) {
                    throw new Error( 'DateTime must be an ISO 8601 string.' );
                }

                return Carbon::parse( $node->value );
            },
        ] ) );

        $registry->define( 'JSON', static fn (): CustomScalarType => new CustomScalarType( [
            'name'         => 'JSON',
            'description'  => 'An arbitrary JSON value.',
            'serialize'    => static fn ( mixed $value ): mixed => $value,
            'parseValue'   => static fn ( mixed $value ): mixed => $value,
            'parseLiteral' => static fn ( Node $node ): mixed => \GraphQL\Utils\AST::valueFromASTUntyped( $node ),
        ] ) );

        $registry->define( 'BigInt', static fn (): CustomScalarType => new CustomScalarType( [
            'name'         => 'BigInt',
            'description'  => 'A 64-bit integer (money in minor units, E8 rates) — wider than GraphQL Int.',
            'serialize'    => static fn ( mixed $value ): ?int => null === $value ? null : (int) $value,
            'parseValue'   => static function ( mixed $value ): int {
                if ( ! is_int( $value ) && ! ( is_string( $value ) && preg_match( '/^-?\d+$/', $value ) ) ) {
                    throw new Error( 'BigInt must be an integer.' );
                }

                return (int) $value;
            },
            'parseLiteral' => static function ( Node $node ): int {
                if ( ! $node instanceof IntValueNode && ! ( $node instanceof StringValueNode && preg_match( '/^-?\d+$/', $node->value ) ) ) {
                    throw new Error( 'BigInt must be an integer.' );
                }

                return (int) $node->value;
            },
        ] ) );
    }

    /**
     * Registers one object or input type from its definition.
     *
     * @since 1.0.0
     *
     * @param  TypeRegistry          $registry    Registry.
     * @param  string                $name        Type name.
     * @param  array<string, mixed>  $definition  Definition.
     *
     * @return void
     */
    protected function defineType( TypeRegistry $registry, string $name, array $definition ): void
    {
        $fields = (array) ( $definition['fields'] ?? [] );

        if ( 'input' === ( $definition['kind'] ?? 'object' ) ) {
            $registry->define( $name, fn (): InputObjectType => new InputObjectType( [
                'name'        => $name,
                'description' => $definition['description'] ?? null,
                'fields'      => fn (): array => $this->inputFields( $registry, $fields ),
            ] ) );

            return;
        }

        $registry->define( $name, fn (): ObjectType => new ObjectType( [
            'name'        => $name,
            'description' => $definition['description'] ?? null,
            'fields'      => fn (): array => $this->outputFields( $registry, $fields ),
        ] ) );
    }

    /**
     * Builds a root operation type.
     *
     * @since 1.0.0
     *
     * @param  TypeRegistry          $registry  Registry.
     * @param  string                $name      `Query`, `Mutation`, or `Subscription`.
     * @param  array<string, mixed>  $fields    Field definitions.
     *
     * @return ObjectType
     */
    protected function rootType( TypeRegistry $registry, string $name, array $fields ): ObjectType
    {
        return new ObjectType( [
            'name'   => $name,
            'fields' => fn (): array => $this->outputFields( $registry, $fields ),
        ] );
    }

    /**
     * Normalizes output field definitions to graphql-php configs.
     *
     * @since 1.0.0
     *
     * @param  TypeRegistry          $registry  Registry.
     * @param  array<string, mixed>  $fields    Field definitions.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function outputFields( TypeRegistry $registry, array $fields ): array
    {
        $configs = [];

        foreach ( $fields as $name => $field ) {
            $field  = is_array( $field ) ? $field : [ 'type' => $field ];
            $config = [
                'type'        => $this->typeOf( $registry, $field['type'] ),
                'description' => $field['description'] ?? null,
                'args'        => $this->inputFields( $registry, (array) ( $field['args'] ?? [] ) ),
            ];

            if ( isset( $field['resolve'] ) ) {
                $config['resolve'] = $field['resolve'];
            }

            if ( isset( $field['complexity'] ) ) {
                $config['complexity'] = $field['complexity'];
            } elseif ( is_string( $field['type'] ) && str_ends_with( rtrim( $field['type'], '!' ), 'Connection' ) ) {
                $config['complexity'] = self::connectionComplexity();
            }

            $configs[ (string) $name ] = $config;
        }

        return $configs;
    }

    /**
     * Normalizes argument / input field definitions.
     *
     * @since 1.0.0
     *
     * @param  TypeRegistry          $registry  Registry.
     * @param  array<string, mixed>  $fields    Field definitions.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function inputFields( TypeRegistry $registry, array $fields ): array
    {
        $configs = [];

        foreach ( $fields as $name => $field ) {
            $field  = is_array( $field ) ? $field : [ 'type' => $field ];
            $config = [
                'type'        => $this->typeOf( $registry, $field['type'] ),
                'description' => $field['description'] ?? null,
            ];

            if ( array_key_exists( 'defaultValue', $field ) ) {
                $config['defaultValue'] = $field['defaultValue'];
            }

            $configs[ (string) $name ] = $config;
        }

        return $configs;
    }

    /**
     * Resolves a field's type (SDL string or a graphql-php type).
     *
     * @since 1.0.0
     *
     * @param  TypeRegistry  $registry  Registry.
     * @param  mixed         $type      Type reference.
     *
     * @return Type
     */
    protected function typeOf( TypeRegistry $registry, mixed $type ): Type
    {
        return $type instanceof Type ? $type : $registry->get( (string) $type );
    }
}
