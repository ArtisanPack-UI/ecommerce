<?php

declare( strict_types=1 );

namespace Tests\Fixtures;

use ArtisanPackUI\Ecommerce\Contracts\PaymentGateway;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentResult;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;
use ArtisanPackUI\Ecommerce\ValueObjects\RefundResult;
use ArtisanPackUI\Ecommerce\ValueObjects\WebhookResult;
use Illuminate\Http\Request;
use Money\Currency;
use Money\Money;
use RuntimeException;

/**
 * A two-phase gateway for checkout tests: sessions are created for the
 * cart's total, "confirmed" by the test (as the shopper's browser would),
 * and captured once confirmed.
 */
final class CheckoutFakeGateway implements PaymentGateway
{
    /** @var array<int, string> */
    public array $calls = [];

    /** @var array<string, PaymentSession> */
    public array $sessions = [];

    /** @var array<int, array<string, mixed>> */
    public array $contexts = [];

    private int $sequence = 0;

    public function __construct( public string $keyName = 'fake' )
    {
    }

    public function key(): string
    {
        return $this->keyName;
    }

    public function label(): string
    {
        return 'Fake card';
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
        return false;
    }

    public function createPaymentSession( Cart $cart, array $context = [] ): PaymentSession
    {
        $this->calls[]    = 'createPaymentSession';
        $this->contexts[] = $context;
        $reference        = 'ps_' . ( ++$this->sequence );

        return $this->sessions[ $reference ] = new PaymentSession(
            gatewayKey: $this->keyName,
            reference: $reference,
            amount: new Money( (int) $cart->total_amount, new Currency( (string) $cart->currency ) ),
            clientSecret: 'secret_' . $reference,
            status: PaymentSession::STATUS_REQUIRES_PAYMENT_METHOD,
        );
    }

    /** The shopper confirms the session client-side. */
    public function confirm( string $reference, string $status = PaymentSession::STATUS_AUTHORIZED ): void
    {
        $session = $this->sessions[ $reference ] ?? throw new RuntimeException( "No session {$reference}." );

        $this->sessions[ $reference ] = new PaymentSession(
            gatewayKey: $session->gatewayKey,
            reference: $session->reference,
            amount: $session->amount,
            clientSecret: $session->clientSecret,
            status: $status,
        );
    }

    public function retrievePaymentSession( string $reference ): PaymentSession
    {
        $this->calls[] = 'retrievePaymentSession';

        return $this->sessions[ $reference ] ?? throw new RuntimeException( "No session {$reference}." );
    }

    public function capturePayment( Order $order, PaymentSession $session ): PaymentResult
    {
        $this->calls[] = 'capturePayment';

        $this->confirm( $session->reference, PaymentSession::STATUS_SUCCEEDED );

        return PaymentResult::success( $session->amount, 'ch_' . $session->reference );
    }

    public function voidPendingPayment( Order $order ): void
    {
        $this->calls[] = 'voidPendingPayment';
    }

    public function refund( Order $order, Money $amount, ?string $reason = null, array $context = [] ): RefundResult
    {
        $this->calls[] = 'refund';

        return RefundResult::success( $amount, 're_' . $order->id );
    }

    public function handleWebhook( Request $request ): WebhookResult
    {
        return WebhookResult::unverified( 'not_implemented' );
    }
}
