<?php

/**
 * AcceptsPaymentOptions contract.
 *
 * Optional companion to {@see PaymentGateway} for a gateway that takes
 * shopper choices when its payment session is created, such as "save this
 * payment method" or "pay with my saved card" (#211). Livewire drivers can
 * pass those as gateway context themselves, but API storefronts (React,
 * Vue, headless) only reach the gateway through
 * {@see \ArtisanPackUI\Ecommerce\Services\CheckoutService::createPaymentSession()}.
 *
 * A storefront sends the choices as `payment_options` on the REST
 * `checkout/{cart}/session` call or the GraphQL `createPaymentSession`
 * mutation. The checkout validates them against
 * {@see self::paymentOptionRules()} and hands only the validated keys to the
 * gateway as `$context['payment_options']`. Unknown keys, and any options for
 * a gateway without this contract, are refused. When options are sent, the
 * cart's current session is reused only while
 * {@see self::paymentOptionsChanged()} says they still match it.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.3.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Contracts;

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.3.0
 */
interface AcceptsPaymentOptions
{
    /**
     * Laravel validation rules for the options this gateway accepts for
     * `$cart`, keyed by option name (`[ 'save_payment_method' => [ 'boolean' ] ]`).
     * Return an empty array when no option applies (for example, saving is
     * turned off or the shopper is a guest): any option is then refused.
     *
     * @since 1.3.0
     *
     * @param  Cart  $cart  The cart being paid for.
     *
     * @return array<string, mixed>
     */
    public function paymentOptionRules( Cart $cart ): array;

    /**
     * Whether `$session`, the cart's current session, was created with
     * options other than `$options`, so it can't be reused and a new one
     * must be created.
     *
     * @since 1.3.0
     *
     * @param  Cart                  $cart     The cart being paid for.
     * @param  PaymentSession        $session  The cart's current session.
     * @param  array<string, mixed>  $options  The validated options sent now.
     *
     * @return bool
     */
    public function paymentOptionsChanged( Cart $cart, PaymentSession $session, array $options ): bool;
}
