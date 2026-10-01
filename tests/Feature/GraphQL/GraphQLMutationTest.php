<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Contracts\PaymentGateway;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\Ecommerce\Models\Coupon;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductPrice;
use ArtisanPackUI\Ecommerce\Models\Promotion;
use ArtisanPackUI\Ecommerce\Models\PromotionAction;
use ArtisanPackUI\Ecommerce\Models\WebhookDelivery;
use ArtisanPackUI\Ecommerce\Models\WebhookSubscription;
use ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry;
use ArtisanPackUI\Ecommerce\ValueObjects\RefundResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Money\Money;

require_once __DIR__ . '/GraphQLTestHelpers.php';

uses( RefreshDatabase::class );

beforeEach( function (): void {
    Gate::define( 'ecommerce.admin', fn ( $user ): bool => 1 === (int) $user->getAuthIdentifier() );
} );

const ADD_TO_CART = 'mutation ($input: AddToCartInput!) {
    addToCart(input: $input) {
        clientMutationId
        cart { subtotal { amount } items { quantity unit_price { amount } product { name } } }
        item { quantity }
        errors { field code message }
    }
}';

it( 'creates a cart and adds a server-priced item', function (): void {
    $product = Product::factory()->create( [ 'name' => 'Mug' ] );
    ProductPrice::factory()->forPriceable( $product )->create( [ 'price_amount' => 1_200 ] );

    $token = gql( $this, 'mutation { createCart(input: { currency: "USD" }) { cart { token } errors { code } } }' )
        ->assertJsonPath( 'data.createCart.errors', [] )
        ->json( 'data.createCart.cart.token' );

    gql( $this, ADD_TO_CART, [ 'input' => [ 'cart_token' => $token, 'product_id' => $product->id, 'quantity' => 2, 'clientMutationId' => 'm1' ] ] )
        ->assertJsonMissingPath( 'errors' )
        ->assertJsonPath( 'data.addToCart.clientMutationId', 'm1' )
        ->assertJsonPath( 'data.addToCart.errors', [] )
        ->assertJsonPath( 'data.addToCart.item.quantity', 2 )
        ->assertJsonPath( 'data.addToCart.cart.subtotal.amount', 2_400 )
        ->assertJsonPath( 'data.addToCart.cart.items.0.product.name', 'Mug' );
} );

it( 'returns expected cart failures as user errors', function (): void {
    $cart = Cart::factory()->create();

    gql( $this, ADD_TO_CART, [ 'input' => [ 'cart_token' => $cart->token, 'product_id' => 999, 'quantity' => 1 ] ] )
        ->assertJsonMissingPath( 'errors' )
        ->assertJsonPath( 'data.addToCart.cart', null )
        ->assertJsonPath( 'data.addToCart.errors.0.code', 'product-unavailable' );

    gql( $this, ADD_TO_CART, [ 'input' => [ 'cart_token' => $cart->token, 'product_id' => 1, 'quantity' => 0 ] ] )
        ->assertJsonPath( 'data.addToCart.errors.0.field', 'quantity' )
        ->assertJsonPath( 'data.addToCart.errors.0.code', 'invalid' );

    gql( $this, ADD_TO_CART, [ 'input' => [ 'cart_token' => 'missing', 'product_id' => 1, 'quantity' => 1 ] ] )
        ->assertJsonPath( 'errors.0.extensions.code', 'NOT_FOUND' );
} );

it( 'updates, removes, and couponizes cart lines', function (): void {
    $product = Product::factory()->create();
    ProductPrice::factory()->forPriceable( $product )->create( [ 'price_amount' => 5_000 ] );
    $promotion = Promotion::factory()->coupon()->create();
    PromotionAction::factory()->create( [ 'promotion_id' => $promotion->id, 'type' => 'percent-off-cart', 'config' => [ 'percent' => 10 ] ] );
    Coupon::factory()->create( [ 'promotion_id' => $promotion->id, 'code' => 'TEN' ] );

    $cart = Cart::factory()->create( [ 'currency' => 'USD' ] );
    gql( $this, ADD_TO_CART, [ 'input' => [ 'cart_token' => $cart->token, 'product_id' => $product->id, 'quantity' => 1 ] ] );
    $item = CartItem::query()->sole();

    gql( $this, 'mutation ($input: UpdateCartItemInput!) { updateCartItem(input: $input) { cart { subtotal { amount } } item { quantity } errors { code } } }', [
        'input' => [ 'cart_token' => $cart->token, 'item_id' => $item->id, 'quantity' => 2 ],
    ] )->assertJsonPath( 'data.updateCartItem.item.quantity', 2 )->assertJsonPath( 'data.updateCartItem.cart.subtotal.amount', 10_000 );

    gql( $this, 'mutation ($input: ApplyCouponInput!) { applyCoupon(input: $input) { cart { discount { amount } total { amount } } errors { code } } }', [
        'input' => [ 'cart_token' => $cart->token, 'code' => 'ten' ],
    ] )->assertJsonPath( 'data.applyCoupon.cart.discount.amount', 1_000 )->assertJsonPath( 'data.applyCoupon.cart.total.amount', 9_000 );

    gql( $this, 'mutation ($input: ApplyCouponInput!) { applyCoupon(input: $input) { cart { id } errors { code } } }', [
        'input' => [ 'cart_token' => $cart->token, 'code' => 'bogus' ],
    ] )->assertJsonPath( 'data.applyCoupon.errors.0.code', 'coupon-invalid' );

    gql( $this, 'mutation ($input: RemoveCouponInput!) { removeCoupon(input: $input) { cart { discount { amount } } errors { code } } }', [
        'input' => [ 'cart_token' => $cart->token, 'code' => 'TEN' ],
    ] )->assertJsonPath( 'data.removeCoupon.cart.discount.amount', 0 );

    gql( $this, 'mutation ($input: RemoveCartItemInput!) { removeCartItem(input: $input) { cart { items { id } } errors { code } } }', [
        'input' => [ 'cart_token' => $cart->token, 'item_id' => $item->id ],
    ] )->assertJsonCount( 0, 'data.removeCartItem.cart.items' );
} );

it( 'issues refunds for admins only', function (): void {
    $gateway = Mockery::mock( PaymentGateway::class );
    $gateway->shouldReceive( 'key' )->andReturn( 'fake' );
    $gateway->shouldReceive( 'supportsRefunds' )->andReturn( true );
    $gateway->shouldReceive( 'supportsPartialRefunds' )->andReturn( true );
    $gateway->shouldReceive( 'refund' )->andReturnUsing( fn ( Order $order, Money $amount ): RefundResult => RefundResult::success( $amount, 're_gql' ) );
    app( PaymentGatewayRegistry::class )->register( 'fake', $gateway );

    $order = Order::factory()->create( [ 'payment_status' => 'paid', 'payment_gateway_key' => 'fake', 'total_amount' => 2_000 ] );
    $item  = OrderItem::factory()->create( [ 'order_id' => $order->id, 'quantity' => 2, 'unit_price_amount' => 1_000, 'total_amount' => 2_000 ] );

    $mutation = 'mutation ($input: IssueRefundInput!) { issueRefund(input: $input) { refund { amount { amount } gateway_reference items { quantity } } order { total_refunded { amount } } errors { code message } } }';
    $input    = [ 'input' => [ 'order_id' => $order->id, 'lines' => [ [ 'order_item_id' => $item->id, 'quantity' => 1, 'amount' => 1_000 ] ] ] ];

    $this->actingAs( ecommerceShopperUser(), 'sanctum' );
    gql( $this, $mutation, $input )->assertJsonPath( 'errors.0.extensions.code', 'FORBIDDEN' );

    $this->actingAs( new Illuminate\Auth\GenericUser( [ 'id' => 1 ] ), 'sanctum' );
    gql( $this, $mutation, $input )
        ->assertJsonMissingPath( 'errors' )
        ->assertJsonPath( 'data.issueRefund.refund.gateway_reference', 're_gql' )
        ->assertJsonPath( 'data.issueRefund.refund.items.0.quantity', 1 )
        ->assertJsonPath( 'data.issueRefund.order.total_refunded.amount', 1_000 );

    $input['input']['lines'][0]['amount'] = 50_000;
    gql( $this, $mutation, $input )->assertJsonPath( 'data.issueRefund.errors.0.code', 'refund-not-allowed' );
} );

it( 'manages webhook subscriptions', function (): void {
    Queue::fake();
    $this->actingAs( new Illuminate\Auth\GenericUser( [ 'id' => 1 ] ), 'sanctum' );

    $created = gql( $this, 'mutation ($input: CreateWebhookSubscriptionInput!) { createWebhookSubscription(input: $input) { webhook_subscription { id secret events } errors { field code } } }', [
        'input' => [ 'name' => 'Zapier', 'url' => 'https://hooks.zapier.test/x', 'events' => [ 'order.refunded' ] ],
    ] )->assertJsonPath( 'data.createWebhookSubscription.errors', [] );

    $id = $created->json( 'data.createWebhookSubscription.webhook_subscription.id' );

    expect( $created->json( 'data.createWebhookSubscription.webhook_subscription.secret' ) )->toStartWith( 'whsec_' );

    gql( $this, 'mutation ($input: CreateWebhookSubscriptionInput!) { createWebhookSubscription(input: $input) { errors { field } } }', [
        'input' => [ 'name' => 'Bad', 'url' => 'http://insecure.test', 'events' => [ 'order.refunded' ] ],
    ] )->assertJsonPath( 'data.createWebhookSubscription.errors.0.field', 'url' );

    gql( $this, 'mutation ($input: UpdateWebhookSubscriptionInput!) { updateWebhookSubscription(input: $input) { webhook_subscription { is_active secret } errors { code } } }', [
        'input' => [ 'id' => $id, 'is_active' => false ],
    ] )->assertJsonPath( 'data.updateWebhookSubscription.webhook_subscription.is_active', false )
        ->assertJsonPath( 'data.updateWebhookSubscription.webhook_subscription.secret', null );

    $delivery = WebhookDelivery::factory()->create( [ 'subscription_id' => $id ] );
    $replay   = 'mutation ($input: ReplayWebhookDeliveryInput!) { replayWebhookDelivery(input: $input) { delivery { event } errors { code } } }';

    gql( $this, $replay, [ 'input' => [ 'subscription_id' => $id, 'delivery_id' => $delivery->id ] ] )
        ->assertJsonPath( 'data.replayWebhookDelivery.delivery', null )
        ->assertJsonPath( 'data.replayWebhookDelivery.errors.0.code', 'webhook-subscription-inactive' );

    WebhookSubscription::query()->whereKey( $id )->update( [ 'is_active' => true ] );

    gql( $this, $replay, [ 'input' => [ 'subscription_id' => $id, 'delivery_id' => $delivery->id ] ] )
        ->assertJsonPath( 'data.replayWebhookDelivery.delivery.event', $delivery->event );

    gql( $this, 'mutation ($input: DeleteWebhookSubscriptionInput!) { deleteWebhookSubscription(input: $input) { deleted_id } }', [
        'input' => [ 'id' => $id ],
    ] )->assertJsonPath( 'data.deleteWebhookSubscription.deleted_id', (string) $id );

    expect( WebhookSubscription::query()->count() )->toBe( 0 );
} );

it( 'honours an optional Idempotency-Key', function (): void {
    $key = [ 'Idempotency-Key' => (string) Str::uuid() ];

    $first  = gql( $this, 'mutation { createCart(input: {}) { cart { token } } }', [], $key )->json( 'data.createCart.cart.token' );
    $second = gql( $this, 'mutation { createCart(input: {}) { cart { token } } }', [], $key )->assertHeader( 'Idempotent-Replay', 'true' );

    expect( $second->json( 'data.createCart.cart.token' ) )->toBe( $first )
        ->and( Cart::query()->count() )->toBe( 1 );

    gql( $this, 'mutation { createCart(input: {}) { cart { token } } }' );

    expect( Cart::query()->count() )->toBe( 2 );
} );

it( 'cancels an order and adds a note through the order mutations', function (): void {
    $order  = Order::factory()->create( [ 'payment_status' => 'paid', 'total_amount' => 1_000 ] );
    $cancel = 'mutation ($input: CancelOrderInput!) { cancelOrder(input: $input) { order { system_status } errors { code message } } }';
    $note   = 'mutation ($input: AddOrderNoteInput!) { addOrderNote(input: $input) { note { body is_customer_visible } errors { field code } } }';

    $this->actingAs( ecommerceShopperUser(), 'sanctum' );
    gql( $this, $cancel, [ 'input' => [ 'order_id' => $order->id, 'reason' => 'x' ] ] )->assertJsonPath( 'errors.0.extensions.code', 'FORBIDDEN' );

    $this->actingAs( new Illuminate\Auth\GenericUser( [ 'id' => 1 ] ), 'sanctum' );

    gql( $this, $note, [ 'input' => [ 'order_id' => $order->id, 'body' => 'Fragile', 'is_customer_visible' => true ] ] )
        ->assertJsonMissingPath( 'errors' )
        ->assertJsonPath( 'data.addOrderNote.note.body', 'Fragile' )
        ->assertJsonPath( 'data.addOrderNote.note.is_customer_visible', true );

    gql( $this, $note, [ 'input' => [ 'order_id' => $order->id, 'body' => str_repeat( 'a', 5_001 ) ] ] )
        ->assertJsonPath( 'data.addOrderNote.errors.0.field', 'body' );

    // Hosts whose GraphQL route skips TrimStrings still get a UserError for a
    // whitespace-only body, never an internal error.
    $this->withoutMiddleware( [ Illuminate\Foundation\Http\Middleware\TrimStrings::class, Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class ] );

    gql( $this, $note, [ 'input' => [ 'order_id' => $order->id, 'body' => " \t " ] ] )
        ->assertJsonMissingPath( 'errors' )
        ->assertJsonPath( 'data.addOrderNote.errors.0.field', 'body' );

    $this->withMiddleware();

    gql( $this, $cancel, [ 'input' => [ 'order_id' => $order->id, 'reason' => 'Customer asked' ] ] )
        ->assertJsonMissingPath( 'errors' )
        ->assertJsonPath( 'data.cancelOrder.order.system_status', 'cancelled' );

    gql( $this, $cancel, [ 'input' => [ 'order_id' => $order->id, 'reason' => 'Again' ] ] )
        ->assertJsonPath( 'data.cancelOrder.errors.0.code', 'order-not-cancellable' );
} );
