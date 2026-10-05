<?php

/**
 * OrderEditResult value object.
 *
 * The return value of {@see \ArtisanPackUI\Ecommerce\Services\OrderEditService::apply()}
 * and {@see \ArtisanPackUI\Ecommerce\Services\OrderEditService::rollback()}.
 *
 * When the new total is higher than the pre-edit total, `paymentActionRequired`
 * carries the delta the caller needs to charge (plan §7.6). When it is lower,
 * `refundDelta` carries the amount that should be refunded to the customer;
 * actual gateway calls are the responsibility of downstream listeners on
 * {@see \ArtisanPackUI\Ecommerce\Events\OrderEdited}. Both fields are `null`
 * when the total is unchanged.
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
use ArtisanPackUI\Ecommerce\Models\OrderEdit;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class OrderEditResult
{
    /**
     * @since 1.0.0
     *
     * @param  Order                            $order                  The refreshed order after the edit.
     * @param  OrderEdit                        $edit                   The audit row that was persisted.
     * @param  array<string, mixed>             $diff                   The diff JSON that was persisted on `$edit->diff`.
     * @param  array{delta_amount:int,currency:string}|null  $paymentActionRequired
     *         Non-null when the new total is greater than the pre-edit total.
     * @param  array{delta_amount:int,currency:string}|null  $refundDelta
     *         Non-null when the new total is less than the pre-edit total.
     */
    public function __construct(
        public readonly Order $order,
        public readonly OrderEdit $edit,
        public readonly array $diff,
        public readonly ?array $paymentActionRequired = null,
        public readonly ?array $refundDelta = null,
    ) {
    }
}
