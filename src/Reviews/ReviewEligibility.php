<?php

/**
 * ReviewEligibility.
 *
 * Whether a shopper may review a product (#181), why not when they can't
 * (`guests-not-allowed`, `already-reviewed`, `purchase-required`), and
 * whether their review would carry the verified-purchase badge.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Reviews;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class ReviewEligibility
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const GUESTS_NOT_ALLOWED = 'guests-not-allowed';

    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const ALREADY_REVIEWED = 'already-reviewed';

    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const PURCHASE_REQUIRED = 'purchase-required';

    /**
     * @since 1.0.0
     *
     * @param  bool         $allowed            Whether a review may be submitted.
     * @param  string|null  $reason             Why not, when it can't.
     * @param  bool         $verifiedPurchase   Whether the review would be a verified purchase.
     * @param  int|null     $purchaseOrderId    The paid order proving the purchase.
     */
    public function __construct(
        public readonly bool $allowed,
        public readonly ?string $reason = null,
        public readonly bool $verifiedPurchase = false,
        public readonly ?int $purchaseOrderId = null,
    ) {
    }

    /**
     * @since 1.0.0
     *
     * @return array{allowed: bool, reason: string|null, verified_purchase: bool}
     */
    public function toArray(): array
    {
        return [ 'allowed' => $this->allowed, 'reason' => $this->reason, 'verified_purchase' => $this->verifiedPurchase ];
    }
}
