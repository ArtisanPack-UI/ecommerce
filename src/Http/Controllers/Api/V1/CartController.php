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

use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\AddCartItemRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\ApplyCouponRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\CreateCartRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\UpdateCartItemRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\CartResource;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use ArtisanPackUI\Ecommerce\Services\CartService;
use ArtisanPackUI\Ecommerce\Services\StorefrontCartService;
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
     * @param  CartService            $carts       Cart lookup.
     * @param  StorefrontCartService  $storefront  Shopper-facing cart operations.
     */
    public function __construct(
        private readonly CartService $carts,
        private readonly StorefrontCartService $storefront,
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
    #[ApiOperation( summary: 'Get a cart by token', resource: CartResource::class )]
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
    #[ApiOperation( summary: 'Create a cart', resource: CartResource::class, status: 201 )]
    public function store( CreateCartRequest $request ): JsonResponse
    {
        $cart = $this->storefront->create( $request->validated( 'currency' ), $request->validated( 'email' ) );

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

        abort_if( null === $cart, 404 );

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
