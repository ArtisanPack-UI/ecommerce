<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Contracts\KanbanAutomationTrigger;
use ArtisanPackUI\Ecommerce\Events\KanbanAutomationTriggered;
use ArtisanPackUI\Ecommerce\Mail\KanbanAutomationMail;
use ArtisanPackUI\Ecommerce\Models\KanbanAutomation;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderTimelineEntry;
use ArtisanPackUI\Ecommerce\Registries\KanbanAutomationRegistry;
use ArtisanPackUI\Ecommerce\Services\KanbanBoardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

require_once __DIR__ . '/KanbanTestHelpers.php';

uses( RefreshDatabase::class );

afterEach( function (): void {
    removeAllActions( 'ap.ecommerce.kanban.automationFired' );
} );

/**
 * A board Printing → Packing → Shipped with a processing order in Printing.
 *
 * @return array{0: Order, 1: ArtisanPackUI\Ecommerce\Models\KanbanBoard}
 */
function automationBoard(): array
{
    $board = kanbanBoard( [ 'key' => 'fulfilment' ], [ [ 'processing', 'Printing' ], [ 'processing', 'Packing' ], [ 'complete', 'Shipped', true ] ] );
    $order = kanbanOrder( [ 'system_status' => 'processing', 'total_amount' => 12_400 ] );

    putOnBoard( $order, kanbanColumn( $board, 'Printing' ) );

    return [ $order, $board ];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function automationOn( ArtisanPackUI\Ecommerce\Models\KanbanBoard $board, string $toLabel, string $field, array $attributes = [] ): KanbanAutomation
{
    return KanbanAutomation::factory()->create( array_merge( [
        'board_id'       => $board->id,
        'to_column_id'   => kanbanColumn( $board, $toLabel )->id,
        'trigger_key'    => 'update-order-field',
        'trigger_config' => [ 'field' => 'meta.' . $field, 'value' => true ],
    ], $attributes ) );
}

it( 'registers the reference triggers', function (): void {
    expect( app( KanbanAutomationRegistry::class )->keys() )->toBe( [
        'send-email', 'dispatch-job', 'webhook', 'update-order-field', 'create-shipment', 'print-shipping-label',
    ] );
} );

it( 'runs automations whose to-column matches and whose from-column matches or is any', function (): void {
    [ $order, $board ] = automationBoard();

    automationOn( $board, 'Packing', 'from_any' );
    automationOn( $board, 'Packing', 'from_printing', [ 'from_column_id' => kanbanColumn( $board, 'Printing' )->id ] );
    automationOn( $board, 'Packing', 'from_shipped', [ 'from_column_id' => kanbanColumn( $board, 'Shipped' )->id ] );
    automationOn( $board, 'Shipped', 'to_shipped' );
    automationOn( $board, 'Packing', 'inactive', [ 'is_active' => false ] );

    Event::fake( [ KanbanAutomationTriggered::class ] );

    app( KanbanBoardService::class )->move( $order, kanbanColumn( $board, 'Packing' ) );

    expect( array_keys( $order->fresh()->meta ) )->toEqualCanonicalizing( [ 'from_any', 'from_printing' ] );
    Event::assertDispatchedTimes( KanbanAutomationTriggered::class, 2 );
    expect( OrderTimelineEntry::query()->where( 'event_type', 'kanban.automation_fired' )->count() )->toBe( 2 );
} );

it( 'only fires when the automation conditions pass for the order', function (): void {
    [ $order, $board ] = automationBoard();

    automationOn( $board, 'Packing', 'big_order', [ 'conditions' => [ 'type' => 'min-subtotal', 'config' => [ 'amount' => 100 ] ] ] );
    automationOn( $board, 'Packing', 'huge_order', [ 'conditions' => [ 'type' => 'min-subtotal', 'config' => [ 'amount' => 10_000_000 ] ] ] );

    app( KanbanBoardService::class )->move( $order, kanbanColumn( $board, 'Packing' ) );

    expect( array_keys( $order->fresh()->meta ) )->toBe( [ 'big_order' ] );
} );

it( 'isolates a failing automation and records it on the timeline', function (): void {
    [ $order, $board ] = automationBoard();

    automationOn( $board, 'Packing', 'unused', [ 'trigger_key' => 'no-such-trigger' ] );
    automationOn( $board, 'Packing', 'bad', [ 'trigger_config' => [ 'field' => 'total_amount', 'value' => 1 ] ] );
    automationOn( $board, 'Packing', 'still_runs' );

    app( KanbanBoardService::class )->move( $order, kanbanColumn( $board, 'Packing' ) );

    $fresh    = $order->fresh();
    $failures = OrderTimelineEntry::query()->where( 'event_type', 'kanban.automation_failed' )->get();

    expect( $fresh->meta )->toBe( [ 'still_runs' => true ] )
        ->and( $fresh->total_amount )->toBe( 12_400 )
        ->and( $failures )->toHaveCount( 2 )
        ->and( $failures->pluck( 'payload.trigger_key' )->all() )->toEqualCanonicalizing( [ 'no-such-trigger', 'update-order-field' ] );
} );

it( 'fires the automationFired hook, and lets satellites add triggers', function (): void {
    [ $order, $board ] = automationBoard();
    $slack             = new class () implements KanbanAutomationTrigger {
        /** @var array<int, string> */
        public array $sent = [];

        public function key(): string
        {
            return 'slack:notify-slack';
        }

        public function label(): string
        {
            return 'Notify Slack';
        }

        public function fire( Order $order, KanbanAutomation $automation, array $config ): void
        {
            $this->sent[] = $config['channel'] . ':' . $order->order_number;
        }
    };

    app( KanbanAutomationRegistry::class )->register( 'slack:notify-slack', $slack );
    automationOn( $board, 'Shipped', 'unused', [ 'trigger_key' => 'slack:notify-slack', 'trigger_config' => [ 'channel' => '#shipping' ] ] );

    $fired = [];
    addAction( 'ap.ecommerce.kanban.automationFired', function ( KanbanAutomation $automation, Order $o ) use ( &$fired ): void {
        $fired[] = $automation->trigger_key;
    } );

    app( KanbanBoardService::class )->move( $order, kanbanColumn( $board, 'Shipped' ) );

    expect( $slack->sent )->toBe( [ '#shipping:' . $order->order_number ] )
        ->and( $fired )->toBe( [ 'slack:notify-slack' ] );
} );

it( 'sends the configured email when a card reaches a column', function (): void {
    [ $order, $board ] = automationBoard();

    Mail::fake();
    automationOn( $board, 'Packing', 'unused', [
        'trigger_key'    => 'send-email',
        'trigger_config' => [ 'to' => [ 'printer@example.test', 'customer' ], 'subject' => 'Pack {order_number}', 'body' => 'Now in {column} on {board}' ],
    ] );

    app( KanbanBoardService::class )->move( $order, kanbanColumn( $board, 'Packing' ) );

    Mail::assertQueued( KanbanAutomationMail::class, fn ( KanbanAutomationMail $mail ): bool => $mail->hasTo( 'printer@example.test' )
        && $mail->hasTo( $order->email )
        && 'Pack ' . $order->order_number === $mail->subjectLine
        && 'Now in Packing on ' . $board->name === $mail->bodyText );
} );

it( 'escapes the email body', function (): void {
    $html = ( new KanbanAutomationMail( 'Hi', "<script>x</script>\nline two" ) )->render();

    expect( $html )->toContain( '&lt;script&gt;' )->toContain( '<br />' )->not->toContain( '<script>' );
} );
