<?php

declare( strict_types=1 );

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

require_once __DIR__ . '/ApiTestHelpers.php';
require_once __DIR__ . '/../Reports/ReportFixtures.php';

uses( RefreshDatabase::class );

beforeEach( function (): void {
    configureReportStore();
} );

it( 'lists the reports', function (): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->getJson( '/api/ecommerce/v1/admin/reports' )
        ->assertOk()
        ->assertJsonPath( 'data.0', [ 'key' => 'sales', 'label' => 'Sales over time', 'ranged' => true ] );
} );

it( 'runs a report with a range, interval, comparison, and options', function (): void {
    seedReportFixtures();

    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->getJson( '/api/ecommerce/v1/admin/reports/sales?from=2026-03-01&to=2026-03-03&interval=day&compare=1' )
        ->assertOk()
        ->assertJsonPath( 'data.report', 'sales' )
        ->assertJsonPath( 'data.currency', 'USD' )
        ->assertJsonPath( 'data.totals.total', 29_955 )
        ->assertJsonPath( 'data.notices.converted_orders', 1 )
        ->assertJsonPath( 'data.previous.totals.refunds', 1_100 );

    $this->getJson( '/api/ecommerce/v1/admin/reports/top-products?from=2026-03-01&to=2026-03-03&sort=units&limit=1' )
        ->assertOk()
        ->assertJsonCount( 1, 'data.rows' )
        ->assertJsonPath( 'data.rows.0.name', 'Mug' );

    $this->getJson( '/api/ecommerce/v1/admin/reports/inventory' )
        ->assertOk()
        ->assertJsonPath( 'data.range', null )
        ->assertJsonPath( 'data.totals.stock_value', 17_400 );
} );

it( 'validates the range and options', function ( string $query, string $field ): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->getJson( "/api/ecommerce/v1/admin/reports/top-products?{$query}" )
        ->assertStatus( 422 )
        ->assertJsonPath( 'errors.0.field', $field );
} )->with( [
    'bad date'      => [ 'from=yesterday', 'from' ],
    'reversed'      => [ 'from=2026-03-05&to=2026-03-01', 'to' ],
    'interval'      => [ 'interval=hour', 'interval' ],
    'limit too big' => [ 'limit=500', 'limit' ],
    'sort'          => [ 'sort=price', 'sort' ],
    'too long'      => [ 'from=2020-01-01&to=2026-01-01', 'from' ],
] );

it( 'returns 404 for an unknown report', function (): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' )->getJson( '/api/ecommerce/v1/admin/reports/nope' )->assertNotFound();
} );

it( 'needs report.view', function (): void {
    $this->actingAs( ecommerceShopper(), 'sanctum' );

    $this->getJson( '/api/ecommerce/v1/admin/reports' )->assertForbidden();
    $this->getJson( '/api/ecommerce/v1/admin/reports/sales' )->assertForbidden();

    Gate::define( 'ecommerce.report.view', fn (): bool => true );

    $this->getJson( '/api/ecommerce/v1/admin/reports/sales' )->assertOk();
} );
