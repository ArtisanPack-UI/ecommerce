<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Contracts\KanbanCardWidget;
use ArtisanPackUI\Ecommerce\Kanban\KanbanCardRenderer;
use ArtisanPackUI\Ecommerce\Models\KanbanColumn;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Registries\KanbanCardWidgetRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

require_once __DIR__ . '/KanbanTestHelpers.php';

uses( RefreshDatabase::class );

afterEach( function (): void {
    removeAllFilters( 'ap.ecommerce.kanban.widgetRendered' );
    removeAllFilters( 'ap.ecommerce.kanban.orderTags' );
    Carbon::setTestNow();
} );

/**
 * The rendered widgets for a fresh order card, keyed by widget key.
 *
 * @param  array<int, string>    $widgets     Column widget keys.
 * @param  array<string, mixed>  $attributes  Order columns.
 *
 * @return array<string, array<string, string|null>>
 */
function renderedCard( array $widgets, array $attributes = [], array $types = [ 'simple', 'simple' ] ): array
{
    $board  = kanbanBoard( [], [ [ 'processing', 'Printing' ] ] );
    $column = kanbanColumn( $board, 'Printing' );
    $column->update( [ 'card_widgets' => $widgets ] );

    $order = kanbanOrder( array_merge( [ 'system_status' => 'processing' ], $attributes ), $types );
    putOnBoard( $order, $column );

    return collect( app( KanbanCardRenderer::class )->render( $order, $column->fresh( 'board' ) ) )->keyBy( 'key' )->all();
}

it( 'registers the built-in widgets', function (): void {
    expect( app( KanbanCardWidgetRegistry::class )->keys() )->toBe( [
        'total', 'item-count', 'customer', 'shipping-method', 'tags', 'days-in-column', 'payment-status', 'fulfillment-status',
    ] );
} );

it( 'renders framework-agnostic payloads for the built-in widgets', function (): void {
    $card = renderedCard( [ 'total', 'item-count', 'customer', 'shipping-method', 'tags', 'payment-status', 'fulfillment-status' ], [
        'total_amount'        => 12_400,
        'total_currency'      => 'USD',
        'billing_address'     => [ 'first_name' => 'Ada', 'last_name' => 'Lovelace' ],
        'email'               => 'ada@example.test',
        'shipping_method_key' => 'flat-rate',
        'meta'                => [ 'tags' => [ 'rush', 'gift' ] ],
        'payment_status'      => 'paid',
        'fulfillment_status'  => 'partial',
    ] );

    expect( $card['total']['value'] )->toContain( '124.00' )
        ->and( $card['item-count']['value'] )->toBe( '2 items' )
        ->and( $card['customer'] )->toMatchArray( [ 'value' => 'Ada Lovelace', 'tooltip' => 'ada@example.test' ] )
        ->and( $card['shipping-method']['value'] )->toBe( 'Flat rate' )
        ->and( $card['tags']['value'] )->toBe( 'rush, gift' )
        ->and( $card['payment-status'] )->toMatchArray( [ 'value' => 'Paid', 'tone' => 'success' ] )
        ->and( $card['fulfillment-status'] )->toMatchArray( [ 'value' => 'Partial', 'tone' => 'warning' ] );

    foreach ( $card as $payload ) {
        expect( $payload )->toHaveKeys( [ 'key', 'label', 'value', 'tone', 'icon', 'tooltip', 'href', 'refresh_channel' ] );
    }
} );

it( 'counts days in the current column and warns when the card goes stale', function (): void {
    Carbon::setTestNow( '2026-10-01 12:00:00' );
    $board  = kanbanBoard( [], [ [ 'processing', 'Printing' ] ] );
    $column = kanbanColumn( $board, 'Printing' );
    $order  = kanbanOrder( [ 'system_status' => 'processing' ] );

    putOnBoard( $order, $column )->update( [ 'moved_at' => Carbon::parse( '2026-09-27 11:00:00' ) ] );

    $widget = app( KanbanCardWidgetRegistry::class )->get( 'days-in-column' );

    expect( $widget->render( $order, $column ) )->toMatchArray( [ 'value' => '4', 'tone' => 'warning' ] );

    Carbon::setTestNow( '2026-10-08 12:00:00' );

    expect( $widget->render( $order, $column )['tone'] )->toBe( 'danger' );
} );

it( 'falls back from column widgets to board settings to the configured default', function (): void {
    $board    = kanbanBoard( [ 'settings' => [ 'card_widgets' => [ 'tags' ] ] ], [ [ 'processing', 'Printing' ] ] );
    $column   = kanbanColumn( $board, 'Printing' );
    $renderer = app( KanbanCardRenderer::class );

    expect( $renderer->widgetKeys( $column ) )->toBe( [ 'tags' ] );

    $column->update( [ 'card_widgets' => [ 'total', 'total', 'customer' ] ] );

    expect( $renderer->widgetKeys( $column->fresh( 'board' ) ) )->toBe( [ 'total', 'customer' ] );

    $board->update( [ 'settings' => [] ] );
    $column->update( [ 'card_widgets' => [] ] );

    expect( $renderer->widgetKeys( $column->fresh( 'board' ) ) )->toBe( [ 'total', 'item-count', 'customer' ] );
} );

it( 'skips unknown and throwing widgets and normalizes bad tones', function (): void {
    $registry = app( KanbanCardWidgetRegistry::class );

    $registry->register( 'broken', new class () implements KanbanCardWidget {
        public function key(): string
        {
            return 'broken';
        }

        public function label(): string
        {
            return 'Broken';
        }

        public function render( Order $order, KanbanColumn $column ): array
        {
            throw new RuntimeException( 'satellite API down' );
        }

        public function refreshSubscription( Order $order ): ?string
        {
            return null;
        }
    } );

    $registry->register( 'printful:order-status', new class () implements KanbanCardWidget {
        public function key(): string
        {
            return 'printful:order-status';
        }

        public function label(): string
        {
            return 'Printful';
        }

        public function render( Order $order, KanbanColumn $column ): array
        {
            return [ 'label' => 'Printful', 'value' => 'Printing', 'tone' => 'purple' ];
        }

        public function refreshSubscription( Order $order ): ?string
        {
            return 'printful.order.' . $order->id;
        }
    } );

    $card = renderedCard( [ 'broken', 'not-registered', 'printful:order-status' ] );

    expect( array_keys( $card ) )->toBe( [ 'printful:order-status' ] )
        ->and( $card['printful:order-status']['tone'] )->toBe( 'neutral' )
        ->and( $card['printful:order-status']['refresh_channel'] )->toStartWith( 'printful.order.' );
} );

it( 'runs every payload through the widgetRendered filter', function (): void {
    addFilter( 'ap.ecommerce.kanban.widgetRendered', function ( array $payload, KanbanCardWidget $widget, Order $order ): array {
        $payload['href'] = '/admin/orders/' . $order->id;

        return $payload;
    } );

    addFilter( 'ap.ecommerce.kanban.orderTags', fn ( array $tags ): array => [ ...$tags, 'vip' ] );

    $card = renderedCard( [ 'tags' ], [ 'meta' => [ 'tags' => [ 'rush' ] ] ] );

    expect( $card['tags']['href'] )->toStartWith( '/admin/orders/' )
        ->and( $card['tags']['value'] )->toBe( 'rush, vip' );
} );
