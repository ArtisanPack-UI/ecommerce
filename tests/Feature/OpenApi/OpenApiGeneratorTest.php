<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Ecommerce;
use ArtisanPackUI\Ecommerce\OpenApi\OpenApiGenerator;
use ArtisanPackUI\Ecommerce\OpenApi\RuleSchema;
use Illuminate\Routing\Route;
use Illuminate\Validation\Rule;

beforeEach( function (): void {
    $this->spec = app( OpenApiGenerator::class )->generate();
} );

/**
 * Every [ route, method, path-key, operation ] the generator documented.
 *
 * @return array<int, array{0: Route, 1: string, 2: array<string, mixed>}>
 */
function documentedOperations( array $spec ): array
{
    $base       = '/api/ecommerce/v1';
    $operations = [];

    foreach ( app( OpenApiGenerator::class )->routes() as $route ) {
        $uri  = '/' . $route->uri();
        $path = str_starts_with( $uri, $base ) ? substr( $uri, strlen( $base ) ) : $uri;

        foreach ( array_diff( $route->methods(), [ 'HEAD' ] ) as $method ) {
            $operations[] = [ $route, $method, $spec['paths'][ $path ][ strtolower( $method ) ] ?? null ];
        }
    }

    return $operations;
}

it( 'produces an OpenAPI 3.1 document', function (): void {
    expect( $this->spec['openapi'] )->toBe( '3.1.0' )
        ->and( $this->spec['info']['version'] )->toBe( OpenApiGenerator::normalizeVersion( Ecommerce::version() ) ?? OpenApiGenerator::FALLBACK_VERSION )
        ->and( $this->spec['info']['license'] )->toBe( [ 'name' => 'MIT', 'identifier' => 'MIT' ] )
        ->and( $this->spec['servers'][0]['url'] )->toBe( '/api/ecommerce/v1' )
        ->and( $this->spec['components']['securitySchemes'] )->toHaveKeys( [ 'sanctum', 'sessionCookie', 'serviceSignature' ] );
} );

it( 'documents every REST route and method', function (): void {
    $operations = documentedOperations( $this->spec );

    expect( count( $operations ) )->toBeGreaterThan( 40 );

    foreach ( $operations as [ $route, $method, $operation ] ) {
        expect( $operation )->not->toBeNull( sprintf( '%s %s is undocumented', $method, $route->uri() ) )
            ->and( $operation['summary'] )->not->toBeEmpty();
    }
} );

it( 'annotates every endpoint with its rate-limit policy', function (): void {
    foreach ( documentedOperations( $this->spec ) as [ $route, $method, $operation ] ) {
        $policy = collect( $route->gatherMiddleware() )->first( fn ( string $m ): bool => str_starts_with( $m, 'ecommerce.rate-limit:' ) );

        expect( $policy )->not->toBeNull( sprintf( '%s %s has no rate-limit policy', $method, $route->uri() ) )
            ->and( $operation['x-rate-limit-policy'] )->toBe( substr( $policy, strlen( 'ecommerce.rate-limit:' ) ) )
            ->and( $operation['responses'] )->toHaveKey( '429' );
    }
} );

it( 'documents the Idempotency-Key requirement on every mutating endpoint', function (): void {
    foreach ( documentedOperations( $this->spec ) as [ $route, $method, $operation ] ) {
        // Template preview is a read-only POST (engine spec §9.11): no Idempotency-Key.
        $readOnlyPost = 'ecommerce.api.admin.notification-templates.preview' === $route->getName();

        if ( 'ecommerce.webhooks' === $route->getName() || $readOnlyPost || ! in_array( $method, [ 'POST', 'PUT', 'PATCH', 'DELETE' ], true ) ) {
            continue;
        }

        expect( $route->gatherMiddleware() )->toContain( 'ecommerce.idempotency' )
            ->and( $operation['x-idempotency'] )->toBe( 'required' )
            ->and( $operation['parameters'] )->toContain( [ '$ref' => '#/components/parameters/IdempotencyKey' ] )
            ->and( $operation['responses'] )->toHaveKeys( [ '400', '409' ] );
    }

    expect( $this->spec['components']['parameters']['IdempotencyKey']['required'] )->toBeTrue();
} );

it( 'documents auth, abilities, and token scopes for admin endpoints', function (): void {
    $refund = $this->spec['paths']['/orders/{order}/refunds']['post'];

    expect( $refund['x-ecommerce-ability'] )->toBe( 'ecommerce.order.refund' )
        ->and( $refund['x-token-scopes'] )->toBe( [ 'ecommerce:admin', 'ecommerce:orders.refund' ] )
        ->and( $refund['security'] )->toContain( [ 'sanctum' => [] ] )
        ->and( $refund['responses'] )->toHaveKeys( [ '201', '401', '403', '404', '422' ] )
        ->and( $refund['requestBody']['content']['application/json']['schema']['properties']['lines']['items']['required'] )
        ->toBe( [ 'amount', 'order_item_id', 'quantity' ] );

    expect( $this->spec['paths']['/products']['get']['security'] )->toBe( [] )
        ->and( $this->spec['paths']['/products']['get']['responses']['200']['content']['application/json']['schema']['properties']['data']['items'] )
        ->toBe( [ '$ref' => '#/components/schemas/Product' ] );
} );

it( 'keeps operation ids unique and path parameters declared', function (): void {
    $ids = [];

    foreach ( $this->spec['paths'] as $path => $operations ) {
        preg_match_all( '/\{(\w+)\}/', $path, $placeholders );

        foreach ( $operations as $operation ) {
            $ids[]    = $operation['operationId'];
            $declared = collect( $operation['parameters'] ?? [] )->where( 'in', 'path' )->pluck( 'name' )->all();

            expect( $declared )->toEqualCanonicalizing( $placeholders[1] );
        }
    }

    expect( $ids )->toBe( array_values( array_unique( $ids ) ) );
} );

it( 'resolves every $ref', function (): void {
    $refs = [];
    array_walk_recursive( $this->spec, function ( $value, $key ) use ( &$refs ): void {
        if ( '$ref' === $key ) {
            $refs[] = $value;
        }
    } );

    expect( $refs )->not->toBeEmpty();

    foreach ( array_unique( $refs ) as $ref ) {
        $target = $this->spec;

        foreach ( explode( '/', substr( $ref, 2 ) ) as $segment ) {
            expect( is_array( $target ) && array_key_exists( $segment, $target ) )->toBeTrue( sprintf( 'Unresolved $ref %s', $ref ) );
            $target = $target[ $segment ];
        }
    }
} );

it( 'documents outbound webhook events', function (): void {
    expect( $this->spec['webhooks'] )->toHaveKey( 'order.refunded' )
        ->and( $this->spec['webhooks']['order.refunded']['post']['parameters'][0]['name'] )->toBe( 'X-ArtisanPack-Signature' );
} );

it( 'writes the spec from the artisan command', function (): void {
    $path = sys_get_temp_dir() . '/ecommerce-openapi-' . uniqid() . '.json';

    $this->artisan( 'ecommerce:generate-openapi', [ '--output' => $path ] )->assertSuccessful();

    expect( json_decode( (string) file_get_contents( $path ), true )['openapi'] )->toBe( '3.1.0' );

    unlink( $path );
} );

it( 'uses the spec version it is given, without a leading v', function ( string $given, string $expected ): void {
    expect( app( OpenApiGenerator::class )->generate( $given )['info']['version'] )->toBe( $expected );
} )->with( [
    'a tag'         => [ 'v1.0.2', '1.0.2' ],
    'a version'     => [ '2.3.4', '2.3.4' ],
    'a pre-release' => [ 'v2.0.0-beta.1', '2.0.0-beta.1' ],
    'build data'    => [ '1.2.3+build.5', '1.2.3+build.5' ],
    'zeros'         => [ '0.0.0', '0.0.0' ],
    'alphanumerics' => [ '1.0.0-0alpha.x-y.7+001', '1.0.0-0alpha.x-y.7+001' ],
] );

it( 'refuses a spec version that is not a semantic version', function ( string $given ): void {
    app( OpenApiGenerator::class )->generate( $given );
} )->with( [
    'a branch'                 => [ 'dev-main' ],
    'two parts'                => [ '1.0' ],
    'shell characters'         => [ 'v1.0.2; rm -rf /' ],
    'empty'                    => [ '' ],
    'a leading zero'           => [ '01.2.3' ],
    'a leading zero in minor'  => [ '1.02.3' ],
    'a numeric pre-release 0x' => [ '1.2.3-beta.01' ],
    'an empty pre-release id'  => [ '1.2.3-beta..1' ],
    'an empty build id'        => [ '1.2.3+build.' ],
    'a trailing newline'       => [ "1.2.3\nx" ],
] )->throws( InvalidArgumentException::class );

it( 'reads a version only when it is a semantic version', function ( string $given, ?string $expected ): void {
    expect( OpenApiGenerator::normalizeVersion( $given ) )->toBe( $expected );
} )->with( [
    'a tag'          => [ 'v1.0.2', '1.0.2' ],
    'spaces'         => [ ' 1.0.2 ', '1.0.2' ],
    'a branch'       => [ 'dev-main', null ],
    'a release line' => [ 'dev-release/1.x', null ],
    'dev'            => [ 'dev', null ],
] );

it( 'never falls back to a release number', function (): void {
    expect( OpenApiGenerator::FALLBACK_VERSION )->toBe( '0.0.0-dev' )
        ->and( (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/OpenApi/OpenApiGenerator.php' ) )
        ->not->toMatch( "/: '\\d+\\.\\d+\\.\\d+';/" );
} );

it( 'sets the spec version from the artisan command', function (): void {
    $path = sys_get_temp_dir() . '/ecommerce-openapi-' . uniqid() . '.json';

    $this->artisan( 'ecommerce:generate-openapi', [ '--output' => $path, '--spec-version' => 'v9.8.7' ] )->assertSuccessful();

    expect( json_decode( (string) file_get_contents( $path ), true )['info']['version'] )->toBe( '9.8.7' );

    unlink( $path );
} );

it( 'fails without writing when the command gets a malformed or empty spec version', function ( string $given ): void {
    $path = sys_get_temp_dir() . '/ecommerce-openapi-' . uniqid() . '.json';

    $this->artisan( 'ecommerce:generate-openapi', [ '--output' => $path, '--spec-version' => $given ] )
        ->expectsOutputToContain( 'is not a semantic version' )
        ->assertFailed();

    expect( file_exists( $path ) )->toBeFalse();
} )->with( [ 'a word' => [ 'latest' ], 'empty' => [ '' ] ] );

it( 'converts validation rules to JSON Schema', function (): void {
    $schema = RuleSchema::fromRules( [
        'name'          => [ 'required', 'string', 'max:20' ],
        'status'        => [ 'nullable', Rule::in( [ 'a', 'b' ] ) ],
        'slug'          => 'string|regex:/^[a-z]+$/',
        'tags'          => [ 'array', 'min:1' ],
        'tags.*'        => [ 'string', 'size:2' ],
        'lines.*.qty'   => [ 'required', 'integer', 'min:1' ],
        'currency'      => [ 'nullable', 'string', 'size:3' ],
    ] );

    expect( $schema['required'] )->toBe( [ 'name' ] )
        ->and( $schema['properties']['name'] )->toBe( [ 'type' => 'string', 'maxLength' => 20 ] )
        ->and( $schema['properties']['status']['enum'] )->toBe( [ 'a', 'b' ] )
        ->and( $schema['properties']['slug']['pattern'] )->toBe( '^[a-z]+$' )
        ->and( $schema['properties']['tags'] )->toMatchArray( [ 'type' => 'array', 'minItems' => 1 ] )
        ->and( $schema['properties']['tags']['items'] )->toBe( [ 'type' => 'string', 'minLength' => 2, 'maxLength' => 2 ] )
        ->and( $schema['properties']['lines']['items']['required'] )->toBe( [ 'qty' ] )
        ->and( $schema['properties']['currency']['type'] )->toBe( [ 'string', 'null' ] );
} );

it( 'documents listing query parameters', function (): void {
    $parameters = fn ( string $path ): array => collect( $this->spec['paths'][ $path ]['get']['parameters'] ?? [] )
        ->reject( fn ( array $parameter ): bool => isset( $parameter['$ref'] ) )
        ->keyBy( 'name' )
        ->all();

    $products = $parameters( '/products' );

    expect( $products )->toHaveKeys( [ 'filter', 'sort', 'include', 'per_page', 'cursor', 'q', 'currency', 'attributes', 'facets', 'page' ] )
        ->and( $products['filter']['style'] )->toBe( 'deepObject' )
        ->and( $products['filter']['schema']['properties'] )->toHaveKeys( [ 'category', 'tag', 'price_min', 'in_stock', 'ids' ] )
        ->and( $products['filter']['schema']['properties']['ids']['pattern'] )->toBe( '^[0-9]+(,[0-9]+)*$' )
        ->and( $products['per_page']['schema']['maximum'] )->toBe( (int) config( 'artisanpack.ecommerce.api.max_per_page' ) )
        ->and( $products['include']['description'] )->toContain( 'images' );

    $search = $parameters( '/search' );

    expect( $search['q']['required'] )->toBeTrue()
        ->and( $search )->toHaveKeys( [ 'page', 'per_page', 'include' ] )
        ->and( $search['per_page']['schema']['maximum'] )->toBe( (int) config( 'artisanpack.ecommerce.api.max_per_page' ) );

    expect( $parameters( '/admin/reports/{report}' ) )->toHaveKeys( [ 'from', 'to', 'interval', 'compare' ] );

    // Includes come from the controller's map on single-resource reads too.
    expect( $parameters( '/orders/{order}' )['include']['description'] )->toContain( 'shipments' );

    // A plain (unpaginated) list has no cursor.
    expect( $parameters( '/me/addresses' ) )->not->toHaveKey( 'cursor' );
} );

it( 'gives every success response a real schema', function (): void {
    foreach ( $this->spec['paths'] as $path => $operations ) {
        foreach ( $operations as $method => $operation ) {
            if ( 'get' === $method ) {
                expect( $operation )->not->toHaveKey( 'requestBody' );
            }

            foreach ( $operation['responses'] as $status => $response ) {
                if ( ! str_starts_with( (string) $status, '2' ) ) {
                    continue;
                }

                foreach ( $response['content'] ?? [] as $type => $content ) {
                    $schema = $content['schema'] ?? [];

                    expect( $schema )->not->toBe( [ 'type' => 'object' ], sprintf( '%s %s %s has a bare object schema', $method, $path, $status ) )
                        ->and( $schema )->not->toBe( [ 'type' => 'object', 'additionalProperties' => true ], sprintf( '%s %s %s has a free-form schema', $method, $path, $status ) );
                }
            }
        }
    }
} );

it( 'documents maps as objects and file downloads as binary', function (): void {
    $options = $this->spec['paths']['/carts/{cart}/items']['post']['requestBody']['content']['application/json']['schema']['properties']['options'];

    expect( (array) $options['type'] )->toContain( 'object' )->not->toContain( 'array' )
        ->and( $options['maxProperties'] )->toBe( 20 );

    $stream = $this->spec['paths']['/downloads/{token}/stream']['get']['responses'];

    expect( $stream )->toHaveKeys( [ '200', '206', '416' ] )
        ->and( array_keys( $stream['200']['content'] ) )->toBe( [ 'application/octet-stream' ] )
        ->and( array_keys( $stream['206']['content'] ) )->toBe( [ 'application/octet-stream' ] );
} );

it( 'never emits an empty list where a schema object belongs', function (): void {
    $json = json_encode( app( OpenApiGenerator::class )->generate(), JSON_THROW_ON_ERROR );

    expect( $json )->not->toContain( '"schema":[]' )
        ->and( $json )->not->toContain( '"properties":[]' )
        ->and( $json )->not->toMatch( '/"items":\[\]/' );
} );
