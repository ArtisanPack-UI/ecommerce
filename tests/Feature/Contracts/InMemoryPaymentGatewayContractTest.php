<?php

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\PaymentGateway;
use ArtisanPackUI\Ecommerce\Exceptions\PaymentCurrencyMismatchException;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Testing\Contracts\PaymentGatewayContractTest;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentResult;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;
use ArtisanPackUI\Ecommerce\ValueObjects\RefundResult;
use ArtisanPackUI\Ecommerce\ValueObjects\WebhookResult;
use Illuminate\Http\Request;
use Money\Money;
use RuntimeException;

/**
 * In-memory reference {@see PaymentGateway} used only to prove the shared
 * {@see PaymentGatewayContractTest} suite runs green — no provider I/O.
 */
final class InMemoryPaymentGateway implements PaymentGateway
{
    public const WEBHOOK_SECRET   = 'test-shared-secret';
    public const SIGNATURE_HEADER = 'X-InMemory-Signature';

    /**
     * Authorization state per payment reference: `authorized` or `voided`.
     *
     * @var array<string, string>
     */
    public static array $authorizations = [];

    /**
     * Sessions created so far, by reference.
     *
     * @var array<string, PaymentSession>
     */
    public static array $sessions = [];

    public function key(): string
    {
        return 'in-memory';
    }

    public function label(): string
    {
        return 'In-memory (test only)';
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
        return self::$sessions[ 'ps_' . ( $cart->getKey() ?? 'new' ) ] = new PaymentSession(
            gatewayKey: $this->key(),
            reference: 'ps_' . ( $cart->getKey() ?? 'new' ),
            amount: Money::USD( (int) ( $cart->total_amount ?? 0 ) ),
            status: PaymentSession::STATUS_REQUIRES_PAYMENT_METHOD,
        );
    }

    public function retrievePaymentSession( string $reference ): PaymentSession
    {
        return self::$sessions[ $reference ] ?? throw new RuntimeException( "No session {$reference}." );
    }

    public function capturePayment( Order $order, PaymentSession $session ): PaymentResult
    {
        if ( $session->amount->getCurrency()->getCode() !== $order->currency ) {
            throw new PaymentCurrencyMismatchException( $order->currency, $session->amount->getCurrency()->getCode() );
        }

        return PaymentResult::success( $session->amount, 'pi_captured' );
    }

    public function voidPendingPayment( Order $order ): void
    {
        $reference = (string) $order->payment_reference;

        match ( self::$authorizations[ $reference ] ?? null ) {
            'authorized' => self::$authorizations[ $reference ] = 'voided',
            'voided'     => null, // Already voided: a no-op, per the contract.
            default      => throw new RuntimeException( "No authorization {$reference} to void." ),
        };
    }

    public function refund( Order $order, Money $amount, ?string $reason = null, array $context = [] ): RefundResult
    {
        if ( $amount->getCurrency()->getCode() !== $order->currency ) {
            throw new PaymentCurrencyMismatchException( $order->currency, $amount->getCurrency()->getCode() );
        }

        return RefundResult::success( $amount, 're_' . uniqid( 'inmem_', true ) );
    }

    public function handleWebhook( Request $request ): WebhookResult
    {
        $raw       = $request->getContent();
        $signature = (string) $request->header( self::SIGNATURE_HEADER, '' );
        $expected  = hash_hmac( 'sha256', $raw, self::WEBHOOK_SECRET );

        if ( '' === $signature || ! hash_equals( $expected, $signature ) ) {
            return WebhookResult::unverified( 'signature_mismatch', 'Signature header missing or invalid.' );
        }

        /** @var array<string, mixed> $payload */
        $payload = json_decode( $raw, true ) ?: [];

        return WebhookResult::verified(
            (string) ( $payload[ 'type' ] ?? 'unknown' ),
            (string) ( $payload[ 'id' ] ?? 'evt_' . uniqid() ),
            $payload,
        );
    }
}

/**
 * Verifies the in-memory reference gateway satisfies the shared
 * {@see PaymentGatewayContractTest} suite.
 *
 * @since 1.0.0
 */
final class InMemoryPaymentGatewayContractTest extends PaymentGatewayContractTest
{
    protected function gateway(): PaymentGateway
    {
        return new InMemoryPaymentGateway();
    }

    protected function existingPaymentSession(): PaymentSession
    {
        return $this->gateway()->createPaymentSession( Cart::factory()->create( [ 'total_amount' => 2_500 ] ) );
    }

    protected function makeOrder( string $currency = 'USD', int $capturedAmount = 10_000 ): Order
    {
        return Order::factory()->create( [
            'currency'            => $currency,
            'total_amount'        => $capturedAmount,
            'payment_status'      => 'paid',
            'payment_gateway_key' => 'in-memory',
        ] );
    }

    protected function makePendingAuthorizationOrder(): Order
    {
        $order = Order::factory()->create( [
            'currency'            => 'USD',
            'total_amount'        => 10_000,
            'payment_status'      => 'pending',
            'payment_gateway_key' => 'in-memory',
            'payment_reference'   => 'pi_auth_' . uniqid(),
        ] );

        InMemoryPaymentGateway::$authorizations[ (string) $order->payment_reference ] = 'authorized';

        return $order;
    }

    protected function signedWebhookRequest(): Request
    {
        $payload   = json_encode( [ 'type' => 'payment.captured', 'id' => 'evt_signed_1' ], JSON_THROW_ON_ERROR );
        $signature = hash_hmac( 'sha256', $payload, InMemoryPaymentGateway::WEBHOOK_SECRET );

        $request = Request::create( '/webhooks/in-memory', 'POST', [], [], [], [], $payload );
        $request->headers->set( InMemoryPaymentGateway::SIGNATURE_HEADER, $signature );
        $request->headers->set( 'Content-Type', 'application/json' );

        return $request;
    }

    protected function unsignedWebhookRequest(): Request
    {
        $payload = json_encode( [ 'type' => 'payment.captured', 'id' => 'evt_unsigned_1' ], JSON_THROW_ON_ERROR );

        $request = Request::create( '/webhooks/in-memory', 'POST', [], [], [], [], $payload );
        $request->headers->set( 'Content-Type', 'application/json' );

        return $request;
    }

    protected function captureTerminalDeclineResult(): PaymentResult
    {
        return PaymentResult::terminalFailure( Money::USD( 10_000 ), 'card_declined', 'The card was declined.' );
    }

    protected function captureRetryableResult(): PaymentResult
    {
        return PaymentResult::retryableFailure( Money::USD( 10_000 ), 'provider_unavailable', 'Provider returned 503.' );
    }
}
