<?php

/**
 * PromotionEngine.
 *
 * Runs the promotion pipeline from parent plan §5.9:
 *
 * 1. Load active `automatic` promotions, plus the promotion behind
 *    `$couponCode` when one is supplied. The candidate list runs through
 *    the `ap.ecommerce.promotions.candidates` filter so satellite sources
 *    (gift cards, referrals, loyalty) can add their own promotions.
 * 2. Drop promotions with no usage left (overall, and per customer when
 *    the cart has one).
 * 3. Drop promotions whose conditions don't all pass. Unknown or throwing
 *    conditions fail closed.
 * 4. Sort survivors by `priority` (lower first), then id.
 * 5. Walk the list applying each promotion's actions to a
 *    {@see DiscountLedger}. **Stacking rules:** an `is_exclusive` promotion
 *    applies only if nothing has applied before it, and once it applies
 *    nothing after it does. So a higher-priority exclusive promotion
 *    excludes everything below it, and a lower-priority exclusive
 *    promotion is skipped if anything already applied.
 * 6. The ledger holds per-line and cart-level discount amounts
 *    ({@see self::applyToCart()} writes the total onto the cart).
 * 7. On order placement, {@see self::recordUsage()} writes one
 *    `promotion_usages` row per applied promotion.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Services;

use ArtisanPackUI\Ecommerce\Exceptions\PromotionUsageLimitReachedException;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Coupon;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\Promotion;
use ArtisanPackUI\Ecommerce\Models\PromotionUsage;
use ArtisanPackUI\Ecommerce\Registries\PromotionActionRegistry;
use ArtisanPackUI\Ecommerce\Registries\PromotionConditionRegistry;
use ArtisanPackUI\Ecommerce\Support\DiscountLedger;
use ArtisanPackUI\Ecommerce\ValueObjects\PromotionResult;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class PromotionEngine
{
    /**
     * @since 1.0.0
     *
     * @param  PromotionConditionRegistry  $conditions  Registered conditions.
     * @param  PromotionActionRegistry     $actions     Registered actions.
     */
    public function __construct(
        private readonly PromotionConditionRegistry $conditions,
        private readonly PromotionActionRegistry $actions,
    ) {
    }

    /**
     * Evaluates every applicable promotion against `$cart`.
     *
     * @since 1.0.0
     *
     * @param  Cart         $cart        Cart being evaluated (not mutated).
     * @param  string|null  $couponCode  Customer-entered coupon code.
     *
     * @return PromotionResult
     */
    public function evaluate( Cart $cart, ?string $couponCode = null ): PromotionResult
    {
        $now          = Carbon::now();
        $customerId   = null === $cart->customer_id ? null : (int) $cart->customer_id;
        $couponCode   = null === $couponCode ? null : Coupon::normalize( $couponCode );
        $couponCode   = '' === $couponCode ? null : $couponCode;
        $couponStatus = null;
        $couponPromo  = null;

        $candidates = Promotion::query()
            ->with( [ 'conditions', 'actions' ] )
            ->activeAt( $now )
            ->where( 'source_type', Promotion::SOURCE_AUTOMATIC )
            ->where( fn ( $q ) => $q->whereNull( 'usage_limit_total' )->orWhereColumn( 'times_used', '<', 'usage_limit_total' ) )
            ->get()
            ->keyBy( 'id' )
            ->all();

        if ( null !== $couponCode ) {
            $coupon = Coupon::findByCode( $couponCode );

            if ( null === $coupon ) {
                $couponStatus = PromotionResult::COUPON_INVALID;
            } else {
                $couponPromo = $coupon->promotion()->with( [ 'conditions', 'actions' ] )->first();

                if ( null === $couponPromo || ! $couponPromo->isActiveAt( $now ) ) {
                    $couponStatus = PromotionResult::COUPON_INACTIVE;
                    $couponPromo  = null;
                } elseif ( ! $couponPromo->hasUsageRemaining( $customerId ) ) {
                    $couponStatus = PromotionResult::COUPON_EXHAUSTED;
                    $couponPromo  = null;
                } else {
                    $candidates[ $couponPromo->id ] = $couponPromo;
                }
            }
        }

        $candidates = array_filter(
            (array) applyFilters( 'ap.ecommerce.promotions.candidates', array_values( $candidates ), $cart, $couponCode ),
            fn ( mixed $promotion ): bool => $this->isUsableCandidate( $promotion, $now ),
        );

        $customerUsage = $this->customerUsageCounts( $candidates, $customerId );
        $eligible      = [];

        foreach ( $candidates as $promotion ) {
            if ( ! $this->hasUsageLeft( $promotion, $customerUsage ) ) {
                continue;
            }

            if ( $this->conditionsPass( $promotion, $cart ) ) {
                $eligible[] = $promotion;
            } elseif ( null !== $couponPromo && $promotion->id === $couponPromo->id ) {
                $couponStatus = PromotionResult::COUPON_NOT_ELIGIBLE;
            }
        }

        usort( $eligible, static fn ( Promotion $a, Promotion $b ): int => [ $a->priority, $a->id ] <=> [ $b->priority, $b->id ] );

        $ledger  = new DiscountLedger( $cart );
        $applied = [];

        foreach ( $eligible as $promotion ) {
            if ( $promotion->is_exclusive && [] !== $applied ) {
                if ( null !== $couponPromo && $promotion->id === $couponPromo->id ) {
                    $couponStatus = PromotionResult::COUPON_NOT_COMBINABLE;
                }

                continue;
            }

            $freeItemsBefore = count( $ledger->freeItems() );
            $this->applyActions( $promotion, $cart, $ledger );

            // A promotion only counts as applied if it actually granted
            // something — otherwise a $0 exclusive promotion would block
            // everything after it and burn a use in recordUsage().
            if ( ! $ledger->amountForPromotion( $promotion->id )->isPositive()
                && ! $ledger->grantedFreeShipping( $promotion->id )
                && count( $ledger->freeItems() ) === $freeItemsBefore ) {
                if ( null !== $couponPromo && $promotion->id === $couponPromo->id ) {
                    $couponStatus = PromotionResult::COUPON_NOT_ELIGIBLE;
                }

                continue;
            }

            $applied[] = $promotion;

            doAction( 'ap.ecommerce.pricing.discountApplied', $promotion, $cart, $ledger->amountForPromotion( $promotion->id ) );

            if ( $promotion->is_exclusive ) {
                break;
            }
        }

        if ( null !== $couponPromo ) {
            $wasApplied = [] !== array_filter( $applied, static fn ( Promotion $p ): bool => $p->id === $couponPromo->id );

            if ( $wasApplied ) {
                $couponStatus = PromotionResult::COUPON_APPLIED;
            } elseif ( null === $couponStatus ) {
                $couponStatus = PromotionResult::COUPON_NOT_COMBINABLE;
            }
        }

        return new PromotionResult( $ledger, $applied, $couponCode, $couponStatus );
    }

    /**
     * Writes the evaluation's discount total onto the cart.
     *
     * @since 1.0.0
     *
     * @param  Cart             $cart    Cart to update.
     * @param  PromotionResult  $result  Result of {@see self::evaluate()}.
     *
     * @return Cart
     */
    public function applyToCart( Cart $cart, PromotionResult $result ): Cart
    {
        $cart->discount_amount   = (int) $result->discountTotal()->getAmount();
        $cart->discount_currency = $result->discountTotal()->getCurrency()->getCode();
        $cart->save();

        return $cart;
    }

    /**
     * Records one `promotion_usages` row per applied promotion and bumps
     * `times_used`. Call inside the order-placement transaction, late and
     * short — each promotion row is locked until that transaction commits,
     * so never wrap payment-gateway calls around it. Re-checks the total
     * and per-customer limits under the lock, and is idempotent per order.
     *
     * @since 1.0.0
     *
     * @param  Order            $order   Placed order.
     * @param  PromotionResult  $result  Evaluation the order was priced with.
     *
     * @throws PromotionUsageLimitReachedException When a promotion's total or per-customer limit was reached concurrently.
     *
     * @return array<int, PromotionUsage>
     */
    public function recordUsage( Order $order, PromotionResult $result ): array
    {
        return DB::transaction( function () use ( $order, $result ): array {
            $usages = [];

            foreach ( $result->applied as $promotion ) {
                // Row lock serialises concurrent placements of this promotion
                // so the per-customer count below can't be raced.
                $locked = Promotion::query()->whereKey( $promotion->id )->lockForUpdate()->first();

                if ( null === $locked ) {
                    throw new PromotionUsageLimitReachedException( __( 'Promotion ":key" no longer exists.', [ 'key' => $promotion->key ] ) );
                }

                // Idempotent: a retried placement for the same order records nothing twice.
                $existing = PromotionUsage::query()->where( 'promotion_id', $locked->id )->where( 'order_id', $order->id )->first();

                if ( null !== $existing ) {
                    $usages[] = $existing;

                    continue;
                }

                if ( null !== $locked->usage_limit_total && $locked->times_used >= $locked->usage_limit_total ) {
                    throw new PromotionUsageLimitReachedException( __( 'Promotion ":name" has reached its usage limit.', [ 'name' => $locked->name ] ) );
                }

                // Enforced here too because the cart may have been evaluated
                // as a guest and placed by a signed-in customer.
                if ( null !== $order->customer_id && null !== $locked->usage_limit_per_customer
                    && $locked->usages()->where( 'customer_id', $order->customer_id )->count() >= $locked->usage_limit_per_customer ) {
                    throw new PromotionUsageLimitReachedException( __( 'Promotion ":name" has already been used the maximum number of times by this customer.', [ 'name' => $locked->name ] ) );
                }

                $locked->increment( 'times_used' );

                $amount = $result->ledger->amountForPromotion( $locked->id );

                $usages[] = PromotionUsage::query()->create( [
                    'promotion_id'      => $locked->id,
                    'order_id'          => $order->id,
                    'customer_id'       => $order->customer_id,
                    'amount_discounted' => (int) $amount->getAmount(),
                    'currency'          => $amount->getCurrency()->getCode(),
                ] );
            }

            return $usages;
        } );
    }

    /**
     * Whether a filter-supplied candidate can take part: it must be a
     * persisted, active promotion (a satellite handing over an unsaved or
     * expired promotion is logged and dropped).
     *
     * @since 1.0.0
     *
     * @param  mixed               $promotion  Candidate.
     * @param  DateTimeInterface  $now        Evaluation time.
     *
     * @return bool
     */
    protected function isUsableCandidate( mixed $promotion, DateTimeInterface $now ): bool
    {
        if ( ! $promotion instanceof Promotion ) {
            return false;
        }

        if ( ! $promotion->exists || null === $promotion->id || ! $promotion->isActiveAt( $now ) ) {
            Log::channel( 'ecommerce' )->warning( 'Dropping promotion candidate that is unsaved or inactive.', [
                'promotion_id' => $promotion->id,
                'key'          => $promotion->key,
            ] );

            return false;
        }

        return true;
    }

    /**
     * Per-customer usage counts for `$candidates`, in one grouped query.
     *
     * @since 1.0.0
     *
     * @param  array<int, Promotion>  $candidates  Candidate promotions.
     * @param  int|null               $customerId  Customer id, if known.
     *
     * @return array<int, int> Promotion id → uses by this customer.
     */
    protected function customerUsageCounts( array $candidates, ?int $customerId ): array
    {
        $limited = array_values( array_filter( $candidates, static fn ( Promotion $p ): bool => null !== $p->usage_limit_per_customer ) );

        if ( null === $customerId || [] === $limited ) {
            return [];
        }

        return PromotionUsage::query()
            ->whereIn( 'promotion_id', array_map( static fn ( Promotion $p ): int => (int) $p->id, $limited ) )
            ->where( 'customer_id', $customerId )
            ->groupBy( 'promotion_id' )
            ->selectRaw( 'promotion_id, COUNT(*) as uses' )
            ->pluck( 'uses', 'promotion_id' )
            ->map( static fn ( mixed $uses ): int => (int) $uses )
            ->all();
    }

    /**
     * Whether `$promotion` has usage left overall and for the customer.
     *
     * @since 1.0.0
     *
     * @param  Promotion         $promotion      Promotion.
     * @param  array<int, int>   $customerUsage  Output of {@see self::customerUsageCounts()}.
     *
     * @return bool
     */
    protected function hasUsageLeft( Promotion $promotion, array $customerUsage ): bool
    {
        if ( null !== $promotion->usage_limit_total && $promotion->times_used >= $promotion->usage_limit_total ) {
            return false;
        }

        return null === $promotion->usage_limit_per_customer
            || ( $customerUsage[ $promotion->id ] ?? 0 ) < $promotion->usage_limit_per_customer;
    }

    /**
     * Whether every condition on `$promotion` passes. Unknown condition
     * types and conditions that throw fail closed.
     *
     * @since 1.0.0
     *
     * @param  Promotion  $promotion  Promotion.
     * @param  Cart       $cart       Cart.
     *
     * @return bool
     */
    protected function conditionsPass( Promotion $promotion, Cart $cart ): bool
    {
        foreach ( $promotion->conditions as $condition ) {
            if ( ! $this->conditions->has( $condition->type ) ) {
                Log::channel( 'ecommerce' )->warning( 'Promotion condition type is not registered; failing closed.', [
                    'promotion_id' => $promotion->id,
                    'type'         => $condition->type,
                ] );

                return false;
            }

            try {
                if ( ! $this->conditions->get( $condition->type )->evaluate( $cart, (array) $condition->config ) ) {
                    return false;
                }
            } catch ( Throwable $e ) {
                Log::channel( 'ecommerce' )->error( 'Promotion condition threw; failing closed.', [
                    'promotion_id' => $promotion->id,
                    'type'         => $condition->type,
                    'exception'    => $e->getMessage(),
                ] );

                return false;
            }
        }

        return true;
    }

    /**
     * Applies every action on `$promotion` to `$ledger`. Unknown action
     * types and actions that throw are skipped.
     *
     * @since 1.0.0
     *
     * @param  Promotion       $promotion  Promotion.
     * @param  Cart            $cart       Cart.
     * @param  DiscountLedger  $ledger     Ledger.
     *
     * @return void
     */
    protected function applyActions( Promotion $promotion, Cart $cart, DiscountLedger $ledger ): void
    {
        $ledger->beginPromotion( $promotion->id );

        foreach ( $promotion->actions as $action ) {
            if ( ! $this->actions->has( $action->type ) ) {
                Log::channel( 'ecommerce' )->warning( 'Promotion action type is not registered; skipping.', [
                    'promotion_id' => $promotion->id,
                    'type'         => $action->type,
                ] );

                continue;
            }

            try {
                $this->actions->get( $action->type )->apply( $cart, $ledger, (array) $action->config );
            } catch ( Throwable $e ) {
                Log::channel( 'ecommerce' )->error( 'Promotion action threw; skipping.', [
                    'promotion_id' => $promotion->id,
                    'type'         => $action->type,
                    'exception'    => $e->getMessage(),
                ] );
            }
        }

        $ledger->endPromotion();
    }
}
