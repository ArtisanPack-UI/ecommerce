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
 *
 * Refunds and voids are recorded (`$refunds`, `$voids`) and can be made to
 * fail (`$refundsFail`, `$voidsFail`).
 */
class CheckoutFakeGateway implements PaymentGateway
{
    /** @var array<int, string> */
    public array $calls = [];

    /** @var array<string, PaymentSession> */
    public array $sessions = [];

    /** @var array<int, array<string, mixed>> */
    public array $contexts = [];

    /** @var array<int, array{reference: string, amount: int, context: array<string, mixed>}> */
    public array $refunds = [];

    /** @var array<int, string> */
    public array $voids = [];

    public bool $refundsFail = false;

    public bool $refundsSupported = true;

    /** The next refund goes through at the provider, but reports a failure. */
    public bool $refundsLoseResponse = false;

    /** The next void goes through at the provider, but throws. */
    public bool $voidsLoseResponse = false;

    /** @var array<string, array<string, mixed>> */
    public array $refundRequests = [];

    public bool $voidsFail = false;

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
        return $this->refundsSupported;
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

        if ( $this->voidsFail ) {
            throw new RuntimeException( 'The provider refused the void.' );
        }

        $reference = (string) $order->payment_reference;
        $session   = $this->sessions[ $reference ] ?? null;

        // Already voided: a no-op, like a real provider.
        if ( null === $session || $session->isCanceled() ) {
            return;
        }

        $this->voids[] = $reference;
        $this->confirm( $reference, PaymentSession::STATUS_CANCELED );

        if ( $this->voidsLoseResponse ) {
            $this->voidsLoseResponse = false;

            throw new RuntimeException( 'The provider voided the payment, but the response was lost.' );
        }
    }

    public function refund( Order $order, Money $amount, ?string $reason = null, array $context = [] ): RefundResult
    {
        $this->calls[] = 'refund';

        if ( $this->refundsFail ) {
            return RefundResult::failure( $amount, 'refund_refused', 'The provider refused the refund.' );
        }

        $key     = (string) ( $context['idempotency_key'] ?? '' );
        $request = [ 'reference' => (string) $order->payment_reference, 'amount' => (int) $amount->getAmount(), 'order_id' => $order->getKey() ];

        // Like Stripe: a reused key replays the first refund, but only for
        // the same request; a changed one is refused.
        if ( '' !== $key && isset( $this->refundRequests[ $key ] ) ) {
            if ( $this->refundRequests[ $key ] !== $request ) {
                return RefundResult::failure( $amount, 'idempotency_error', 'Keys for idempotent requests can only be used with the same parameters.' );
            }

            return RefundResult::success( $amount, 're_' . $key );
        }

        $this->refunds[] = $request + [ 'context' => $context ];

        if ( '' !== $key ) {
            $this->refundRequests[ $key ] = $request;
        }

        if ( $this->refundsLoseResponse ) {
            $this->refundsLoseResponse = false;

            return RefundResult::failure( $amount, 'api_connection_error', 'The response was lost.' );
        }

        return RefundResult::success( $amount, '' !== $key ? 're_' . $key : 're_' . $order->id );
    }

    public function handleWebhook( Request $request ): WebhookResult
    {
        return WebhookResult::unverified( 'not_implemented' );
    }
}
