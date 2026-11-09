<?php

declare( strict_types=1 );

namespace Tests\Fixtures;

use ArtisanPackUI\Ecommerce\Contracts\PaymentGateway;
use ArtisanPackUI\Ecommerce\Contracts\SharesWebhookEndpoint;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentResult;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;
use ArtisanPackUI\Ecommerce\ValueObjects\RefundResult;
use ArtisanPackUI\Ecommerce\ValueObjects\WebhookResult;
use Illuminate\Http\Request;
use Money\Money;
use RuntimeException;

/**
 * The webhook-receiving gateway of a provider whose sibling gateways share
 * one webhook endpoint (#207). Only its key and declared keys are used: the
 * checkouts it reconciles are paid through the sibling gateways.
 */
final class SharedWebhookFakeGateway implements PaymentGateway, SharesWebhookEndpoint
{
    /**
     * @param  array<int, mixed>  $sharedKeys  What {@see self::webhookGatewayKeys()} returns.
     */
    public function __construct(
        public string $keyName = 'fake-shared',
        public array $sharedKeys = [],
    ) {
    }

    public function webhookGatewayKeys(): array
    {
        return $this->sharedKeys;
    }

    public function key(): string
    {
        return $this->keyName;
    }

    public function label(): string
    {
        return 'Fake shared';
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
        throw new RuntimeException( 'Not used.' );
    }

    public function retrievePaymentSession( string $reference ): PaymentSession
    {
        throw new RuntimeException( 'Not used.' );
    }

    public function capturePayment( Order $order, PaymentSession $session ): PaymentResult
    {
        throw new RuntimeException( 'Not used.' );
    }

    public function voidPendingPayment( Order $order ): void
    {
        throw new RuntimeException( 'Not used.' );
    }

    public function refund( Order $order, Money $amount, ?string $reason = null, array $context = [] ): RefundResult
    {
        throw new RuntimeException( 'Not used.' );
    }

    public function handleWebhook( Request $request ): WebhookResult
    {
        return WebhookResult::unverified( 'not_implemented' );
    }
}
