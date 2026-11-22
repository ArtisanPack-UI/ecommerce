<?php

declare( strict_types=1 );

namespace Tests\Fixtures;

use ArtisanPackUI\Ecommerce\Contracts\CancelsPaymentSessions;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;
use RuntimeException;

/**
 * The checkout fake gateway, able to cancel a session the shopper hasn't
 * confirmed. `$confirmBeforeCancel` makes the next cancel find the session
 * confirmed (the shopper confirmed it in another tab in the meantime).
 */
class CancellableCheckoutFakeGateway extends CheckoutFakeGateway implements CancelsPaymentSessions
{
    /** @var array<int, string> */
    public array $canceled = [];

    public ?string $confirmBeforeCancel = null;

    public function cancelPaymentSession( string $reference ): void
    {
        $this->calls[] = 'cancelPaymentSession';

        if ( null !== $this->confirmBeforeCancel ) {
            $this->confirm( $reference, $this->confirmBeforeCancel );
            $this->confirmBeforeCancel = null;
        }

        $session = $this->sessions[ $reference ] ?? null;

        if ( null === $session || $session->isCanceled() ) {
            return;
        }

        if ( $session->isConfirmed() ) {
            throw new RuntimeException( "Session {$reference} is confirmed." );
        }

        $this->confirm( $reference, PaymentSession::STATUS_CANCELED );
        $this->canceled[] = $reference;
    }
}
