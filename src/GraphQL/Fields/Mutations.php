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
 * | `createProduct` / `updateProduct` / `deleteProduct` | `POST` / `PATCH` / `DELETE admin/products[/{product}]` |
 * | `createProductVariant` / `updateProductVariant` / `deleteProductVariant` | `admin/products/{product}/variants[/{variant}]` |
 * | `createProductPrice` / `updateProductPrice` / `deleteProductPrice` | `admin/products/{product}/prices[/{price}]` |
 * | `createCategory` / `updateCategory` / `deleteCategory` | `admin/product-categories[/{category}]` |
 * | `createTag` / `updateTag` / `deleteTag` | `admin/product-tags[/{tag}]` |
 * | `createOrderSubstatus` / `updateOrderSubstatus` / `deleteOrderSubstatus` | `admin/order-substatuses[/{substatus}]` |
 * | `reorderOrderSubstatuses` | `POST admin/order-substatuses/reorder` |
 * | `adjustInventory` | `POST admin/inventory/{item}/adjust` |
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

use ArtisanPackUI\Ecommerce\Exceptions\CartCurrencyMismatchException;
use ArtisanPackUI\Ecommerce\Exceptions\CartOperationException;
use ArtisanPackUI\Ecommerce\Exceptions\CheckoutException;
use ArtisanPackUI\Ecommerce\Exceptions\IdempotencyConflictException;
use ArtisanPackUI\Ecommerce\Exceptions\NotificationTemplateException;
use ArtisanPackUI\Ecommerce\Exceptions\OrderNotCancellableException;
use ArtisanPackUI\Ecommerce\Exceptions\OrderSubstatusWriteException;
use ArtisanPackUI\Ecommerce\Exceptions\ProductWriteException;
use ArtisanPackUI\Ecommerce\Exceptions\RefundNotAllowedException;
use ArtisanPackUI\Ecommerce\GraphQL\GraphQLError;
use ArtisanPackUI\Ecommerce\GraphQL\PayloadError;
use ArtisanPackUI\Ecommerce\GraphQL\Support\Resolvers;
use ArtisanPackUI\Ecommerce\Http\Middleware\IdempotencyMiddleware;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\AddCartItemRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\AddOrderNoteRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\AdjustInventoryRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\ApplyCouponRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\CancelOrderRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\CheckoutAddressRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\CheckoutFinalizeRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\CheckoutPaymentGatewayRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\CheckoutSessionRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\CheckoutShippingMethodRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\CreateCartRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\IssueRefundRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\MergeCartRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\OrderSubstatusRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\PreviewNotificationTemplateRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\ProductCategoryRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\ProductPriceRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\ProductRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\ProductTagRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\ProductVariantRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\ReorderOrderSubstatusesRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\SelectShippingRateRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\UpdateCartItemRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\UpdateCartRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\UpdateNotificationTemplateRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\WebhookSubscriptionRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\WebhookSubscriptionResource;
use ArtisanPackUI\Ecommerce\Http\Support\ValidationErrors;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\Models\NotificationTemplate;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderSubstatus;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductCategory;
use ArtisanPackUI\Ecommerce\Models\ProductPrice;
use ArtisanPackUI\Ecommerce\Models\ProductTag;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use ArtisanPackUI\Ecommerce\Models\WebhookDelivery;
use ArtisanPackUI\Ecommerce\Models\WebhookSubscription;
use ArtisanPackUI\Ecommerce\Services\CheckoutService;
use ArtisanPackUI\Ecommerce\Services\CurrentCart;
use ArtisanPackUI\Ecommerce\Services\CustomerService;
use ArtisanPackUI\Ecommerce\Services\InventoryService;
use ArtisanPackUI\Ecommerce\Services\NotificationTemplateService;
use ArtisanPackUI\Ecommerce\Services\OrderCancellationService;
use ArtisanPackUI\Ecommerce\Services\OrderNoteService;
use ArtisanPackUI\Ecommerce\Services\OrderSubstatusService;
use ArtisanPackUI\Ecommerce\Services\ProductCategoryService;
use ArtisanPackUI\Ecommerce\Services\ProductService;
use ArtisanPackUI\Ecommerce\Services\ProductTagService;
use ArtisanPackUI\Ecommerce\Services\RefundService;
use ArtisanPackUI\Ecommerce\Services\StorefrontCartService;
use ArtisanPackUI\Ecommerce\Services\WebhookSubscriptionService;
use ArtisanPackUI\Ecommerce\Support\ClientPaymentConfig;
use ArtisanPackUI\Ecommerce\Support\IdempotentAction;
use ArtisanPackUI\Ecommerce\Support\ReturnUrl;
use ArtisanPackUI\Ecommerce\ValueObjects\Address;
use ArtisanPackUI\Ecommerce\ValueObjects\CartMergeResolution;
use ArtisanPackUI\Ecommerce\ValueObjects\PendingCartMerge;
use ArtisanPackUI\Ecommerce\ValueObjects\ShippingRate;
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
            'MergeCartInput'        => $input( [ 'cart_token' => 'String!', 'resolution' => 'String' ] ),
            'MergeCartPayload'      => $output( [ 'cart' => 'Cart', 'merged' => 'Boolean!', 'pending' => 'CartMergePending' ] ),
            'CartMergePending'      => [ 'fields' => [ 'guest_currency' => 'String!', 'account_currency' => 'String!', 'resolutions' => '[String!]!' ] ],

            'UpdateCartInput'               => $input( [ 'cart_token' => 'String!', 'email' => 'String', 'shipping_address' => 'JSON', 'billing_address' => 'JSON' ] ),
            'UpdateCartPayload'             => $output( [ 'cart' => 'Cart' ] ),
            'ClearCartInput'                => $input( [ 'cart_token' => 'String!' ] ),
            'ClearCartPayload'              => $output( [ 'cart' => 'Cart' ] ),
            'SelectCartShippingRateInput'   => $input( [ 'cart_token' => 'String!', 'rate_id' => 'String!', 'destination' => 'JSON!' ] ),
            'SelectCartShippingRatePayload' => $output( [ 'cart' => 'Cart' ] ),
            'StartCheckoutInput'            => $input( [ 'cart_token' => 'String!' ] ),
            'StartCheckoutPayload'          => $output( [ 'cart' => 'Cart', 'adjustments' => 'JSON' ] ),
            'SetCheckoutAddressInput'       => $input( [ 'cart_token' => 'String!', 'email' => 'String', 'shipping_address' => 'JSON', 'billing_address' => 'JSON' ] ),
            'SetCheckoutAddressPayload'     => $output( [ 'cart' => 'Cart', 'shipping_rates' => 'JSON' ] ),
            'SetShippingMethodInput'        => $input( [ 'cart_token' => 'String!', 'rate_id' => 'String!' ] ),
            'SetShippingMethodPayload'      => $output( [ 'cart' => 'Cart' ] ),
            'SetPaymentGatewayInput'        => $input( [ 'cart_token' => 'String!', 'gateway' => 'String!' ] ),
            'SetPaymentGatewayPayload'      => $output( [ 'cart' => 'Cart' ] ),
            'CheckoutPaymentSession'        => [ 'fields' => [ 'reference' => 'String!', 'status' => 'String', 'amount' => 'Money!', 'client' => 'JSON' ] ],
            'CreatePaymentSessionInput'     => $input( [ 'cart_token' => 'String!', 'return_url' => 'String' ] ),
            'CreatePaymentSessionPayload'   => $output( [ 'cart' => 'Cart', 'session' => 'CheckoutPaymentSession' ] ),
            'PlaceOrderInput'               => $input( [ 'cart_token' => 'String!', 'payment_reference' => 'String', 'customer_note' => 'String' ] ),
            'PlaceOrderPayload'             => $output( [ 'order' => 'Order', 'status' => 'String', 'complete' => 'Boolean', 'step_up_token' => 'String' ] ),
            'RefundLineInput'               => [
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

            'ProductPriceRowInput'         => [
                'kind'   => 'input',
                'fields' => [
                    'currency'          => 'String!',
                    'price_amount'      => 'BigInt!',
                    'compare_at_amount' => 'BigInt',
                    'cost_amount'       => 'BigInt',
                    'starts_at'         => 'String',
                    'ends_at'           => 'String',
                ],
            ],
            'ProductImageRowInput'         => [
                'kind'   => 'input',
                'fields' => [ 'id' => 'ID', 'media_id' => 'Int', 'image_url' => 'String', 'alt_text' => 'String' ],
            ],
            'ProductChildRowInput'         => [
                'kind'   => 'input',
                'fields' => [ 'product_id' => 'ID!', 'variant_id' => 'ID', 'quantity' => 'Int' ],
            ],
            'CreateProductInput'           => $input( [ 'type' => 'String!', 'name' => 'String!' ] + $this->productFields() ),
            'CreateProductPayload'         => $output( [ 'product' => 'Product' ] ),
            'UpdateProductInput'           => $input( [ 'id' => 'ID!', 'type' => 'String', 'name' => 'String', 'stock_adjustment' => 'JSON' ] + $this->productFields() ),
            'UpdateProductPayload'         => $output( [ 'product' => 'Product' ] ),
            'DeleteProductInput'           => $input( [ 'id' => 'ID!' ] ),
            'DeleteProductPayload'         => $output( [ 'deleted_id' => 'ID' ] ),
            'CreateProductVariantInput'    => $input( [ 'product_id' => 'ID!' ] + $this->variantFields() ),
            'CreateProductVariantPayload'  => $output( [ 'variant' => 'ProductVariant' ] ),
            'UpdateProductVariantInput'    => $input( [ 'id' => 'ID!', 'stock_adjustment' => 'JSON' ] + $this->variantFields() ),
            'UpdateProductVariantPayload'  => $output( [ 'variant' => 'ProductVariant' ] ),
            'DeleteProductVariantInput'    => $input( [ 'id' => 'ID!' ] ),
            'DeleteProductVariantPayload'  => $output( [ 'deleted_id' => 'ID' ] ),
            'CreateProductPriceInput'      => $input( [
                'product_id'         => 'ID!',
                'product_variant_id' => 'ID',
                'currency'           => 'String!',
                'price_amount'       => 'BigInt!',
                'compare_at_amount'  => 'BigInt',
                'cost_amount'        => 'BigInt',
                'starts_at'          => 'String',
                'ends_at'            => 'String',
            ] ),
            'CreateProductPricePayload'    => $output( [ 'price' => 'ProductPrice' ] ),
            'UpdateProductPriceInput'      => $input( [
                'id'                => 'ID!',
                'currency'          => 'String',
                'price_amount'      => 'BigInt',
                'compare_at_amount' => 'BigInt',
                'cost_amount'       => 'BigInt',
                'starts_at'         => 'String',
                'ends_at'           => 'String',
            ] ),
            'UpdateProductPricePayload'    => $output( [ 'price' => 'ProductPrice' ] ),
            'DeleteProductPriceInput'      => $input( [ 'id' => 'ID!' ] ),
            'DeleteProductPricePayload'    => $output( [ 'deleted_id' => 'ID' ] ),
            'CreateCategoryInput'          => $input( [ 'name' => 'String!' ] + $this->categoryFields() ),
            'CreateCategoryPayload'        => $output( [ 'category' => 'ProductCategory' ] ),
            'UpdateCategoryInput'          => $input( [ 'id' => 'ID!', 'name' => 'String' ] + $this->categoryFields() ),
            'UpdateCategoryPayload'        => $output( [ 'category' => 'ProductCategory' ] ),
            'DeleteCategoryInput'          => $input( [ 'id' => 'ID!' ] ),
            'DeleteCategoryPayload'        => $output( [ 'deleted_id' => 'ID' ] ),
            'CreateTagInput'               => $input( [ 'name' => 'String!', 'slug' => 'String' ] ),
            'CreateTagPayload'             => $output( [ 'tag' => 'ProductTag' ] ),
            'UpdateTagInput'               => $input( [ 'id' => 'ID!', 'name' => 'String', 'slug' => 'String' ] ),
            'UpdateTagPayload'             => $output( [ 'tag' => 'ProductTag' ] ),
            'DeleteTagInput'               => $input( [ 'id' => 'ID!' ] ),
            'DeleteTagPayload'             => $output( [ 'deleted_id' => 'ID' ] ),

            'CreateOrderSubstatusInput'      => $input( [ 'system_status' => 'String!', 'label' => 'String!' ] + $this->substatusFields() ),
            'CreateOrderSubstatusPayload'    => $output( [ 'substatus' => 'OrderSubstatus' ] ),
            'UpdateOrderSubstatusInput'      => $input( [ 'id' => 'ID!', 'label' => 'String' ] + $this->substatusFields() ),
            'UpdateOrderSubstatusPayload'    => $output( [ 'substatus' => 'OrderSubstatus' ] ),
            'DeleteOrderSubstatusInput'      => $input( [ 'id' => 'ID!' ] ),
            'DeleteOrderSubstatusPayload'    => $output( [ 'deleted_id' => 'ID' ] ),
            'ReorderOrderSubstatusesInput'   => $input( [ 'system_status' => 'String!', 'ids' => '[ID!]!' ] ),
            'ReorderOrderSubstatusesPayload' => $output( [ 'substatuses' => '[OrderSubstatus!]' ] ),

            'AdjustInventoryInput'   => $input( [ 'inventory_item_id' => 'ID!', 'delta' => 'Int!', 'reason' => 'String!' ] ),
            'AdjustInventoryPayload' => $output( [ 'inventory_item' => 'InventoryItem' ] ),
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

                $user     = $this->r->user();
                $customer = null === $user ? null : app( CustomerService::class )->customerForUser( $user, true );
                $cart     = $this->carts()->create( $input['currency'] ?? null, $input['email'] ?? null, $customer );

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

            'mergeCart' => $this->mutation( 'MergeCart', function ( array $input, ResolveInfo $info ): array {
                $user = $this->r->requireUser();
                $this->r->throttle( 'ecommerce.cart.mutate', [ 'cart_token' => (string) $input['cart_token'] ] );
                $this->validate( $input, MergeCartRequest::baseRules() );

                $current = app( CurrentCart::class );

                if ( null === $current->guestCart( (string) $input['cart_token'] ) ) {
                    throw GraphQLError::notFound();
                }

                $resolution = isset( $input['resolution'] ) ? CartMergeResolution::from( (string) $input['resolution'] ) : null;

                try {
                    $cart = $current->mergeGuestCart( (string) $input['cart_token'], $user, $resolution );
                } catch ( CartCurrencyMismatchException $exception ) {
                    return [
                        'cart'    => null,
                        'merged'  => false,
                        'pending' => PendingCartMerge::fromException( $exception )->toPublicArray(),
                        'errors'  => [ [ 'field' => 'resolution', 'code' => 'currency-mismatch', 'message' => __( 'Choose which currency to keep.' ) ] ],
                    ];
                }

                return [ 'cart' => null === $cart ? null : $this->cart( $cart, $info ), 'merged' => null !== $cart, 'pending' => null ];
            } ),

            'updateCart' => $this->mutation( 'UpdateCart', function ( array $input, ResolveInfo $info ): array {
                $cart = $this->findCart( $input );
                $this->validate( $input, UpdateCartRequest::baseRules() );

                $this->carts()->updateDetails( $cart, array_intersect_key( $input, array_flip( [ 'email', 'shipping_address', 'billing_address' ] ) ) );

                return [ 'cart' => $this->cart( $cart, $info ) ];
            } ),

            'clearCart' => $this->mutation( 'ClearCart', function ( array $input, ResolveInfo $info ): array {
                $cart = $this->findCart( $input );

                $this->carts()->clear( $cart );

                return [ 'cart' => $this->cart( $cart, $info ) ];
            } ),

            'selectCartShippingRate' => $this->mutation( 'SelectCartShippingRate', function ( array $input, ResolveInfo $info ): array {
                $cart = $this->findCart( $input );
                $this->validate( $input, SelectShippingRateRequest::baseRules() );

                $destination = (array) $input['destination'];

                $this->carts()->selectShippingMethod( $cart, new Address(
                    address1: '',
                    city: (string) ( $destination['city'] ?? '' ),
                    countryCode: strtoupper( (string) $destination['country_code'] ),
                    regionCode: isset( $destination['region_code'] ) ? (string) $destination['region_code'] : null,
                    postalCode: isset( $destination['postal_code'] ) ? (string) $destination['postal_code'] : null,
                ), (string) $input['rate_id'] );

                return [ 'cart' => $this->cart( $cart, $info ) ];
            } ),

            'startCheckout' => $this->mutation( 'StartCheckout', function ( array $input, ResolveInfo $info ): array {
                $start = $this->checkout()->start( $this->findCart( $input ) );

                return [ 'cart' => $this->cart( $start->cart, $info ), 'adjustments' => $start->adjustments ];
            } ),

            'setCheckoutAddress' => $this->mutation( 'SetCheckoutAddress', function ( array $input, ResolveInfo $info ): array {
                $cart = $this->findCart( $input );
                $this->validate( $input, CheckoutAddressRequest::baseRules() );

                if ( isset( $input['email'] ) ) {
                    $this->checkout()->setEmail( $cart, (string) $input['email'] );
                }

                $this->checkout()->setAddress(
                    $cart,
                    isset( $input['shipping_address'] ) ? Address::fromArray( (array) $input['shipping_address'] ) : null,
                    isset( $input['billing_address'] ) ? Address::fromArray( (array) $input['billing_address'] ) : null,
                );

                $rates = null === $cart->refresh()->shipping_address ? [] : $this->checkout()->shippingRates( $cart )->map( static fn ( ShippingRate $rate ): array => $rate->toArray() )->values()->all();

                return [ 'cart' => $this->cart( $cart, $info ), 'shipping_rates' => $rates ];
            } ),

            'setShippingMethod' => $this->mutation( 'SetShippingMethod', function ( array $input, ResolveInfo $info ): array {
                $cart = $this->findCart( $input );
                $this->validate( $input, CheckoutShippingMethodRequest::baseRules() );

                $this->checkout()->setShippingMethod( $cart, (string) $input['rate_id'] );

                return [ 'cart' => $this->cart( $cart, $info ) ];
            } ),

            'setPaymentGateway' => $this->mutation( 'SetPaymentGateway', function ( array $input, ResolveInfo $info ): array {
                $cart = $this->findCart( $input );
                $this->validate( $input, CheckoutPaymentGatewayRequest::baseRules() );

                $this->checkout()->setPaymentGateway( $cart, (string) $input['gateway'] );

                return [ 'cart' => $this->cart( $cart, $info ) ];
            } ),

            'createPaymentSession' => $this->mutation( 'CreatePaymentSession', function ( array $input, ResolveInfo $info ): array {
                $cart = $this->findCart( $input, 'ecommerce.checkout.finalize' );
                $this->validate( $input, CheckoutSessionRequest::baseRules() );

                $returnUrl = $input['return_url'] ?? null;

                if ( null !== $returnUrl && ! ReturnUrl::isAllowed( (string) $returnUrl, request() ) ) {
                    throw new CheckoutException( 'return_url', 'return-url-invalid', __( 'The return URL must be on this site.' ) );
                }

                $session = $this->checkout()->createPaymentSession( $cart, array_filter( [
                    'return_url'      => $returnUrl,
                    'idempotency_key' => request()->header( 'Idempotency-Key' ),
                ], static fn ( mixed $value ): bool => null !== $value && '' !== $value ) );
                $gateway = $this->checkout()->availableGateways( $cart )[ (string) $cart->refresh()->payment_gateway_key ] ?? null;

                return [
                    'cart'    => $this->cart( $cart, $info ),
                    'session' => [
                        'reference' => $session->reference,
                        'status'    => $session->status,
                        'amount'    => [ 'amount' => (int) $session->amount->getAmount(), 'currency' => $session->amount->getCurrency()->getCode() ],
                        'client'    => null === $gateway ? null : ClientPaymentConfig::for( $gateway, $cart, $session ),
                    ],
                ];
            } ),

            'placeOrder' => $this->mutation( 'PlaceOrder', function ( array $input, ResolveInfo $info ): array {
                $cart = $this->findCart( $input, 'ecommerce.checkout.finalize' );
                $this->validate( $input, CheckoutFinalizeRequest::baseRules() );

                $result = $this->checkout()->finalize( $cart, isset( $input['payment_reference'] ) ? (string) $input['payment_reference'] : null, array_filter( [
                    'idempotency_key' => request()->header( 'Idempotency-Key' ),
                    'ip_address'      => request()->ip(),
                    'user_agent'      => request()->userAgent(),
                    'customer_note'   => $input['customer_note'] ?? null,
                ], static fn ( mixed $value ): bool => null !== $value && '' !== $value ) );

                return [
                    'order'         => $this->r->present( $result->order->fresh(), 'Order', $this->r->selection( $info, 'order' ) ),
                    'status'        => $result->status(),
                    'complete'      => $result->isComplete(),
                    'step_up_token' => $result->stepUpToken(),
                ];
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

                return $this->idempotent( 'issueRefund', $input, function () use ( $order, $input, $actor, $info ): array {
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
                } );
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

                return $this->idempotent( 'cancelOrder', $input, function () use ( $order, $input, $actor, $info ): array {
                    try {
                        $summary = app( OrderCancellationService::class )->cancel( $order, (string) $input['reason'], is_numeric( $actor ) ? (int) $actor : null );
                    } catch ( InvalidArgumentException $exception ) {
                        throw new OrderNotCancellableException( $exception->getMessage(), [], 0, $exception );
                    }

                    return [ 'order' => $this->r->present( $summary->order, 'Order', $this->r->selection( $info, 'order' ), true ) ];
                } );
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

                try {
                    $note = app( OrderNoteService::class )->add(
                        $order,
                        (string) $input['body'],
                        is_numeric( $actor ) ? (int) $actor : null,
                        (bool) ( $input['is_customer_visible'] ?? false ),
                    );
                } catch ( InvalidArgumentException $exception ) {
                    return [ 'errors' => [ [ 'field' => 'body', 'code' => 'note-invalid', 'message' => $exception->getMessage() ] ] ];
                }

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

            'createProduct' => $this->mutation( 'CreateProduct', function ( array $input, ResolveInfo $info ): array {
                $this->catalog( 'create' );
                $this->validate( $input, $this->required( ProductRequest::baseRules(), [ 'type', 'name' ] ) );

                $product = $this->products()->create( $this->without( $input, [ 'clientMutationId', 'stock_adjustment' ] ) );

                return [ 'product' => $this->r->present( $product, 'Product', $this->r->selection( $info, 'product' ), true ) ];
            } ),

            'updateProduct' => $this->mutation( 'UpdateProduct', function ( array $input, ResolveInfo $info ): array {
                $this->catalog( 'update' );

                if ( isset( $input['stock_adjustment'] ) ) {
                    $this->r->authorize( 'inventory', 'adjust' );
                }

                $product = Product::query()->find( $input['id'] ) ?? throw GraphQLError::notFound();
                $this->validate( $input, $this->sometimes( ProductRequest::baseRules() ) );

                $this->products()->update( $product, $this->without( $input, [ 'clientMutationId', 'id' ] ) );

                return [ 'product' => $this->r->present( $product->refresh(), 'Product', $this->r->selection( $info, 'product' ), true ) ];
            } ),

            'deleteProduct' => $this->mutation( 'DeleteProduct', function ( array $input ): array {
                $this->catalog( 'delete' );
                $product = Product::query()->find( $input['id'] ) ?? throw GraphQLError::notFound();

                $this->products()->delete( $product );

                return [ 'deleted_id' => $product->id ];
            } ),

            'createProductVariant' => $this->mutation( 'CreateProductVariant', function ( array $input, ResolveInfo $info ): array {
                $this->catalog( 'update' );
                $product = Product::query()->find( $input['product_id'] ) ?? throw GraphQLError::notFound();
                $this->validate( $input, ProductVariantRequest::baseRules() );

                $variant = $this->products()->createVariant( $product, $this->without( $input, [ 'clientMutationId', 'product_id', 'stock_adjustment' ] ) );

                return [ 'variant' => $this->r->present( $variant, 'ProductVariant', $this->r->selection( $info, 'variant' ), true ) ];
            } ),

            'updateProductVariant' => $this->mutation( 'UpdateProductVariant', function ( array $input, ResolveInfo $info ): array {
                $this->catalog( 'update' );

                if ( isset( $input['stock_adjustment'] ) ) {
                    $this->r->authorize( 'inventory', 'adjust' );
                }

                $variant = ProductVariant::query()->find( $input['id'] ) ?? throw GraphQLError::notFound();
                $this->validate( $input, $this->sometimes( ProductVariantRequest::baseRules() ) );

                $this->products()->updateVariant( $variant, $this->without( $input, [ 'clientMutationId', 'id' ] ) );

                return [ 'variant' => $this->r->present( $variant->refresh(), 'ProductVariant', $this->r->selection( $info, 'variant' ), true ) ];
            } ),

            'deleteProductVariant' => $this->mutation( 'DeleteProductVariant', function ( array $input ): array {
                $this->catalog( 'update' );
                $variant = ProductVariant::query()->find( $input['id'] ) ?? throw GraphQLError::notFound();

                $this->products()->deleteVariant( $variant );

                return [ 'deleted_id' => $variant->id ];
            } ),

            'createProductPrice' => $this->mutation( 'CreateProductPrice', function ( array $input, ResolveInfo $info ): array {
                $this->catalog( 'update' );
                $product = Product::query()->find( $input['product_id'] ) ?? throw GraphQLError::notFound();
                $this->validate( $input, $this->required( ProductPriceRequest::baseRules(), [ 'currency', 'price_amount' ] ) );
                $this->products()->assertEditable( $product );

                $priceable = empty( $input['product_variant_id'] )
                    ? $product
                    : ( ProductVariant::query()->where( 'product_id', $product->id )->find( $input['product_variant_id'] ) ?? throw GraphQLError::notFound() );

                $price = $this->products()->upsertPrice( $priceable, $this->without( $input, [ 'clientMutationId', 'product_id', 'product_variant_id' ] ) );

                return [ 'price' => $this->r->present( $price, 'ProductPrice', $this->r->selection( $info, 'price' ), true ) ];
            } ),

            'updateProductPrice' => $this->mutation( 'UpdateProductPrice', function ( array $input, ResolveInfo $info ): array {
                $this->catalog( 'update' );
                $price = ProductPrice::query()->find( $input['id'] ) ?? throw GraphQLError::notFound();
                $this->validate( $input, $this->sometimes( ProductPriceRequest::baseRules() ) );
                $this->assertPriceEditable( $price );

                $this->products()->updatePrice( $price, $this->without( $input, [ 'clientMutationId', 'id' ] ) );

                return [ 'price' => $this->r->present( $price->refresh(), 'ProductPrice', $this->r->selection( $info, 'price' ), true ) ];
            } ),

            'deleteProductPrice' => $this->mutation( 'DeleteProductPrice', function ( array $input ): array {
                $this->catalog( 'update' );
                $price = ProductPrice::query()->find( $input['id'] ) ?? throw GraphQLError::notFound();
                $this->assertPriceEditable( $price );

                $price->delete();

                return [ 'deleted_id' => $price->id ];
            } ),

            'createCategory' => $this->mutation( 'CreateCategory', function ( array $input, ResolveInfo $info ): array {
                $this->catalog( 'create' );
                $this->validate( $input, $this->required( ProductCategoryRequest::baseRules(), [ 'name' ] ) );

                $category = app( ProductCategoryService::class )->create( $this->without( $input, [ 'clientMutationId' ] ) );

                return [ 'category' => $this->r->present( $category, 'ProductCategory', $this->r->selection( $info, 'category' ), true ) ];
            } ),

            'updateCategory' => $this->mutation( 'UpdateCategory', function ( array $input, ResolveInfo $info ): array {
                $this->catalog( 'update' );
                $category = ProductCategory::query()->find( $input['id'] ) ?? throw GraphQLError::notFound();
                $this->validate( $input, $this->sometimes( ProductCategoryRequest::baseRules() ) );

                app( ProductCategoryService::class )->update( $category, $this->without( $input, [ 'clientMutationId', 'id' ] ) );

                return [ 'category' => $this->r->present( $category->refresh(), 'ProductCategory', $this->r->selection( $info, 'category' ), true ) ];
            } ),

            'deleteCategory' => $this->mutation( 'DeleteCategory', function ( array $input ): array {
                $this->catalog( 'delete' );
                $category = ProductCategory::query()->find( $input['id'] ) ?? throw GraphQLError::notFound();

                app( ProductCategoryService::class )->delete( $category );

                return [ 'deleted_id' => $category->id ];
            } ),

            'createTag' => $this->mutation( 'CreateTag', function ( array $input, ResolveInfo $info ): array {
                $this->catalog( 'create' );
                $this->validate( $input, $this->required( ProductTagRequest::baseRules(), [ 'name' ] ) );

                $tag = app( ProductTagService::class )->create( $this->without( $input, [ 'clientMutationId' ] ) );

                return [ 'tag' => $this->r->present( $tag, 'ProductTag', $this->r->selection( $info, 'tag' ), true ) ];
            } ),

            'updateTag' => $this->mutation( 'UpdateTag', function ( array $input, ResolveInfo $info ): array {
                $this->catalog( 'update' );
                $tag = ProductTag::query()->find( $input['id'] ) ?? throw GraphQLError::notFound();
                $this->validate( $input, $this->sometimes( ProductTagRequest::baseRules() ) );

                app( ProductTagService::class )->update( $tag, $this->without( $input, [ 'clientMutationId', 'id' ] ) );

                return [ 'tag' => $this->r->present( $tag->refresh(), 'ProductTag', $this->r->selection( $info, 'tag' ), true ) ];
            } ),

            'deleteTag' => $this->mutation( 'DeleteTag', function ( array $input ): array {
                $this->catalog( 'delete' );
                $tag = ProductTag::query()->find( $input['id'] ) ?? throw GraphQLError::notFound();

                app( ProductTagService::class )->delete( $tag );

                return [ 'deleted_id' => $tag->id ];
            } ),

            'createOrderSubstatus' => $this->mutation( 'CreateOrderSubstatus', function ( array $input, ResolveInfo $info ): array {
                $this->substatusAbility( 'create' );
                $this->validate( $input, $this->required( OrderSubstatusRequest::baseRules(), [ 'system_status', 'label' ] ) );

                $substatus = app( OrderSubstatusService::class )->create( $this->without( $input, [ 'clientMutationId' ] ) );

                return [ 'substatus' => $this->r->present( $substatus, 'OrderSubstatus', $this->r->selection( $info, 'substatus' ), true ) ];
            } ),

            'updateOrderSubstatus' => $this->mutation( 'UpdateOrderSubstatus', function ( array $input, ResolveInfo $info ): array {
                $this->substatusAbility( 'update' );
                $substatus = OrderSubstatus::query()->find( $input['id'] ) ?? throw GraphQLError::notFound();
                $this->validate( $input, $this->sometimes( OrderSubstatusRequest::baseRules() ) );

                $substatus = app( OrderSubstatusService::class )->update( $substatus, $this->without( $input, [ 'clientMutationId', 'id' ] ) );

                return [ 'substatus' => $this->r->present( $substatus, 'OrderSubstatus', $this->r->selection( $info, 'substatus' ), true ) ];
            } ),

            'deleteOrderSubstatus' => $this->mutation( 'DeleteOrderSubstatus', function ( array $input ): array {
                $this->substatusAbility( 'delete' );
                $substatus = OrderSubstatus::query()->find( $input['id'] ) ?? throw GraphQLError::notFound();

                app( OrderSubstatusService::class )->delete( $substatus );

                return [ 'deleted_id' => $substatus->id ];
            } ),

            'reorderOrderSubstatuses' => $this->mutation( 'ReorderOrderSubstatuses', function ( array $input ): array {
                $this->substatusAbility( 'update' );
                $this->validate( $input, ReorderOrderSubstatusesRequest::baseRules() );

                $ordered = app( OrderSubstatusService::class )->reorder( (string) $input['system_status'], (array) $input['ids'] );

                return [ 'substatuses' => $this->r->renderMany( $ordered, true ) ];
            } ),

            'adjustInventory' => $this->mutation( 'AdjustInventory', function ( array $input, ResolveInfo $info ): array {
                $this->r->authorize( 'inventory', 'adjust' );
                $this->r->throttle( 'ecommerce.admin.mutate' );
                $item = InventoryItem::query()->find( $input['inventory_item_id'] ) ?? throw GraphQLError::notFound();
                $this->validate( $input, AdjustInventoryRequest::baseRules() );

                return $this->idempotent( 'adjustInventory', $input, function () use ( $item, $input, $info ): array {
                    $item = app( InventoryService::class )->adjust( $item, (int) $input['delta'], trim( (string) $input['reason'] ) );

                    return [ 'inventory_item' => $this->r->present( $item, 'InventoryItem', $this->r->selection( $info, 'inventory_item' ), true ) ];
                } );
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
                } catch ( PayloadError $exception ) {
                    $payload = [ 'errors' => [ [ 'field' => $exception->field, 'code' => $exception->errorCode, 'message' => $exception->getMessage() ] ] ];
                } catch ( IdempotencyConflictException $exception ) {
                    $payload = [ 'errors' => [ [ 'field' => null, 'code' => 'idempotency-conflict', 'message' => $exception->getMessage() ] ] ];
                } catch ( RefundNotAllowedException $exception ) {
                    $payload = [ 'errors' => [ [ 'field' => null, 'code' => 'refund-not-allowed', 'message' => $exception->getMessage() ] ] ];
                } catch ( OrderNotCancellableException $exception ) {
                    $payload = [ 'errors' => [ [ 'field' => null, 'code' => 'order-not-cancellable', 'message' => $exception->getMessage() ] ] ];
                } catch ( NotificationTemplateException $exception ) {
                    $payload = [ 'errors' => $exception->errors ];
                } catch ( ProductWriteException $exception ) {
                    $payload = [ 'errors' => $exception->errors ];
                } catch ( OrderSubstatusWriteException $exception ) {
                    $payload = [ 'errors' => $exception->errors ];
                }

                return $payload + [ 'errors' => [], 'clientMutationId' => $input['clientMutationId'] ?? null ];
            },
        ];
    }

    /**
     * Runs a money- or stock-moving mutation once per `Idempotency-Key`
     * (audit F9): without the header it is refused with a
     * `idempotency-key-required` payload error; a retry with the same key
     * and input gets the first result back; the same key with other input
     * is an `idempotency-conflict`. Keys are scoped to the acting user.
     *
     * @since 1.0.0
     *
     * @param  string                $operation  Mutation name.
     * @param  array<string, mixed>  $input      Mutation input (the fingerprint).
     * @param  callable(): array<string, mixed>  $call  The mutation body.
     *
     * @throws PayloadError When the header is missing.
     *
     * @return array<string, mixed>
     */
    protected function idempotent( string $operation, array $input, callable $call ): array
    {
        $key = request()->header( 'Idempotency-Key' );

        if ( ! is_string( $key ) || '' === trim( $key ) ) {
            throw new PayloadError( null, 'idempotency-key-required', __( 'This mutation needs an Idempotency-Key header.' ) );
        }

        $user  = $this->r->user();
        $scope = sprintf( 'graphql.%s:user:%s', $operation, null === $user ? 'guest' : (string) $user->getAuthIdentifier() );

        unset( $input['clientMutationId'] );

        return app( IdempotentAction::class )->run( $scope, trim( $key ), $call, (string) json_encode( $input ) );
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
        return ValidationErrors::from( $exception->validator );
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

        $cart = Cart::query()->where( 'token', (string) $input['cart_token'] )->first();

        // An account's cart also needs that account's session (engine spec §9.2).
        if ( null === $cart || ! $cart->isAccessibleBy( $this->r->user() ) ) {
            throw GraphQLError::notFound();
        }

        return $cart;
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
     * Authorizes a catalog admin action (products, categories, and tags all
     * use the `product` abilities) and applies the admin rate policy.
     *
     * @since 1.0.0
     *
     * @param  string  $action  Action name.
     *
     * @throws GraphQLError When not allowed.
     *
     * @return void
     */
    protected function catalog( string $action ): void
    {
        $this->r->authorize( 'product', $action );
        $this->r->throttle( 'ecommerce.admin.mutate' );
    }

    /**
     * Authorizes an order sub-status admin action and applies the admin
     * rate policy.
     *
     * @since 1.0.0
     *
     * @param  string  $action  Action name.
     *
     * @throws GraphQLError When not allowed.
     *
     * @return void
     */
    protected function substatusAbility( string $action ): void
    {
        $this->r->authorize( 'orderSubstatus', $action );
        $this->r->throttle( 'ecommerce.admin.mutate' );
    }

    /**
     * Refuses a price write when its product's type is missing.
     *
     * @since 1.0.0
     *
     * @param  ProductPrice  $price  Price row.
     *
     * @throws ProductWriteException When the owning product is read-only.
     *
     * @return void
     */
    protected function assertPriceEditable( ProductPrice $price ): void
    {
        $owner   = $price->priceable;
        $product = $owner instanceof ProductVariant ? $owner->product : $owner;

        if ( $product instanceof Product ) {
            $this->products()->assertEditable( $product );
        }
    }

    /**
     * Shared optional fields of the product inputs.
     *
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function productFields(): array
    {
        return [
            'slug'                    => 'String',
            'sku'                     => 'String',
            'barcode'                 => 'String',
            'description'             => 'String',
            'short_description'       => 'String',
            'status'                  => 'String',
            'featured_image_media_id' => 'Int',
            'featured_image_url'      => 'String',
            'is_taxable'              => 'Boolean',
            'tax_class_key'           => 'String',
            'weight'                  => 'Float',
            'weight_unit'             => 'String',
            'length'                  => 'Float',
            'width'                   => 'Float',
            'height'                  => 'Float',
            'dim_unit'                => 'String',
            'meta'                    => 'JSON',
            'published_at'            => 'String',
            'prices'                  => '[ProductPriceRowInput!]',
            'category_ids'            => '[ID!]',
            'tag_ids'                 => '[ID!]',
            'images'                  => '[ProductImageRowInput!]',
            'attributes'              => 'JSON',
            'children'                => '[ProductChildRowInput!]',
            'inventory'               => 'JSON',
        ];
    }

    /**
     * Shared optional fields of the variant inputs.
     *
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function variantFields(): array
    {
        return [
            'sku'            => 'String',
            'barcode'        => 'String',
            'name'           => 'String',
            'image_media_id' => 'Int',
            'weight'         => 'Float',
            'weight_unit'    => 'String',
            'length'         => 'Float',
            'width'          => 'Float',
            'height'         => 'Float',
            'dim_unit'       => 'String',
            'position'       => 'Int',
            'meta'           => 'JSON',
            'option_values'  => 'JSON',
            'prices'         => '[ProductPriceRowInput!]',
            'inventory'      => 'JSON',
        ];
    }

    /**
     * Shared optional fields of the category inputs.
     *
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function categoryFields(): array
    {
        return [
            'parent_id'      => 'ID',
            'slug'           => 'String',
            'description'    => 'String',
            'image_media_id' => 'Int',
            'icon'           => 'String',
            'position'       => 'Int',
        ];
    }

    /**
     * Shared optional fields of the order sub-status inputs.
     *
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function substatusFields(): array
    {
        return [
            'key'         => 'String',
            'color'       => 'String',
            'icon'        => 'String',
            'is_terminal' => 'Boolean',
        ];
    }

    /**
     * `$input` without the listed keys.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $input  Input.
     * @param  array<int, string>    $keys   Keys to drop.
     *
     * @return array<string, mixed>
     */
    protected function without( array $input, array $keys ): array
    {
        return array_diff_key( $input, array_flip( $keys ) );
    }

    /**
     * @since 1.0.0
     *
     * @return ProductService
     */
    protected function products(): ProductService
    {
        return app( ProductService::class );
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

    /**
     * @since 1.0.0
     *
     * @return CheckoutService
     */
    protected function checkout(): CheckoutService
    {
        return app( CheckoutService::class );
    }
}
