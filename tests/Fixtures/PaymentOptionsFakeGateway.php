<?php

declare( strict_types=1 );

namespace Tests\Fixtures;

use ArtisanPackUI\Ecommerce\Contracts\AcceptsPaymentOptions;
use ArtisanPackUI\Ecommerce\Contracts\PaymentGateway;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Services\CheckoutService;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentResult;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;
use ArtisanPackUI\Ecommerce\ValueObjects\RefundResult;
use ArtisanPackUI\Ecommerce\ValueObjects\WebhookResult;
use Illuminate\Http\Request;
use Money\Money;

/**
 * The two-phase {@see CheckoutFakeGateway}, accepting payment options
 * (#211): `save` (boolean) and `saved_method` (a positive integer). It
 * remembers the options each session was created with, so a session is
 * reused only while the same options are sent.
 */
final class PaymentOptionsFakeGateway implements PaymentGateway, AcceptsPaymentOptions
{
    /** The checkout fake doing the work. */
    public CheckoutFakeGateway $inner;

    /** @var array<string, array<string, mixed>> Options by session reference. */
    public array $optionsBySession = [];

    /** Whether options apply at all (the rules are empty when not). */
    public bool $acceptsOptions = true;

    public function __construct( string $keyName = 'fake-options' )
    {
        $this->inner = new CheckoutFakeGateway( $keyName );
    }

    public function paymentOptionRules( Cart $cart ): array
    {
        if ( ! $this->acceptsOptions ) {
            return [];
        }

        return [
            'save'         => [ 'sometimes', 'boolean' ],
            'saved_method' => [ 'sometimes', 'integer', 'min:1' ],
        ];
    }

    public function paymentOptionsChanged( Cart $cart, PaymentSession $session, array $options ): bool
    {
        return ( $this->optionsBySession[ $session->reference ] ?? [] ) != $options;
    }

    public function key(): string
    {
        return $this->inner->key();
    }

    public function label(): string
    {
        return 'Fake card with options';
    }

    public function supportsRefunds(): bool
    {
        return true;
    }

    public function supportsPartialRefunds(): bool
    {
        return true;
    }

    public function supportsSavedInstruments(): bool
    {
        return true;
    }

    public function createPaymentSession( Cart $cart, array $context = [] ): PaymentSession
    {
        $session = $this->inner->createPaymentSession( $cart, $context );

        $this->optionsBySession[ $session->reference ] = (array) ( $context[ CheckoutService::PAYMENT_OPTIONS ] ?? [] );

        return $session;
    }

    public function retrievePaymentSession( string $reference ): PaymentSession
    {
        return $this->inner->retrievePaymentSession( $reference );
    }

    public function capturePayment( Order $order, PaymentSession $session ): PaymentResult
    {
        return $this->inner->capturePayment( $order, $session );
    }

    public function voidPendingPayment( Order $order ): void
    {
        $this->inner->voidPendingPayment( $order );
    }

    public function refund( Order $order, Money $amount, ?string $reason = null, array $context = [] ): RefundResult
    {
        return $this->inner->refund( $order, $amount, $reason, $context );
    }

    public function handleWebhook( Request $request ): WebhookResult
    {
        return $this->inner->handleWebhook( $request );
    }
}
