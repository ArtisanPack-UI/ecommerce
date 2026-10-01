<?php

/**
 * Mutations.
 *
 * Root `Mutation` fields of the ecommerce GraphQL schema (engine spec
 * §10.3). Every mutation mirrors a REST endpoint and calls the same service
 * with the same validation rules, auth, and rate-limit policy:
 *
 * | Mutation                    | REST                                                   |
 * |-----------------------------|--------------------------------------------------------|
 * | `createCart`                | `POST carts`                                           |
 * | `addToCart`                 | `POST carts/{token}/items`                             |
 * | `updateCartItem`            | `PATCH carts/{token}/items/{item}`                     |
 * | `removeCartItem`            | `DELETE carts/{token}/items/{item}`                    |
 * | `applyCoupon`               | `POST carts/{token}/coupons`                           |
 * | `removeCoupon`              | `DELETE carts/{token}/coupons/{code}`                  |
 * | `issueRefund`               | `POST orders/{order}/refunds`                          |
 * | `cancelOrder`               | `POST orders/{order}/cancel`                           |
 * | `addOrderNote`              | `POST orders/{order}/notes`                            |
 * | `createWebhookSubscription` | `POST admin/webhook-subscriptions`                     |
 * | `updateWebhookSubscription` | `PATCH admin/webhook-subscriptions/{sub}`              |
 * | `deleteWebhookSubscription` | `DELETE admin/webhook-subscriptions/{sub}`             |
 * | `replayWebhookDelivery`     | `POST admin/webhook-subscriptions/{sub}/replay/{id}`   |
 * | `updateNotificationTemplate`  | `PATCH admin/notification-templates/{template}`      |
 * | `previewNotificationTemplate` | `POST admin/notification-templates/{template}/preview` |
 *
 * Inputs use the REST payload's snake_case keys. Expected failures
 * (validation, unknown product, bad coupon, refused refund) come back in
 * the payload's `errors: [UserError!]!`; auth and rate-limit failures are
 * top-level GraphQL errors. An `Idempotency-Key` header, when sent,
 * de-duplicates the whole request (engine spec §11.2).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\GraphQL\Fields;

use ArtisanPackUI\Ecommerce\Exceptions\CartOperationException;
use ArtisanPackUI\Ecommerce\Exceptions\NotificationTemplateException;
use ArtisanPackUI\Ecommerce\Exceptions\OrderNotCancellableException;
use ArtisanPackUI\Ecommerce\Exceptions\RefundNotAllowedException;
use ArtisanPackUI\Ecommerce\GraphQL\GraphQLError;
use ArtisanPackUI\Ecommerce\GraphQL\Support\Resolvers;
use ArtisanPackUI\Ecommerce\Http\Middleware\IdempotencyMiddleware;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\AddCartItemRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\AddOrderNoteRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\ApplyCouponRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\CancelOrderRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\CreateCartRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\IssueRefundRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\PreviewNotificationTemplateRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\UpdateCartItemRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\UpdateNotificationTemplateRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\WebhookSubscriptionRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\WebhookSubscriptionResource;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\Ecommerce\Models\NotificationTemplate;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\WebhookDelivery;
use ArtisanPackUI\Ecommerce\Models\WebhookSubscription;
use ArtisanPackUI\Ecommerce\Services\NotificationTemplateService;
use ArtisanPackUI\Ecommerce\Services\OrderCancellationService;
use ArtisanPackUI\Ecommerce\Services\OrderNoteService;
use ArtisanPackUI\Ecommerce\Services\RefundService;
use ArtisanPackUI\Ecommerce\Services\StorefrontCartService;
use ArtisanPackUI\Ecommerce\Services\WebhookSubscriptionService;
use Closure;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class Mutations
{
    /**
     * @since 1.0.0
     *
     * @param  Resolvers  $r  Shared resolver plumbing.
     */
    public function __construct( protected Resolvers $r )
    {
    }

    /**
     * Input and payload types.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, mixed>>
     */
    public function types(): array
    {
        $id     = [ 'clientMutationId' => 'String' ];
        $errors = [ 'errors' => '[UserError!]!', 'clientMutationId' => 'String' ];
        $input  = static fn ( array $fields ): array => [ 'kind' => 'input', 'fields' => $fields + $id ];
        $output = static fn ( array $fields ): array => [ 'fields' => $fields + $errors ];

        return [
            'CreateCartInput'       => $input( [ 'currency' => 'String', 'email' => 'String' ] ),
            'CreateCartPayload'     => $output( [ 'cart' => 'Cart' ] ),
            'AddToCartInput'        => $input( [
                'cart_token'         => 'String!',
                'product_id'         => 'ID!',
                'product_variant_id' => 'ID',
                'quantity'           => 'Int!',
                'options'            => 'JSON',
            ] ),
            'AddToCartPayload'      => $output( [ 'cart' => 'Cart', 'item' => 'CartItem' ] ),
            'UpdateCartItemInput'   => $input( [ 'cart_token' => 'String!', 'item_id' => 'ID!', 'quantity' => 'Int!' ] ),
            'UpdateCartItemPayload' => $output( [ 'cart' => 'Cart', 'item' => 'CartItem' ] ),
            'RemoveCartItemInput'   => $input( [ 'cart_token' => 'String!', 'item_id' => 'ID!' ] ),
            'RemoveCartItemPayload' => $output( [ 'cart' => 'Cart' ] ),
            'ApplyCouponInput'      => $input( [ 'cart_token' => 'String!', 'code' => 'String!' ] ),
            'ApplyCouponPayload'    => $output( [ 'cart' => 'Cart' ] ),
            'RemoveCouponInput'     => $input( [ 'cart_token' => 'String!', 'code' => 'String!' ] ),
            'RemoveCouponPayload'   => $output( [ 'cart' => 'Cart' ] ),
            'RefundLineInput'       => [
                'kind'   => 'input',
                'fields' => [ 'order_item_id' => 'ID!', 'quantity' => 'Int!', 'amount' => 'BigInt!', 'restock' => 'Boolean' ],
            ],
            'IssueRefundInput'      => $input( [ 'order_id' => 'ID!', 'lines' => '[RefundLineInput!]!', 'reason' => 'String' ] ),
            'IssueRefundPayload'    => $output( [ 'order' => 'Order', 'refund' => 'Refund' ] ),
            'CancelOrderInput'      => $input( [ 'order_id' => 'ID!', 'reason' => 'String!' ] ),
            'CancelOrderPayload'    => $output( [ 'order' => 'Order' ] ),
            'AddOrderNoteInput'     => $input( [ 'order_id' => 'ID!', 'body' => 'String!', 'is_customer_visible' => 'Boolean' ] ),
            'AddOrderNotePayload'   => $output( [ 'note' => 'OrderNote' ] ),

            'CreateWebhookSubscriptionInput'   => $input( [
                'name'      => 'String!',
                'url'       => 'String!',
                'events'    => '[String!]!',
                'secret'    => 'String',
                'is_active' => 'Boolean',
            ] ),
            'CreateWebhookSubscriptionPayload' => $output( [ 'webhook_subscription' => 'WebhookSubscription' ] ),
            'UpdateWebhookSubscriptionInput'   => $input( [
                'id'        => 'ID!',
                'name'      => 'String',
                'url'       => 'String',
                'events'    => '[String!]',
                'secret'    => 'String',
                'is_active' => 'Boolean',
            ] ),
            'UpdateWebhookSubscriptionPayload' => $output( [ 'webhook_subscription' => 'WebhookSubscription' ] ),
            'DeleteWebhookSubscriptionInput'   => $input( [ 'id' => 'ID!' ] ),
            'DeleteWebhookSubscriptionPayload' => $output( [ 'deleted_id' => 'ID' ] ),
            'ReplayWebhookDeliveryInput'       => $input( [ 'subscription_id' => 'ID!', 'delivery_id' => 'ID!' ] ),
            'ReplayWebhookDeliveryPayload'     => $output( [ 'delivery' => 'WebhookDelivery' ] ),

            'RenderedNotification'               => [
                'description' => 'A notification template rendered through the delivery-time sandbox.',
                'fields'      => [ 'subject' => 'String', 'body' => 'String!' ],
            ],
            'UpdateNotificationTemplateInput'    => $input( [
                'id'           => 'ID!',
                'subject'      => 'String',
                'body'         => 'String',
                'is_active'    => 'Boolean',
                'preview_data' => 'JSON',
            ] ),
            'UpdateNotificationTemplatePayload'  => $output( [ 'notification_template' => 'NotificationTemplate' ] ),
            'PreviewNotificationTemplateInput'   => $input( [
                'id'           => 'ID!',
                'subject'      => 'String',
                'body'         => 'String',
                'preview_data' => 'JSON',
            ] ),
            'PreviewNotificationTemplatePayload' => $output( [ 'rendered' => 'RenderedNotification' ] ),
        ];
    }

    /**
     * Root mutation fields.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, mixed>>
     */
    public function fields(): array
    {
        return [
            'createCart' => $this->mutation( 'CreateCart', function ( array $input, ResolveInfo $info ): array {
                $this->r->throttle( 'ecommerce.cart.mutate' );
                $this->validate( $input, CreateCartRequest::baseRules() );

                $cart = $this->carts()->create( $input['currency'] ?? null, $input['email'] ?? null );

                return [ 'cart' => $this->cart( $cart, $info ) ];
            } ),

            'addToCart' => $this->mutation( 'AddToCart', function ( array $input, ResolveInfo $info ): array {
                $cart = $this->findCart( $input );
                $this->validate( $input, AddCartItemRequest::baseRules() );

                $item = $this->carts()->addItem(
                    $cart,
                    (int) $input['product_id'],
                    isset( $input['product_variant_id'] ) ? (int) $input['product_variant_id'] : null,
                    (int) $input['quantity'],
                    (array) ( $input['options'] ?? [] ),
                );

                return [ 'cart' => $this->cart( $cart, $info ), 'item' => $this->r->present( $item->fresh(), 'CartItem', $this->r->selection( $info, 'item' ) ) ];
            } ),

            'updateCartItem' => $this->mutation( 'UpdateCartItem', function ( array $input, ResolveInfo $info ): array {
                $cart = $this->findCart( $input );
                $this->validate( $input, UpdateCartItemRequest::baseRules() );

                $item = $this->carts()->updateItem( $cart, $this->findItem( $cart, $input ), (int) $input['quantity'] );

                return [ 'cart' => $this->cart( $cart, $info ), 'item' => $this->r->present( $item, 'CartItem', $this->r->selection( $info, 'item' ) ) ];
            } ),

            'removeCartItem' => $this->mutation( 'RemoveCartItem', function ( array $input, ResolveInfo $info ): array {
                $cart = $this->findCart( $input );

                $this->carts()->removeItem( $cart, $this->findItem( $cart, $input ) );

                return [ 'cart' => $this->cart( $cart, $info ) ];
            } ),

            'applyCoupon' => $this->mutation( 'ApplyCoupon', function ( array $input, ResolveInfo $info ): array {
                $cart = $this->findCart( $input, 'ecommerce.coupon.attempt' );
                $this->validate( $input, ApplyCouponRequest::baseRules() );

                $this->carts()->applyCoupon( $cart, (string) $input['code'] );

                return [ 'cart' => $this->cart( $cart, $info ) ];
            } ),

            'removeCoupon' => $this->mutation( 'RemoveCoupon', function ( array $input, ResolveInfo $info ): array {
                $cart = $this->findCart( $input );

                $this->carts()->removeCoupon( $cart, (string) $input['code'] );

                return [ 'cart' => $this->cart( $cart, $info ) ];
            } ),

            'issueRefund' => $this->mutation( 'IssueRefund', function ( array $input, ResolveInfo $info ): array {
                $order = Order::query()->find( $input['order_id'] );
                $user  = $this->r->authorize( 'order', 'refund', $order );
                $this->r->throttle( 'ecommerce.admin.mutate' );

                if ( null === $order ) {
                    throw GraphQLError::notFound();
                }

                $this->validate( $input, IssueRefundRequest::baseRules() );

                $actor = $user->getAuthIdentifier();

                try {
                    $refund = app( RefundService::class )->issue(
                        $order,
                        array_map( static fn ( array $line ): array => [
                            'order_item_id' => (int) $line['order_item_id'],
                            'quantity'      => (int) $line['quantity'],
                            'amount'        => (int) $line['amount'],
                            'restock'       => (bool) ( $line['restock'] ?? false ),
                        ], (array) $input['lines'] ),
                        is_numeric( $actor ) ? (int) $actor : null,
                        $input['reason'] ?? null,
                    );
                } catch ( InvalidArgumentException $exception ) {
                    throw new RefundNotAllowedException( $exception->getMessage(), [], 0, $exception );
                }

                return [
                    'order'  => $this->r->present( $order->fresh(), 'Order', $this->r->selection( $info, 'order' ), true ),
                    'refund' => $this->r->present( $refund, 'Refund', $this->r->selection( $info, 'refund' ), true ),
                ];
            } ),

            'cancelOrder' => $this->mutation( 'CancelOrder', function ( array $input, ResolveInfo $info ): array {
                $order = Order::query()->find( $input['order_id'] );
                $user  = $this->r->authorize( 'order', 'cancel', $order );
                $this->r->throttle( 'ecommerce.admin.mutate' );

                if ( null === $order ) {
                    throw GraphQLError::notFound();
                }

                $this->validate( $input, CancelOrderRequest::baseRules() );

                $actor = $user->getAuthIdentifier();

                try {
                    $summary = app( OrderCancellationService::class )->cancel( $order, (string) $input['reason'], is_numeric( $actor ) ? (int) $actor : null );
                } catch ( InvalidArgumentException $exception ) {
                    throw new OrderNotCancellableException( $exception->getMessage(), [], 0, $exception );
                }

                return [ 'order' => $this->r->present( $summary->order, 'Order', $this->r->selection( $info, 'order' ), true ) ];
            } ),

            'addOrderNote' => $this->mutation( 'AddOrderNote', function ( array $input, ResolveInfo $info ): array {
                $order = Order::query()->find( $input['order_id'] );
                $user  = $this->r->authorize( 'order', 'update', $order );
                $this->r->throttle( 'ecommerce.admin.mutate' );

                if ( null === $order ) {
                    throw GraphQLError::notFound();
                }

                $this->validate( $input, AddOrderNoteRequest::baseRules() );

                $actor = $user->getAuthIdentifier();
                $note  = app( OrderNoteService::class )->add(
                    $order,
                    (string) $input['body'],
                    is_numeric( $actor ) ? (int) $actor : null,
                    (bool) ( $input['is_customer_visible'] ?? false ),
                );

                return [ 'note' => $this->r->present( $note, 'OrderNote', $this->r->selection( $info, 'note' ), true ) ];
            } ),

            'createWebhookSubscription' => $this->mutation( 'CreateWebhookSubscription', function ( array $input ): array {
                $this->admin( 'create' );

                // The secret is shown once: don't store this response for
                // replay at all — aliases make key-based redaction unreliable.
                request()->attributes->set( IdempotencyMiddleware::REDACT_ATTRIBUTE, [ IdempotencyMiddleware::REDACT_ALL ] );

                $this->validate( $input, $this->required( WebhookSubscriptionRequest::baseRules(), [ 'name', 'url', 'events' ] ) );

                $subscription = app( WebhookSubscriptionService::class )->create( $this->only( $input, [ 'name', 'url', 'events', 'secret', 'is_active' ] ) );

                if ( null === $subscription ) {
                    return [ 'errors' => [ [ 'field' => null, 'code' => 'webhook-subscription-rejected', 'message' => __( 'The subscription was rejected by a filter.' ) ] ] ];
                }

                return [ 'webhook_subscription' => ( new WebhookSubscriptionResource( $subscription ) )->withSecret()->resolve( $this->r->renderRequest( true ) ) ];
            } ),

            'updateWebhookSubscription' => $this->mutation( 'UpdateWebhookSubscription', function ( array $input, ResolveInfo $info ): array {
                $subscription = $this->admin( 'update', $input['id'] );
                $this->validate( $input, $this->sometimes( WebhookSubscriptionRequest::baseRules() ) );

                app( WebhookSubscriptionService::class )->update( $subscription, $this->only( $input, [ 'name', 'url', 'events', 'secret', 'is_active' ] ) );

                return [ 'webhook_subscription' => $this->r->present( $subscription, 'WebhookSubscription', $this->r->selection( $info, 'webhook_subscription' ), true ) ];
            } ),

            'deleteWebhookSubscription' => $this->mutation( 'DeleteWebhookSubscription', function ( array $input ): array {
                $subscription = $this->admin( 'delete', $input['id'] );
                $subscription->delete();

                return [ 'deleted_id' => $subscription->id ];
            } ),

            'replayWebhookDelivery' => $this->mutation( 'ReplayWebhookDelivery', function ( array $input, ResolveInfo $info ): array {
                $subscription = $this->admin( 'update', $input['subscription_id'] );
                $delivery     = WebhookDelivery::query()->where( 'subscription_id', $subscription->id )->find( $input['delivery_id'] ) ?? throw GraphQLError::notFound();

                $replayed = app( WebhookSubscriptionService::class )->replay( $delivery );

                if ( null === $replayed ) {
                    return [ 'errors' => [ [ 'field' => 'subscription_id', 'code' => 'webhook-subscription-inactive', 'message' => __( 'Re-enable the subscription before replaying its deliveries.' ) ] ] ];
                }

                return [ 'delivery' => $this->r->present( $replayed, 'WebhookDelivery', $this->r->selection( $info, 'delivery' ), true ) ];
            } ),

            'updateNotificationTemplate' => $this->mutation( 'UpdateNotificationTemplate', function ( array $input, ResolveInfo $info ): array {
                $template = $this->notificationTemplate( 'update', $input['id'] );
                $this->validate( $input, UpdateNotificationTemplateRequest::baseRules() );

                app( NotificationTemplateService::class )->update( $template, $this->only( $input, [ 'subject', 'body', 'is_active', 'preview_data' ] ) );

                return [ 'notification_template' => $this->r->present( $template, 'NotificationTemplate', $this->r->selection( $info, 'notification_template' ), true ) ];
            } ),

            'previewNotificationTemplate' => $this->mutation( 'PreviewNotificationTemplate', function ( array $input ): array {
                $template = $this->notificationTemplate( 'update', $input['id'] );
                $this->validate( $input, PreviewNotificationTemplateRequest::baseRules() );

                return [
                    'rendered' => app( NotificationTemplateService::class )->preview(
                        $template,
                        $input['subject'] ?? null,
                        $input['body'] ?? null,
                        isset( $input['preview_data'] ) ? (array) $input['preview_data'] : null,
                    ),
                ];
            } ),
        ];
    }

    /**
     * Wraps a mutation resolver: takes the single `input` argument, maps
     * expected failures to `UserError`s, and echoes `clientMutationId`.
     *
     * @since 1.0.0
     *
     * @param  string                                                         $name     Type-name stem (`AddToCart`).
     * @param  Closure(array<string, mixed>, ResolveInfo): array<string, mixed>  $resolve  Resolver.
     *
     * @return array<string, mixed>
     */
    protected function mutation( string $name, Closure $resolve ): array
    {
        return [
            'type'    => $name . 'Payload!',
            'args'    => [ 'input' => $name . 'Input!' ],
            'resolve' => function ( $root, array $args, $context, ResolveInfo $info ) use ( $resolve ): array {
                $input = (array) $args['input'];

                try {
                    $payload = $resolve( $input, $info );
                } catch ( ValidationException $exception ) {
                    $payload = [ 'errors' => $this->validationErrors( $exception ) ];
                } catch ( CartOperationException $exception ) {
                    $payload = [ 'errors' => [ [ 'field' => $exception->field, 'code' => $exception->errorCode, 'message' => $exception->getMessage() ] ] ];
                } catch ( RefundNotAllowedException $exception ) {
                    $payload = [ 'errors' => [ [ 'field' => null, 'code' => 'refund-not-allowed', 'message' => $exception->getMessage() ] ] ];
                } catch ( OrderNotCancellableException $exception ) {
                    $payload = [ 'errors' => [ [ 'field' => null, 'code' => 'order-not-cancellable', 'message' => $exception->getMessage() ] ] ];
                } catch ( NotificationTemplateException $exception ) {
                    $payload = [ 'errors' => $exception->errors ];
                }

                return $payload + [ 'errors' => [], 'clientMutationId' => $input['clientMutationId'] ?? null ];
            },
        ];
    }

    /**
     * Validates input with the REST request's rules.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>              $input  Input.
     * @param  array<string, array<int, mixed>>  $rules  Rules.
     *
     * @throws ValidationException When validation fails.
     *
     * @return void
     */
    protected function validate( array $input, array $rules ): void
    {
        Validator::make( $input, $rules )->validate();
    }

    /**
     * @since 1.0.0
     *
     * @param  ValidationException  $exception  Failure.
     *
     * @return array<int, array{field: string, code: string, message: string}>
     */
    protected function validationErrors( ValidationException $exception ): array
    {
        $errors = [];

        foreach ( $exception->errors() as $field => $messages ) {
            foreach ( $messages as $message ) {
                $errors[] = [ 'field' => (string) $field, 'code' => 'invalid', 'message' => $message ];
            }
        }

        return $errors;
    }

    /**
     * The cart for `cart_token`, after the cart rate policy.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $input   Input.
     * @param  string                $policy  Rate policy.
     *
     * @throws GraphQLError When the cart does not exist.
     *
     * @return Cart
     */
    protected function findCart( array $input, string $policy = 'ecommerce.cart.mutate' ): Cart
    {
        $this->r->throttle( $policy, [ 'cart_token' => (string) $input['cart_token'] ] );

        return Cart::query()->where( 'token', (string) $input['cart_token'] )->first() ?? throw GraphQLError::notFound();
    }

    /**
     * A line of `$cart` by `item_id`.
     *
     * @since 1.0.0
     *
     * @param  Cart                  $cart   Cart.
     * @param  array<string, mixed>  $input  Input.
     *
     * @throws GraphQLError When the line is not in the cart.
     *
     * @return CartItem
     */
    protected function findItem( Cart $cart, array $input ): CartItem
    {
        return $cart->items()->whereKey( $input['item_id'] )->first() ?? throw GraphQLError::notFound();
    }

    /**
     * Renders the refreshed cart for the payload's `cart` selection.
     *
     * @since 1.0.0
     *
     * @param  Cart         $cart  Cart.
     * @param  ResolveInfo  $info  Resolve info.
     *
     * @return array<string, mixed>|null
     */
    protected function cart( Cart $cart, ResolveInfo $info ): ?array
    {
        return $this->r->present( $cart->fresh(), 'Cart', $this->r->selection( $info, 'cart' ) );
    }

    /**
     * Authorizes a webhook-subscription admin action (and finds the row).
     *
     * @since 1.0.0
     *
     * @param  string           $action  Action name.
     * @param  int|string|null  $id      Subscription id, when acting on one.
     *
     * @throws GraphQLError When not allowed or not found.
     *
     * @return WebhookSubscription
     */
    protected function admin( string $action, int|string|null $id = null ): WebhookSubscription
    {
        $this->r->authorize( 'webhookSubscription', $action );
        $this->r->throttle( 'ecommerce.admin.mutate' );

        if ( null === $id ) {
            return new WebhookSubscription();
        }

        return WebhookSubscription::query()->find( $id ) ?? throw GraphQLError::notFound();
    }

    /**
     * Authorizes a notification-template admin action and finds the row.
     *
     * @since 1.0.0
     *
     * @param  string      $action  Action name.
     * @param  int|string  $id      Template id.
     *
     * @throws GraphQLError When not allowed or not found.
     *
     * @return NotificationTemplate
     */
    protected function notificationTemplate( string $action, int|string $id ): NotificationTemplate
    {
        $this->r->authorize( 'notificationTemplate', $action );
        $this->r->throttle( 'ecommerce.admin.mutate' );

        return NotificationTemplate::query()->find( $id ) ?? throw GraphQLError::notFound();
    }

    /**
     * Only the listed keys that are present in `$input`.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $input  Input.
     * @param  array<int, string>    $keys   Keys.
     *
     * @return array<string, mixed>
     */
    protected function only( array $input, array $keys ): array
    {
        return array_intersect_key( $input, array_flip( $keys ) );
    }

    /**
     * Prefixes the listed keys' rules with `required`.
     *
     * @since 1.0.0
     *
     * @param  array<string, array<int, mixed>>  $rules  Rules.
     * @param  array<int, string>                $keys   Required keys.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function required( array $rules, array $keys ): array
    {
        foreach ( $keys as $key ) {
            array_unshift( $rules[ $key ], 'required' );
        }

        return $rules;
    }

    /**
     * Prefixes every rule set with `sometimes` (partial update).
     *
     * @since 1.0.0
     *
     * @param  array<string, array<int, mixed>>  $rules  Rules.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function sometimes( array $rules ): array
    {
        return array_map( static fn ( array $set ): array => [ 'sometimes', ...$set ], $rules );
    }

    /**
     * @since 1.0.0
     *
     * @return StorefrontCartService
     */
    protected function carts(): StorefrontCartService
    {
        return app( StorefrontCartService::class );
    }
}
