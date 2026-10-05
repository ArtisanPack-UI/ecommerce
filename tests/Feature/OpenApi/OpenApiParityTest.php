<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Auth\TokenAbilities;
use ArtisanPackUI\Ecommerce\OpenApi\OpenApiGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Tests\Fixtures\ApiUser;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    Gate::define( 'ecommerce.admin', fn ( $user ): bool => 1 === (int) $user->getAuthIdentifier() );
    Sanctum::actingAs( ApiUser::make( 1 ), [ TokenAbilities::ADMIN ] );
} );

/**
 * A value that fits a documented filter schema.
 *
 * @param  array<string, mixed>  $schema
 */
function openApiSampleFilterValue( array $schema ): string
{
    return match ( true ) {
        isset( $schema['pattern'] )                                        => '1',
        in_array( $schema['type'] ?? null, [ 'integer', 'number' ], true ) => '1',
        'boolean' === ( $schema['type'] ?? null )                          => 'true',
        default                                                            => 'sample',
    };
}

/**
 * Every documented GET list without path parameters, with its listing
 * parameters keyed by name. `me/*` lists need a shopper with a customer
 * record and are covered by the shopper tests.
 *
 * @return array<string, array<string, array<string, mixed>>>
 */
function openApiDocumentedLists(): array
{
    $lists = [];

    foreach ( app( OpenApiGenerator::class )->generate()['paths'] as $path => $operations ) {
        if ( str_contains( $path, '{' ) || str_starts_with( $path, '/me/' ) || ! isset( $operations['get'] ) ) {
            continue;
        }

        $parameters = collect( $operations['get']['parameters'] ?? [] )
            ->reject( fn ( array $parameter ): bool => isset( $parameter['$ref'] ) )
            ->keyBy( 'name' )
            ->all();

        if ( isset( $parameters['sort'] ) || isset( $parameters['include'] ) ) {
            $lists[ $path ] = $parameters;
        }
    }

    return $lists;
}

// A documented parameter is never refused as unknown (400) or invalid
// (422), and never errors; lookups may find nothing (404) for samples.
it( 'accepts every documented filter, sort, and include', function (): void {
    $lists = openApiDocumentedLists();

    expect( array_keys( $lists ) )->toContain( '/products', '/orders', '/customers' );

    foreach ( $lists as $path => $parameters ) {
        $required = collect( $parameters )->filter( fn ( array $parameter ): bool => true === ( $parameter['required'] ?? false ) )->map( fn ( array $parameter, string $name ): string => 'email' === $name ? 'sample@example.test' : 'sample' )->all();
        $url      = '/api/ecommerce/v1' . $path . '?' . http_build_query( $required ) . '&';

        foreach ( $parameters['filter']['schema']['properties'] ?? [] as $name => $schema ) {
            $query = $url . http_build_query( [ 'filter' => [ $name => openApiSampleFilterValue( $schema ) ] ] );

            expect( $this->getJson( $query )->status() )->toBeIn( [ 200, 404 ], $query );
        }

        if ( isset( $parameters['sort'] ) ) {
            preg_match( '/One of: (.*?)\. /', (string) ( $parameters['sort']['description'] ?? '' ), $match );

            foreach ( $parameters['sort']['schema']['enum'] ?? explode( ', ', $match[1] ) as $sort ) {
                $query = $url . 'sort=' . urlencode( $sort );

                expect( $this->getJson( $query )->status() )->toBeIn( [ 200, 404 ], $query );
            }
        }

        if ( isset( $parameters['include'] ) ) {
            preg_match( '/from: (.*?)\. /', (string) $parameters['include']['description'], $match );

            $query = $url . 'include=' . urlencode( str_replace( ', ', ',', $match[1] ) );

            expect( $this->getJson( $query )->status() )->toBeIn( [ 200, 404 ], $query );
        }
    }
} );
