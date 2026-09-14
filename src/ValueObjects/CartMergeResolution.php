<?php

/**
 * CartMergeResolution enum.
 *
 * Explicit resolution choices the caller may pass to
 * {@see \ArtisanPackUI\Ecommerce\Services\CartMergeService::merge()} when
 * guest and destination carts transact in different currencies. There is no
 * silent FX re-pricing — the caller must present these choices (typically
 * as a modal) and re-invoke the merge with the resolved value.
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

/**
 * Cart-merge currency-mismatch resolution.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
enum CartMergeResolution: string
{
    /**
     * Keep the guest cart's currency; discard the destination cart's lines and
     * carry the guest lines forward. The merged cart takes the guest cart's
     * currency on every paired currency column.
     */
    case KeepGuestCurrency = 'keep_guest_currency';

    /**
     * Keep the destination cart's currency; discard the guest cart's lines
     * and leave the destination lines untouched.
     */
    case SwitchToAccountCurrency = 'switch_to_account_currency';

    /**
     * Cancel the merge entirely. The guest cart is discarded and the
     * destination cart is returned unchanged.
     */
    case CancelMerge = 'cancel_merge';
}
