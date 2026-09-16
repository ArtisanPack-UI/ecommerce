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

/**
 * In-memory reference {@see PaymentGateway} used only to prove the shared
 * {@see PaymentGatewayContractTest} suite runs green — no provider I/O.
 */
final class InMemoryPaymentGateway implements PaymentGateway
{
    public const WEBHOOK_SECRET   = 'test-shared-secret';
    public const SIGNATURE_HEADER = 'X-InMemory-Signature';

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
        return new PaymentSession(
            gatewayKey: $this->key(),
            reference: 'ps_' . ( $cart->getKey() ?? 'new' ),
            amount: Money::USD( 0 ),
        );
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
    }

    public function refund( Order $order, Money $amount, ?string $reason = null ): RefundResult
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

    protected function makeOrder( string $currency = 'USD', int $capturedAmount = 10_000 ): Order
    {
        return Order::factory()->create( [
            'currency'            => $currency,
            'total_amount'        => $capturedAmount,
            'payment_status'      => 'paid',
            'payment_gateway_key' => 'in-memory',
        ] );
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
