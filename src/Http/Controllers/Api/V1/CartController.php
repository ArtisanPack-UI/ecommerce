<?php

/**
 * CartController.
 *
 * Storefront cart endpoints (engine spec §9.2). A cart is addressed by its
 * opaque 40-char token, which is the credential; an unknown token is a 404.
 * Mutations delegate to {@see StorefrontCartService}, which the GraphQL
 * cart mutations share, and expected failures surface as 422 problem+json.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1;

use ArtisanPackUI\Ecommerce\Catalog\RelatedProducts;
use ArtisanPackUI\Ecommerce\Exceptions\CartCurrencyMismatchException;
use ArtisanPackUI\Ecommerce\Http\Middleware\IdempotencyMiddleware;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\AddCartItemRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\ApplyCouponRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\CreateCartRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\MergeCartRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\SelectShippingRateRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\ShippingDestinationRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\UpdateCartItemRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\UpdateCartRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\CartResource;
use ArtisanPackUI\Ecommerce\Http\Resources\ProductResource;
use ArtisanPackUI\Ecommerce\Http\Support\ListQuery;
use ArtisanPackUI\Ecommerce\Http\Support\Problem;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use ArtisanPackUI\Ecommerce\Services\CartService;
use ArtisanPackUI\Ecommerce\Services\CurrentCart;
use ArtisanPackUI\Ecommerce\Services\CustomerService;
use ArtisanPackUI\Ecommerce\Services\StorefrontCartService;
use ArtisanPackUI\Ecommerce\ValueObjects\Address;
use ArtisanPackUI\Ecommerce\ValueObjects\CartMergeResolution;
use ArtisanPackUI\Ecommerce\ValueObjects\PendingCartMerge;
use ArtisanPackUI\Ecommerce\ValueObjects\ShippingRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class CartController extends ApiController
{
    /**
     * Includes a client may request.
     *
     * @since 1.0.0
     *
     * @var array<string, string>
     */
    private const INCLUDES = [
        'items'         => 'items',
        'items.product' => 'items.product',
        'items.variant' => 'items.variant',
    ];

    /**
     * @since 1.0.0
     *
     * @param  CartService            $carts        Cart lookup.
     * @param  StorefrontCartService  $storefront   Shopper-facing cart operations.
     * @param  CurrentCart            $currentCart  Guest → account merge.
     * @param  CustomerService        $customers    Customer for a signed-in shopper.
     */
    public function __construct(
        private readonly CartService $carts,
        private readonly StorefrontCartService $storefront,
        private readonly CurrentCart $currentCart,
        private readonly CustomerService $customers,
    ) {
    }

    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     * @param  string   $cart     Cart token.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Get a cart by token', resource: CartResource::class, includes: self::INCLUDES )]
    public function show( Request $request, string $cart ): JsonResponse
    {
        return $this->resourceResponse( $this->find( $cart ), $request, CartResource::class, self::INCLUDES );
    }

    /**
     * @since 1.0.0
     *
     * @param  CreateCartRequest  $request  Validated request.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Create a cart', resource: CartResource::class, status: 201, includes: self::INCLUDES )]
    public function store( CreateCartRequest $request ): JsonResponse
    {
        $user     = $request->user();
        $customer = null === $user ? null : $this->customers->customerForUser( $user, true );

        // A guest's replayed response never carries the token: guests share
        // an IP-based idempotency scope (audit F10).
        if ( null === $user ) {
            request()->attributes->set( IdempotencyMiddleware::REDACT_ATTRIBUTE, [ 'token' ] );
        }
        $cart     = $this->storefront->create( $request->validated( 'currency' ), $request->validated( 'email' ), $customer );

        return $this->resourceResponse( $cart, $request, CartResource::class, self::INCLUDES, 201 );
    }

    /**
     * @since 1.0.0
     *
     * @param  AddCartItemRequest  $request  Validated request.
     * @param  string              $cart     Cart token.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Add an item to a cart', resource: CartResource::class, status: 201 )]
    public function addItem( AddCartItemRequest $request, string $cart ): JsonResponse
    {
        $model   = $this->find( $cart );
        $variant = $request->validated( 'product_variant_id' );

        $this->storefront->addItem(
            $model,
            (int) $request->validated( 'product_id' ),
            null === $variant ? null : (int) $variant,
            (int) $request->validated( 'quantity' ),
            (array) ( $request->validated( 'options' ) ?? [] ),
        );

        return $this->cartResponse( $model, $request, 201 );
    }

    /**
     * @since 1.0.0
     *
     * @param  UpdateCartItemRequest  $request  Validated request.
     * @param  string                 $cart     Cart token.
     * @param  int                    $item     Cart item id.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Change a cart item quantity', resource: CartResource::class )]
    public function updateItem( UpdateCartItemRequest $request, string $cart, int $item ): JsonResponse
    {
        $model = $this->find( $cart );

        $this->storefront->updateItem( $model, $this->findItem( $model, $item ), (int) $request->validated( 'quantity' ) );

        return $this->cartResponse( $model, $request );
    }

    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     * @param  string   $cart     Cart token.
     * @param  int      $item     Cart item id.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Remove a cart item', resource: CartResource::class )]
    public function removeItem( Request $request, string $cart, int $item ): JsonResponse
    {
        $model = $this->find( $cart );

        $this->storefront->removeItem( $model, $this->findItem( $model, $item ) );

        return $this->cartResponse( $model, $request );
    }

    /**
     * @since 1.0.0
     *
     * @param  ApplyCouponRequest  $request  Validated request.
     * @param  string              $cart     Cart token.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Apply a coupon to a cart', resource: CartResource::class )]
    public function applyCoupon( ApplyCouponRequest $request, string $cart ): JsonResponse
    {
        $model = $this->find( $cart );

        $this->storefront->applyCoupon( $model, (string) $request->validated( 'code' ) );

        return $this->cartResponse( $model, $request );
    }

    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     * @param  string   $cart     Cart token.
     * @param  string   $code     Coupon code.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Remove a coupon from a cart', resource: CartResource::class )]
    public function removeCoupon( Request $request, string $cart, string $code ): JsonResponse
    {
        $model = $this->find( $cart );

        $this->storefront->removeCoupon( $model, $code );

        return $this->cartResponse( $model, $request );
    }

    /**
     * Sets the cart's email and addresses. A changed address re-quotes a
     * chosen shipping rate.
     *
     * @since 1.0.0
     *
     * @param  UpdateCartRequest  $request  Validated request.
     * @param  string             $cart     Cart token.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Update a cart\'s email and addresses', resource: CartResource::class )]
    public function update( UpdateCartRequest $request, string $cart ): JsonResponse
    {
        $model = $this->find( $cart );

        $this->storefront->updateDetails( $model, $request->validated() );

        return $this->cartResponse( $model, $request );
    }

    /**
     * Empties the cart.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     * @param  string   $cart     Cart token.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Remove every item from a cart', resource: CartResource::class )]
    public function clear( Request $request, string $cart ): JsonResponse
    {
        $model = $this->find( $cart );

        $this->storefront->clear( $model );

        return $this->cartResponse( $model, $request );
    }

    /**
     * Shipping rates on offer for the cart and a destination — an estimate
     * before checkout (a country is enough).
     *
     * @since 1.0.0
     *
     * @param  ShippingDestinationRequest  $request  Validated request.
     * @param  string                      $cart     Cart token.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Quote shipping rates for a cart' )]
    public function shippingRates( ShippingDestinationRequest $request, string $cart ): JsonResponse
    {
        $rates = $this->storefront->quoteShipping( $this->find( $cart ), self::destination( $request->validated() ) );

        return new JsonResponse( [ 'data' => $rates->map( static fn ( ShippingRate $rate ): array => $rate->toArray() )->values()->all() ] );
    }

    /**
     * Cross-sells for the cart's products (#182).
     *
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     * @param  string   $cart     Cart token.
     *
     * @return JsonResponse
     */
    #[ApiOperation(
        summary: 'List cross-sells for a cart',
        resource: ProductResource::class,
        collection: true,
        includes: ProductController::OPENAPI_INCLUDES,
        query: [ 'limit' => [ 'schema' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 50 ] ] ],
    )]
    public function crossSells( Request $request, string $cart ): JsonResponse
    {
        $products = app( RelatedProducts::class )->crossSellsForCart(
            $this->find( $cart ),
            (int) $request->query( 'limit', '8' ),
            ListQuery::includes( $request, app( ProductController::class )->publicIncludes() ),
        );

        return ProductResource::collection( $products )->response( $request );
    }

    /**
     * Chooses one of the quoted rates (re-quoted server-side for the
     * destination).
     *
     * @since 1.0.0
     *
     * @param  SelectShippingRateRequest  $request  Validated request.
     * @param  string                     $cart     Cart token.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Choose a shipping rate for a cart', resource: CartResource::class )]
    public function selectShippingRate( SelectShippingRateRequest $request, string $cart ): JsonResponse
    {
        $model = $this->find( $cart );

        $this->storefront->selectShippingMethod( $model, self::destination( (array) $request->validated( 'destination' ) ), (string) $request->validated( 'rate_id' ) );

        return $this->cartResponse( $model, $request );
    }

    /**
     * Merges the guest cart `{cart}` into the signed-in shopper's cart
     * (parent plan §7.1), or attaches it to their account when they have no
     * cart yet. Answers 409 `cart-currency-mismatch` (with the currencies
     * and the `resolution` values to choose from) when the two carts'
     * currencies differ and no `resolution` was sent.
     *
     * @since 1.0.0
     *
     * @param  MergeCartRequest  $request  Validated request.
     * @param  string            $cart     Guest cart token.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Merge a guest cart into the signed-in shopper\'s cart', resource: CartResource::class )]
    public function merge( MergeCartRequest $request, string $cart ): JsonResponse
    {
        $user = $request->user();

        if ( null === $user ) {
            return Problem::make( 401, 'unauthenticated', __( 'Unauthenticated' ), __( 'Sign in to merge your cart.' ), $request );
        }

        abort_if( null === $this->currentCart->guestCart( $cart ), 404 );

        $resolution = $request->validated( 'resolution' );

        try {
            $result = $this->currentCart->mergeGuestCart( $cart, $user, null === $resolution ? null : CartMergeResolution::from( $resolution ) );
        } catch ( CartCurrencyMismatchException $exception ) {
            $pending  = PendingCartMerge::fromException( $exception );
            $response = Problem::make( 409, 'cart-currency-mismatch', __( 'Cart currency mismatch' ), __( 'Your cart is in :guest and your saved cart is in :account. Choose which currency to keep.', [
                'guest'   => $pending->guestCurrency,
                'account' => $pending->accountCurrency,
            ] ), $request, [ [ 'field' => 'resolution', 'code' => 'currency-mismatch', 'message' => __( 'Choose which currency to keep.' ) ] ] );

            return $response->setData( (array) $response->getData( true ) + [ 'merge' => $pending->toPublicArray() ] );
        }

        abort_if( null === $result, 404 );

        return $this->cartResponse( $result, $request );
    }

    /**
     * A destination for a shipping quote.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $data  Destination fields.
     *
     * @return Address
     */
    protected static function destination( array $data ): Address
    {
        return new Address(
            address1: '',
            city: (string) ( $data['city'] ?? '' ),
            countryCode: strtoupper( (string) $data['country_code'] ),
            regionCode: isset( $data['region_code'] ) ? (string) $data['region_code'] : null,
            postalCode: isset( $data['postal_code'] ) ? (string) $data['postal_code'] : null,
        );
    }

    /**
     * The cart for a token, or a 404.
     *
     * @since 1.0.0
     *
     * @param  string  $token  Cart token.
     *
     * @return Cart
     */
    protected function find( string $token ): Cart
    {
        $cart = $this->carts->findByToken( $token );

        // The token is the credential for a guest cart; a cart that belongs
        // to an account also needs that account's session (engine spec §9.2).
        abort_if( null === $cart || ! $cart->isAccessibleBy( request()->user() ), 404 );

        return $cart;
    }

    /**
     * A line of the cart, or a 404.
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart.
     * @param  int   $id    Item id.
     *
     * @return CartItem
     */
    protected function findItem( Cart $cart, int $id ): CartItem
    {
        return $cart->items()->whereKey( $id )->firstOrFail();
    }

    /**
     * The refreshed cart with its lines.
     *
     * @since 1.0.0
     *
     * @param  Cart     $cart     Cart.
     * @param  Request  $request  Request.
     * @param  int      $status   HTTP status.
     *
     * @return JsonResponse
     */
    protected function cartResponse( Cart $cart, Request $request, int $status = 200 ): JsonResponse
    {
        $cart->refresh()->load( 'items' );

        return $this->resourceResponse( $cart, $request, CartResource::class, self::INCLUDES, $status );
    }
}
