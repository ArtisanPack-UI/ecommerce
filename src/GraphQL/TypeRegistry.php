<?php

/**
 * TypeRegistry.
 *
 * Holds the ecommerce schema's named types and resolves type references
 * written in SDL notation (`Product`, `[Product!]!`, `Int`). Types are
 * built lazily from definitions, so the whole schema costs nothing until a
 * field is actually resolved.
 *
 * The registry is private to the `ecommerce` schema: its type names
 * (`Product`, `Order`, …) can't collide with a host application's own
 * GraphQL types in rebing/graphql-laravel's global registry. A name this
 * registry doesn't know falls back to rebing's registry, so a satellite can
 * register a type there and reference it from an `ap.ecommerce.graphql.extend`
 * field.
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

use Closure;
use GraphQL\Type\Definition\NamedType;
use GraphQL\Type\Definition\Type;
use InvalidArgumentException;
use Rebing\GraphQL\GraphQL as RebingGraphQL;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class TypeRegistry
{
    /**
     * Built types, by name.
     *
     * @since 1.0.0
     *
     * @var array<string, NamedType&Type>
     */
    protected array $types = [];

    /**
     * Type factories, by name.
     *
     * @since 1.0.0
     *
     * @var array<string, Closure(): (NamedType&Type)>
     */
    protected array $factories = [];

    /**
     * Registers a lazily-built named type.
     *
     * @since 1.0.0
     *
     * @param  string                          $name     Type name.
     * @param  Closure(): (NamedType&Type)     $factory  Builds the type.
     *
     * @return void
     */
    public function define( string $name, Closure $factory ): void
    {
        $this->factories[ $name ] = $factory;
        unset( $this->types[ $name ] );
    }

    /**
     * Whether a named type is defined.
     *
     * @since 1.0.0
     *
     * @param  string  $name  Type name.
     *
     * @return bool
     */
    public function has( string $name ): bool
    {
        return isset( $this->factories[ $name ] ) || isset( Type::getStandardTypes()[ $name ] );
    }

    /**
     * Resolves an SDL type reference.
     *
     * @since 1.0.0
     *
     * @param  string  $reference  e.g. `[Product!]!`.
     *
     * @throws InvalidArgumentException When the named type is unknown.
     *
     * @return Type
     */
    public function get( string $reference ): Type
    {
        $reference = trim( $reference );

        if ( str_ends_with( $reference, '!' ) ) {
            return Type::nonNull( $this->get( substr( $reference, 0, -1 ) ) );
        }

        if ( str_starts_with( $reference, '[' ) && str_ends_with( $reference, ']' ) ) {
            return Type::listOf( $this->get( substr( $reference, 1, -1 ) ) );
        }

        return $this->named( $reference );
    }

    /**
     * A named type by name.
     *
     * @since 1.0.0
     *
     * @param  string  $name  Type name.
     *
     * @throws InvalidArgumentException When the type is unknown.
     *
     * @return NamedType&Type
     */
    public function named( string $name ): Type&NamedType
    {
        $standard = Type::getStandardTypes();

        if ( isset( $standard[ $name ] ) ) {
            return $standard[ $name ];
        }

        if ( isset( $this->types[ $name ] ) ) {
            return $this->types[ $name ];
        }

        if ( isset( $this->factories[ $name ] ) ) {
            return $this->types[ $name ] = ( $this->factories[ $name ] )();
        }

        if ( app()->bound( RebingGraphQL::class ) ) {
            try {
                /** @var NamedType&Type $external */
                $external = app( RebingGraphQL::class )->type( $name );

                return $this->types[ $name ] = $external;
            } catch ( Throwable ) {
                // Fall through to the error below.
            }
        }

        throw new InvalidArgumentException( sprintf( 'Unknown GraphQL type "%s" in the ecommerce schema.', $name ) );
    }

    /**
     * Every defined type (built on demand).
     *
     * @since 1.0.0
     *
     * @return array<int, NamedType&Type>
     */
    public function all(): array
    {
        return array_map( fn ( string $name ): Type => $this->named( $name ), array_keys( $this->factories ) );
    }
}
