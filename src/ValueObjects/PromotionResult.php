<?php

/**
 * PromotionResult value object.
 *
 * Output of {@see \ArtisanPackUI\Ecommerce\Services\PromotionEngine::evaluate()}:
 * the promotions that applied (in application order), the discount ledger
 * they produced, and — when a coupon code was supplied — what happened to
 * it (`couponStatus`, one of the `COUPON_*` constants).
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

use ArtisanPackUI\Ecommerce\Models\Promotion;
use ArtisanPackUI\Ecommerce\Support\DiscountLedger;
use Money\Money;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class PromotionResult
{
    /**
     * Coupon applied.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const COUPON_APPLIED = 'applied';

    /**
     * No coupon with that code exists.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const COUPON_INVALID = 'invalid';

    /**
     * The coupon's promotion is disabled or outside its date window.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const COUPON_INACTIVE = 'inactive';

    /**
     * The coupon's promotion has no usage left (overall or for this customer).
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const COUPON_EXHAUSTED = 'exhausted';

    /**
     * The cart doesn't meet the coupon promotion's conditions.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const COUPON_NOT_ELIGIBLE = 'not-eligible';

    /**
     * The coupon lost to an exclusive promotion (or is exclusive and
     * another promotion already applied).
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const COUPON_NOT_COMBINABLE = 'not-combinable';

    /**
     * @since 1.0.0
     *
     * @param  DiscountLedger          $ledger        Discounts granted.
     * @param  array<int, Promotion>   $applied       Applied promotions, in order.
     * @param  string|null             $couponCode    Normalized coupon code supplied, if any.
     * @param  string|null             $couponStatus  Outcome for `$couponCode`.
     */
    public function __construct(
        public readonly DiscountLedger $ledger,
        public readonly array $applied,
        public readonly ?string $couponCode = null,
        public readonly ?string $couponStatus = null,
    ) {
    }

    /**
     * Total discount across all lines.
     *
     * @since 1.0.0
     *
     * @return Money
     */
    public function discountTotal(): Money
    {
        return $this->ledger->total();
    }

    /**
     * Whether any applied promotion granted free shipping.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function hasFreeShipping(): bool
    {
        return $this->ledger->hasFreeShipping();
    }

    /**
     * Whether the supplied coupon applied.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function couponApplied(): bool
    {
        return self::COUPON_APPLIED === $this->couponStatus;
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'discount_total' => (int) $this->ledger->total()->getAmount(),
            'currency'       => $this->ledger->currency()->getCode(),
            'free_shipping'  => $this->ledger->hasFreeShipping(),
            'coupon'         => null === $this->couponCode ? null : [
                'code'   => $this->couponCode,
                'status' => $this->couponStatus,
            ],
            'promotions' => array_map( fn ( Promotion $promotion ): array => [
                'id'            => $promotion->id,
                'key'           => $promotion->key,
                'name'          => $promotion->name,
                'amount'        => (int) $this->ledger->amountForPromotion( $promotion->id )->getAmount(),
                'free_shipping' => $this->ledger->grantedFreeShipping( $promotion->id ),
            ], $this->applied ),
            'line_discounts' => array_map( static fn ( Money $m ): int => (int) $m->getAmount(), $this->ledger->lineDiscounts() ),
            'free_items'     => $this->ledger->freeItems(),
        ];
    }
}
