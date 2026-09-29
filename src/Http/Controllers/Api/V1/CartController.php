<?php

/**
 * CartController.
 *
 * `GET carts/{cart}` — a cart by its opaque 40-char token (engine spec
 * §9.2). The token is the credential; an unknown token is a 404.
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

use ArtisanPackUI\Ecommerce\Http\Resources\CartResource;
use ArtisanPackUI\Ecommerce\Services\CartService;
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
     * @since 1.0.0
     *
     * @param  CartService  $carts  Cart service.
     */
    public function __construct( private readonly CartService $carts )
    {
    }

    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     * @param  string   $cart     Cart token.
     *
     * @return JsonResponse
     */
    public function show( Request $request, string $cart ): JsonResponse
    {
        $model = $this->carts->findByToken( $cart );

        abort_if( null === $model, 404 );

        return $this->resourceResponse( $model, $request, CartResource::class, [
            'items'          => 'items',
            'items.product'  => 'items.product',
            'items.variant'  => 'items.variant',
        ] );
    }
}
