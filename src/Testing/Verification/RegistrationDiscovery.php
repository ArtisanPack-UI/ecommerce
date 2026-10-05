<?php

/**
 * RegistrationDiscovery.
 *
 * Walks every engine registry and single-implementation container binding
 * and returns the entries whose implementation class lives inside the
 * satellite's namespaces — i.e. what the satellite's service provider
 * registered during boot. Parent plan §15.2.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Testing\Verification;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use ReflectionClass;
use ReflectionFunction;
use Throwable;

/**
 * Discovers a satellite's registrations inside a booted application.
 *
 * Registry entries are read without resolving them where possible (a
 * gateway's constructor may need credentials the verifier doesn't have),
 * so a satellite whose implementations only work with live config can
 * still be verified by its stubbed contract tests.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class RegistrationDiscovery
{
    /**
     * @since 1.0.0
     *
     * @param  Application  $app  Booted application with the satellite's providers registered.
     */
    public function __construct( private readonly Application $app )
    {
    }

    /**
     * Every registration owned by the satellite, ordered by contract then key.
     *
     * @since 1.0.0
     *
     * @param  SatelliteManifest  $manifest  Satellite under verification.
     *
     * @return array<int, Registration>
     */
    public function discover( SatelliteManifest $manifest ): array
    {
        $found = [];

        foreach ( ContractSuiteMap::REGISTRIES as $registryClass => $contract ) {
            if ( ! $this->app->bound( $registryClass ) ) {
                continue;
            }

            foreach ( $this->registryEntries( $this->app->make( $registryClass ) ) as $key => $class ) {
                if ( null !== $class && $manifest->owns( $class ) ) {
                    $found[] = new Registration( $contract, (string) $key, $class, ContractSuiteMap::suiteFor( $contract ) );
                }
            }
        }

        foreach ( ContractSuiteMap::BINDINGS as $contract ) {
            $registration = $this->bindingRegistration( $contract, $manifest );

            if ( null !== $registration ) {
                $found[] = $registration;
            }
        }

        usort( $found, static fn ( Registration $a, Registration $b ): int => [ $a->contract, $a->key ] <=> [ $b->contract, $b->key ] );

        return $found;
    }

    /**
     * Registry key → implementation class for every entry in `$registry`.
     *
     * Reads the registry's `entries` store directly (every engine registry
     * keeps `[ 'entry' => class-string|object, 'meta' => array ]` rows);
     * falls back to resolving each key when a registry stores entries
     * differently.
     *
     * @since 1.0.0
     *
     * @param  object  $registry  Registry instance.
     *
     * @return array<string, string|null>
     */
    protected function registryEntries( object $registry ): array
    {
        for ( $reflection = new ReflectionClass( $registry ); false !== $reflection; $reflection = $reflection->getParentClass() ) {
            if ( ! $reflection->hasProperty( 'entries' ) ) {
                continue;
            }

            $property = $reflection->getProperty( 'entries' );

            if ( $property->getDeclaringClass()->getName() !== $reflection->getName() ) {
                continue;
            }

            $entries = $property->getValue( $registry );

            if ( ! is_array( $entries ) ) {
                break;
            }

            $classes = [];

            foreach ( $entries as $key => $row ) {
                $entry = is_array( $row ) && array_key_exists( 'entry', $row ) ? $row['entry'] : $row;

                $classes[ (string) $key ] = match ( true ) {
                    is_string( $entry ) => ltrim( $entry, '\\' ),
                    is_object( $entry ) => $entry::class,
                    default             => null,
                };
            }

            return $classes;
        }

        $classes = [];

        if ( ! method_exists( $registry, 'keys' ) || ! method_exists( $registry, 'get' ) ) {
            return $classes;
        }

        foreach ( $registry->keys() as $key ) {
            try {
                $classes[ (string) $key ] = $registry->get( $key )::class;
            } catch ( Throwable ) {
                $classes[ (string) $key ] = null;
            }
        }

        return $classes;
    }

    /**
     * The registration for the container binding of `$contract`, when the
     * satellite owns it.
     *
     * Laravel wraps a class-string concrete in a closure, so the class is
     * read from the closure's captured `$concrete` without building
     * anything — a satellite's `CartStorage` may need Redis or credentials
     * the verifier doesn't have. A custom factory closure is only resolved
     * when it is defined inside the satellite; if resolving it fails, the
     * binding is still reported (as failed) rather than dropped, so an
     * unbuildable implementation can never be silently skipped.
     *
     * @since 1.0.0
     *
     * @param  string             $contract  Contract interface FQCN.
     * @param  SatelliteManifest  $manifest  Satellite under verification.
     *
     * @return Registration|null
     */
    protected function bindingRegistration( string $contract, SatelliteManifest $manifest ): ?Registration
    {
        if ( ! $this->app->bound( $contract ) ) {
            return null;
        }

        $key      = ContractSuiteMap::shortName( $contract );
        $suite    = ContractSuiteMap::suiteFor( $contract );
        $concrete = $this->app->getBindings()[ $contract ]['concrete'] ?? null;
        $class    = $this->concreteClass( $concrete );

        if ( null !== $class ) {
            return $manifest->owns( $class ) ? new Registration( $contract, $key, $class, $suite ) : null;
        }

        if ( ! $concrete instanceof Closure || ! $this->definedIn( $concrete, $manifest->path ) ) {
            return null;
        }

        try {
            $class = $this->app->make( $contract )::class;
        } catch ( Throwable $e ) {
            return new Registration( $contract, $key, $contract, $suite, __( 'The :contract binding could not be resolved: :reason', [
                'contract' => $key,
                'reason'   => $e->getMessage(),
            ] ) );
        }

        return new Registration( $contract, $key, $class, $suite );
    }

    /**
     * The class a binding's concrete names, without resolving it.
     *
     * @since 1.0.0
     *
     * @param  mixed  $concrete  Binding concrete (class string or closure).
     *
     * @return string|null
     */
    protected function concreteClass( mixed $concrete ): ?string
    {
        if ( $concrete instanceof Closure ) {
            $concrete = ( new ReflectionFunction( $concrete ) )->getClosureUsedVariables()['concrete'] ?? null;
        }

        return is_string( $concrete ) && class_exists( $concrete ) ? ltrim( $concrete, '\\' ) : null;
    }

    /**
     * Whether `$closure` is declared in a file under `$path` (outside its
     * `vendor/` directory).
     *
     * @since 1.0.0
     *
     * @param  Closure  $closure  Binding factory.
     * @param  string   $path     Satellite root.
     *
     * @return bool
     */
    protected function definedIn( Closure $closure, string $path ): bool
    {
        $file = (string) ( new ReflectionFunction( $closure ) )->getFileName();
        $root = rtrim( (string) ( realpath( $path ) ?: $path ), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;

        return str_starts_with( $file, $root ) && ! str_starts_with( $file, $root . 'vendor' . DIRECTORY_SEPARATOR );
    }
}
