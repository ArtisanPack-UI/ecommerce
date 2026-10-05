<?php

/**
 * CheckoutController.
 *
 * The `checkout/{cart}/*` endpoints (engine spec §9.2) over
 * {@see CheckoutService}: the same steps, guards, and outcomes the
 * in-process storefronts use. The cart token is the credential for a guest
 * cart; an account's cart also needs that account's session.
 *
 * Every response carries the cart (or, after finalize, the order) plus a
 * `checkout` block with the state, whether the order ships, the quoted
 * shipping rates, the payment gateways on offer, and the guest-checkout
 * policy.
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

use ArtisanPackUI\Ecommerce\Contracts\PaymentGateway;
use ArtisanPackUI\Ecommerce\Exceptions\CheckoutException;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\CheckoutAddressRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\CheckoutFinalizeRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\CheckoutPaymentGatewayRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\CheckoutSessionRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\CheckoutShippingMethodRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\CartResource;
use ArtisanPackUI\Ecommerce\Http\Resources\OrderResource;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use ArtisanPackUI\Ecommerce\Services\CartService;
use ArtisanPackUI\Ecommerce\Services\CheckoutService;
use ArtisanPackUI\Ecommerce\Services\StorefrontCartService;
use ArtisanPackUI\Ecommerce\Support\ClientPaymentConfig;
use ArtisanPackUI\Ecommerce\Support\ReturnUrl;
use ArtisanPackUI\Ecommerce\ValueObjects\Address;
use ArtisanPackUI\Ecommerce\ValueObjects\ShippingRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class CheckoutController extends ApiController
{
    /**
     * @since 1.0.0
     *
     * @param  CartService            $carts       Cart lookup.
     * @param  CheckoutService        $checkout    Checkout steps.
     * @param  StorefrontCartService  $storefront  Cart rules.
     */
    public function __construct(
        private readonly CartService $carts,
        private readonly CheckoutService $checkout,
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
    #[ApiOperation( summary: 'Get the checkout state of a cart', resource: CartResource::class )]
    public function show( Request $request, string $cart ): JsonResponse
    {
        return $this->cartResponse( $this->find( $cart ), $request );
    }

    /**
     * Starts checkout and holds the cart's stock. `checkout.adjustments`
     * lists lines that were reduced or removed for lack of stock.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     * @param  string   $cart     Cart token.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Start checkout and hold stock', resource: CartResource::class )]
    public function start( Request $request, string $cart ): JsonResponse
    {
        $start = $this->checkout->start( $this->find( $cart ) );

        return $this->cartResponse( $start->cart, $request, [ 'adjustments' => $start->adjustments ] );
    }

    /**
     * @since 1.0.0
     *
     * @param  CheckoutAddressRequest  $request  Validated request.
     * @param  string                  $cart     Cart token.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Set the checkout email and addresses', resource: CartResource::class )]
    public function address( CheckoutAddressRequest $request, string $cart ): JsonResponse
    {
        $model = $this->find( $cart );
        $email = $request->validated( 'email' );

        if ( null !== $email ) {
            $this->checkout->setEmail( $model, (string) $email );
        }

        $shipping = $request->validated( 'shipping_address' );
        $billing  = $request->validated( 'billing_address' );

        $this->checkout->setAddress(
            $model,
            null === $shipping ? null : Address::fromArray( (array) $shipping ),
            null === $billing ? null : Address::fromArray( (array) $billing ),
        );

        return $this->cartResponse( $model, $request );
    }

    /**
     * @since 1.0.0
     *
     * @param  CheckoutShippingMethodRequest  $request  Validated request.
     * @param  string                         $cart     Cart token.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Choose a shipping rate', resource: CartResource::class )]
    public function shippingMethod( CheckoutShippingMethodRequest $request, string $cart ): JsonResponse
    {
        $model = $this->find( $cart );

        $this->checkout->setShippingMethod( $model, (string) $request->validated( 'rate_id' ) );

        return $this->cartResponse( $model, $request );
    }

    /**
     * @since 1.0.0
     *
     * @param  CheckoutPaymentGatewayRequest  $request  Validated request.
     * @param  string                         $cart     Cart token.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Choose a payment gateway', resource: CartResource::class )]
    public function paymentGateway( CheckoutPaymentGatewayRequest $request, string $cart ): JsonResponse
    {
        $model = $this->find( $cart );

        $this->checkout->setPaymentGateway( $model, (string) $request->validated( 'gateway' ) );

        return $this->cartResponse( $model, $request );
    }

    /**
     * Creates (or reuses) the payment session. `checkout.payment` carries the
     * session reference and status, and `checkout.payment.client` what the
     * storefront needs to render the provider's payment UI.
     *
     * @since 1.0.0
     *
     * @param  CheckoutSessionRequest  $request  Validated request.
     * @param  string                  $cart     Cart token.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Create the payment session', resource: CartResource::class )]
    public function session( CheckoutSessionRequest $request, string $cart ): JsonResponse
    {
        $model     = $this->find( $cart );
        $returnUrl = $request->validated( 'return_url' );

        if ( null !== $returnUrl && ! ReturnUrl::isAllowed( (string) $returnUrl, $request ) ) {
            throw new CheckoutException( 'return_url', 'return-url-invalid', __( 'The return URL must be on this site.' ) );
        }

        $session = $this->checkout->createPaymentSession( $model, array_filter( [
            'return_url'      => $returnUrl,
            'idempotency_key' => $request->header( 'Idempotency-Key' ),
        ], static fn ( mixed $value ): bool => null !== $value && '' !== $value ) );

        $gateway = $this->checkout->availableGateways( $model )[ (string) $model->payment_gateway_key ] ?? null;

        return $this->cartResponse( $model, $request, [ 'payment' => [
            'reference' => $session->reference,
            'status'    => $session->status,
            'amount'    => [ 'amount' => (int) $session->amount->getAmount(), 'currency' => $session->amount->getCurrency()->getCode() ],
            'client'    => null === $gateway ? null : ClientPaymentConfig::for( $gateway, $model, $session ),
        ] ] );
    }

    /**
     * Places the order and captures the payment. `checkout.status` is
     * `captured`, `challenged` (complete `checkout.step_up_token`, then call
     * finalize again), `blocked`, or `failed` (the payment can be retried).
     *
     * @since 1.0.0
     *
     * @param  CheckoutFinalizeRequest  $request  Validated request.
     * @param  string                   $cart     Cart token.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Place the order and capture the payment', resource: OrderResource::class )]
    public function finalize( CheckoutFinalizeRequest $request, string $cart ): JsonResponse
    {
        $result = $this->checkout->finalize( $this->find( $cart ), $request->validated( 'payment_reference' ), array_filter( [
            'idempotency_key' => $request->header( 'Idempotency-Key' ),
            'ip_address'      => $request->ip(),
            'user_agent'      => $request->userAgent(),
            'customer_note'   => $request->validated( 'customer_note' ),
        ], static fn ( mixed $value ): bool => null !== $value && '' !== $value ) );

        $order = $result->order->refresh()->load( 'items' );

        return ( new OrderResource( $order ) )->additional( [ 'checkout' => [
            'status'        => $result->status(),
            'complete'      => $result->isComplete(),
            'step_up_token' => $result->stepUpToken(),
        ] ] )->response( $request )->setStatusCode( $result->isComplete() ? 201 : 200 );
    }

    /**
     * The cart by token, or a 404 (also for an account's cart opened without
     * that account's session).
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

        abort_if( null === $cart || ! $cart->isAccessibleBy( request()->user() ), 404 );

        return $cart;
    }

    /**
     * The cart plus the `checkout` block.
     *
     * @since 1.0.0
     *
     * @param  Cart                  $cart     Cart.
     * @param  Request               $request  Request.
     * @param  array<string, mixed>  $extra    Extra `checkout` fields.
     *
     * @return JsonResponse
     */
    protected function cartResponse( Cart $cart, Request $request, array $extra = [] ): JsonResponse
    {
        $cart->refresh()->load( 'items' );

        return ( new CartResource( $cart ) )->additional( [ 'checkout' => [
            'state'             => $cart->checkout_state,
            'requires_shipping' => $this->storefront->requiresShipping( $cart ),
            'shipping_rates'    => $this->rates( $cart ),
            'gateways'          => array_values( array_map( static fn ( PaymentGateway $gateway ): array => [ 'key' => $gateway->key(), 'label' => $gateway->label() ], $this->checkout->availableGateways( $cart ) ) ),
            'guest_checkout'    => $this->checkout->guestCheckout(),
            'account_creation'  => $this->checkout->offersAccountCreation(),
        ] + $extra ] )->response( $request );
    }

    /**
     * Rates for the cart's shipping address (empty without one).
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function rates( Cart $cart ): array
    {
        if ( null === $cart->shipping_address || null !== $cart->completed_order_id ) {
            return [];
        }

        try {
            return $this->checkout->shippingRates( $cart )->map( static fn ( ShippingRate $rate ): array => $rate->toArray() )->values()->all();
        } catch ( Throwable ) {
            return [];
        }
    }
}
