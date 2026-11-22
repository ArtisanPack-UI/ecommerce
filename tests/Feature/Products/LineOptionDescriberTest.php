<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Contracts\DescribesLineOptions;
use ArtisanPackUI\Ecommerce\Mail\NotificationTemplateMail;
use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use ArtisanPackUI\Ecommerce\Notifications\EcommerceNotification;
use ArtisanPackUI\Ecommerce\Notifications\NotificationCatalog;
use ArtisanPackUI\Ecommerce\Notifications\NotificationContext;
use ArtisanPackUI\Ecommerce\ProductTypes\SimpleProductType;
use ArtisanPackUI\Ecommerce\Registries\ProductTypeRegistry;
use ArtisanPackUI\Ecommerce\Services\CheckoutService;
use ArtisanPackUI\Ecommerce\Services\OrderPlacementService;
use ArtisanPackUI\Ecommerce\Services\StorefrontCartService;
use ArtisanPackUI\Ecommerce\Support\LineOptionDescriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

require_once __DIR__ . '/../Checkout/CheckoutTestHelpers.php';
require_once __DIR__ . '/../GraphQL/GraphQLTestHelpers.php';

uses( RefreshDatabase::class );

/**
 * A print type with `size` and `paper` options that describes them itself.
 */
class LineOptionsPrintType extends SimpleProductType implements DescribesLineOptions
{
    /**
     * Paper labels, mutable so a test can "rename" a choice.
     *
     * @var array<string, string>
     */
    public static array $papers = [ 'lustre' => 'Lustre', 'matte' => 'Matte' ];

    public function key(): string
    {
        return 'line-options-print';
    }

    public function validateCartOptions( Product $product, array $options ): array
    {
        return array_intersect_key( $options, array_flip( [ 'size', 'paper' ] ) ) + parent::validateCartOptions( $product, $options );
    }

    public function describeLineOptions( Product $product, array $options ): array
    {
        return [
            [ 'label' => 'Size', 'value' => str_replace( 'x', ' × ', (string) ( $options['size'] ?? '' ) ) . ' in' ],
            [ 'label' => 'Paper', 'value' => self::$papers[ $options['paper'] ?? '' ] ?? '' ],
        ];
    }
}

beforeEach( function (): void {
    LineOptionsPrintType::$papers = [ 'lustre' => 'Lustre', 'matte' => 'Matte' ];
    app( ProductTypeRegistry::class )->register( 'line-options-print', LineOptionsPrintType::class );

    $this->describer = app( LineOptionDescriber::class );
} );

it( 'uses the product type\'s own rows when it implements the contract', function (): void {
    $product = Product::factory()->create( [ 'type' => 'line-options-print' ] );

    expect( $this->describer->describe( $product, [ 'size' => '16x20', 'paper' => 'lustre' ] ) )->toBe( [
        [ 'label' => 'Size', 'value' => '16 × 20 in' ],
        [ 'label' => 'Paper', 'value' => 'Lustre' ],
    ] );
} );

it( 'drops rows without a value from a type\'s description', function (): void {
    $product = Product::factory()->create( [ 'type' => 'line-options-print' ] );

    expect( $this->describer->describe( $product, [ 'size' => '8x10', 'paper' => 'unknown' ] ) )->toBe( [
        [ 'label' => 'Size', 'value' => '8 × 10 in' ],
    ] );
} );

it( 'describes other types with the variant name and headlined scalar options', function (): void {
    $product = Product::factory()->create( [ 'type' => 'variable' ] );
    $variant = ProductVariant::factory()->for( $product )->create( [ 'name' => 'Red / M' ] );

    expect( $this->describer->describe( $product, [
        'variant_id'    => $variant->id,
        'gift_message'  => '  Happy birthday ',
        'gift_wrap'     => true,
        'express'       => false,
        'engraving_cm'  => 4,
        'items'         => [ 5 => 2 ],
        '_internal'     => 'secret',
        'empty'         => '',
        'product_id'    => 9,
    ] ) )->toBe( [
        [ 'label' => '', 'value' => 'Red / M' ],
        [ 'label' => 'Gift Message', 'value' => 'Happy birthday' ],
        [ 'label' => 'Gift Wrap', 'value' => 'Yes' ],
        [ 'label' => 'Engraving Cm', 'value' => '4' ],
    ] );
} );

it( 'describes a line without a product or options as nothing', function (): void {
    expect( $this->describer->describe( null, [] ) )->toBe( [] )
        ->and( $this->describer->describe( Product::factory()->create(), [ 'variant_id' => 999 ] ) )->toBe( [] );
} );

it( 'lets the rows be filtered', function (): void {
    addFilter( 'ap.ecommerce.lineOptions.described', fn ( array $rows ): array => [ ...$rows, [ 'label' => 'Note', 'value' => 'Filtered' ] ] );

    expect( $this->describer->describe( Product::factory()->create(), [] ) )->toBe( [ [ 'label' => 'Note', 'value' => 'Filtered' ] ] );

    removeAllFilters( 'ap.ecommerce.lineOptions.described' );
} );

it( 'exposes option_lines on the cart item resource', function (): void {
    $product = checkoutProduct( [ 'USD' => 2_000 ], [ 'type' => 'line-options-print' ] );
    $carts   = app( StorefrontCartService::class );
    $cart    = $carts->create( 'USD' );
    $carts->addItem( $cart, $product->id, null, 1, [ 'size' => '16x20', 'paper' => 'lustre' ] );

    $response = $this->getJson( "/api/ecommerce/v1/carts/{$cart->token}?include=items" )->assertOk();

    expect( $response->json( 'data.items.0.option_lines' ) )->toBe( [
        [ 'label' => 'Size', 'value' => '16 × 20 in' ],
        [ 'label' => 'Paper', 'value' => 'Lustre' ],
    ] );
} );

it( 'exposes option_lines on the GraphQL CartItem type', function (): void {
    $product = checkoutProduct( [ 'USD' => 2_000 ], [ 'type' => 'line-options-print' ] );
    $carts   = app( StorefrontCartService::class );
    $cart    = $carts->create( 'USD' );
    $carts->addItem( $cart, $product->id, null, 1, [ 'size' => '8x10', 'paper' => 'matte' ] );

    gql( $this, 'query ($token: String!) { cart(token: $token) { items { option_lines { label value } } } }', [ 'token' => $cart->token ] )
        ->assertJsonPath( 'data.cart.items.0.option_lines', [
            [ 'label' => 'Size', 'value' => '8 × 10 in' ],
            [ 'label' => 'Paper', 'value' => 'Matte' ],
        ] );
} );

it( 'copies the rows into the order snapshot so later product edits don\'t change them', function (): void {
    checkoutGateway();
    checkoutZone();
    $product  = checkoutProduct( [ 'USD' => 2_000 ], [ 'type' => 'line-options-print', 'name' => 'Engine Print' ] );
    $carts    = app( StorefrontCartService::class );
    $checkout = app( CheckoutService::class );
    $cart     = $carts->create( 'USD' );
    $carts->addItem( $cart, $product->id, null, 1, [ 'size' => '16x20', 'paper' => 'lustre' ] );
    $checkout->setEmail( $cart, 'ada@example.test' );
    $checkout->setAddress( $cart, checkoutAddress() );
    $checkout->setShippingMethod( $cart, $checkout->shippingRates( $cart )->first()->id() );
    $checkout->setPaymentGateway( $cart, 'fake' );

    $order = app( OrderPlacementService::class )->place( $cart->refresh() );
    $line  = $order->items->sole();

    expect( $line->product_snapshot['option_lines'] )->toBe( [
        [ 'label' => 'Size', 'value' => '16 × 20 in' ],
        [ 'label' => 'Paper', 'value' => 'Lustre' ],
    ] );

    // The store renames the paper choice after the order was placed.
    LineOptionsPrintType::$papers['lustre'] = 'Lustre Pro';

    expect( $this->describer->forOrderItem( $line->refresh() ) )->toContain( [ 'label' => 'Paper', 'value' => 'Lustre' ] )
        ->and( $this->describer->describe( $product, [ 'size' => '16x20', 'paper' => 'lustre' ] ) )->toContain( [ 'label' => 'Paper', 'value' => 'Lustre Pro' ] );
} );

it( 'records the describer\'s rows even when the type\'s snapshot sets option_lines', function (): void {
    app( ProductTypeRegistry::class )->register( 'snapshotting-print', new class extends LineOptionsPrintType {
        public function key(): string
        {
            return 'snapshotting-print';
        }

        public function buildOrderSnapshot( CartItem $item ): array
        {
            return [ ...parent::buildOrderSnapshot( $item ), 'option_lines' => [ [ 'label' => 'Stale', 'value' => 'Row' ] ], 'name' => 'From the type' ];
        }
    } );

    checkoutGateway();
    checkoutZone();
    $product  = checkoutProduct( [ 'USD' => 2_000 ], [ 'type' => 'snapshotting-print', 'name' => 'Engine Print' ] );
    $carts    = app( StorefrontCartService::class );
    $checkout = app( CheckoutService::class );
    $cart     = $carts->create( 'USD' );
    $carts->addItem( $cart, $product->id, null, 1, [ 'size' => '8x10', 'paper' => 'matte' ] );
    $checkout->setEmail( $cart, 'ada@example.test' );
    $checkout->setAddress( $cart, checkoutAddress() );
    $checkout->setShippingMethod( $cart, $checkout->shippingRates( $cart )->first()->id() );
    $checkout->setPaymentGateway( $cart, 'fake' );

    $snapshot = app( OrderPlacementService::class )->place( $cart->refresh() )->items->sole()->product_snapshot;

    expect( $snapshot['option_lines'] )->toBe( [ [ 'label' => 'Size', 'value' => '8 × 10 in' ], [ 'label' => 'Paper', 'value' => 'Matte' ] ] )
        ->and( $snapshot['name'] )->toBe( 'From the type' );
} );

it( 'reads order item option_lines from the snapshot, falling back to the snapshot options', function (): void {
    $product = Product::factory()->create( [ 'type' => 'simple' ] );
    $order   = Order::factory()->create();
    $stored  = OrderItem::factory()->create( [ 'order_id' => $order->id, 'product_id' => $product->id, 'product_snapshot' => [ 'name' => 'Poster', 'option_lines' => [ [ 'label' => 'Frame', 'value' => 'Black oak' ] ] ] ] );
    $legacy  = OrderItem::factory()->create( [ 'order_id' => $order->id, 'product_id' => $product->id, 'product_snapshot' => [ 'name' => 'Shirt', 'variant_name' => 'Blue / L', 'options' => [ 'monogram' => 'JM' ] ] ] );

    expect( $this->describer->forOrderItem( $stored ) )->toBe( [ [ 'label' => 'Frame', 'value' => 'Black oak' ] ] )
        ->and( $this->describer->forOrderItem( $legacy ) )->toBe( [
            [ 'label' => '', 'value' => 'Blue / L' ],
            [ 'label' => 'Monogram', 'value' => 'JM' ],
        ] );
} );

it( 'describes a cart line from its variant column', function (): void {
    $product = Product::factory()->create( [ 'type' => 'variable' ] );
    $variant = ProductVariant::factory()->for( $product )->create( [ 'name' => 'Large' ] );
    $item    = CartItem::factory()->create( [ 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'options' => [ 'note' => 'Fragile' ] ] );

    expect( $this->describer->forCartItem( $item ) )->toBe( [
        [ 'label' => '', 'value' => 'Large' ],
        [ 'label' => 'Note', 'value' => 'Fragile' ],
    ] );
} );

it( 'lists each line\'s rows in the order notification templates', function (): void {
    $customer = Customer::factory()->create( [ 'first_name' => 'Ada', 'email' => 'ada@example.test' ] );
    $order    = Order::factory()->forCustomer( $customer )->create();
    OrderItem::factory()->create( [ 'order_id' => $order->id, 'product_snapshot' => [ 'name' => 'Engine Print', 'option_lines' => [ [ 'label' => 'Paper', 'value' => 'Lustre' ], [ 'label' => '', 'value' => 'Black oak' ] ] ] ] );

    $context = [ 'Order' => app( NotificationContext::class )->order( $order->refresh() ) ];

    foreach ( [ NotificationCatalog::ORDER_CONFIRMATION, NotificationCatalog::ORDER_PAID_ADMIN, NotificationCatalog::ORDER_SHIPPED, NotificationCatalog::ORDER_REFUNDED ] as $key ) {
        $variables = $context + [
            'Shipment' => [ 'carrier' => null, 'tracking_url' => null, 'tracking_number' => null ],
            'Refund'   => [ 'amount' => '$1.00', 'reason' => null ],
        ];
        $rendered = ( new EcommerceNotification( $key, 'mail', $variables ) )->render();

        expect( $rendered['body'] )->toContain( 'Engine Print' )
            ->and( $rendered['body'] )->toContain( 'Paper: Lustre' )
            ->and( $rendered['body'] )->toContain( '<small>Black oak</small>' );
    }
} );

it( 'sends the rows in the order confirmation mail', function (): void {
    Notification::fake();
    $customer = Customer::factory()->create( [ 'first_name' => 'Ada', 'email' => 'ada@example.test' ] );
    $order    = Order::factory()->forCustomer( $customer )->create();
    OrderItem::factory()->create( [ 'order_id' => $order->id, 'product_snapshot' => [ 'name' => 'Engine Print', 'option_lines' => [ [ 'label' => 'Size', 'value' => '16 × 20 in' ] ] ] ] );

    doAction( 'ap.ecommerce.order.placed', $order->refresh() );

    Notification::assertSentTo( $customer, EcommerceNotification::class, function ( EcommerceNotification $notification ) use ( $customer ): bool {
        $mail = $notification->toMail( $customer );

        return NotificationCatalog::ORDER_CONFIRMATION === $notification->templateKey
            && $mail instanceof NotificationTemplateMail
            && str_contains( $notification->render()['body'], 'Size: 16 × 20 in' );
    } );
} );
