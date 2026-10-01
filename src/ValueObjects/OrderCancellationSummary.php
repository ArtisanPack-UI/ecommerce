<?php

/**
 * OrderCancellationSummary value object.
 *
 * What cancelling an order does, or did. Returned by
 * {@see \ArtisanPackUI\Ecommerce\Services\OrderCancellationService::assess()}
 * before a cancel (so a UI can show what will be released and whether a
 * refund is owed) and by
 * {@see \ArtisanPackUI\Ecommerce\Services\OrderCancellationService::cancel()}
 * after one.
 *
 * Cancelling never refunds. When the order was paid, `refundOwedAmount`
 * is what is still owed to the customer; issue it through
 * {@see \ArtisanPackUI\Ecommerce\Services\RefundService::issue()}.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\ValueObjects;

use ArtisanPackUI\Ecommerce\Models\Order;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class OrderCancellationSummary
{
    /**
     * @since 1.0.0
     *
     * @param  Order                                                     $order             The order (refreshed, after a cancel).
     * @param  bool                                                      $cancellable       Whether the order can be cancelled (always true on a cancel result).
     * @param  string|null                                               $blockedReason     Why it cannot be, when not cancellable.
     * @param  array<int, array{inventory_item_id: int, quantity: int}>  $reservations      Reservations to release, or released.
     * @param  bool                                                      $voidsPayment      Whether an uncaptured payment is (or was) voided.
     * @param  int                                                       $refundOwedAmount  Minor units still owed to the customer; 0 when nothing was captured.
     * @param  string                                                    $currency          The order currency.
     */
    public function __construct(
        public readonly Order $order,
        public readonly bool $cancellable,
        public readonly ?string $blockedReason,
        public readonly array $reservations,
        public readonly bool $voidsPayment,
        public readonly int $refundOwedAmount,
        public readonly string $currency,
    ) {
    }

    /**
     * Whether a refund is still owed to the customer.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function refundOwed(): bool
    {
        return $this->refundOwedAmount > 0;
    }

    /**
     * The total units held by the reservations.
     *
     * @since 1.0.0
     *
     * @return int
     */
    public function reservedUnits(): int
    {
        return array_sum( array_column( $this->reservations, 'quantity' ) );
    }
}
