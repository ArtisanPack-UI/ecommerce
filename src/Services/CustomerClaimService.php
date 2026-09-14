<?php

/**
 * CustomerClaimService.
 *
 * Handles guest-order claims for a registered customer: rate-limits attempts
 * (default 5/hour per customer, engine spec §3.22), verifies (order number +
 * shipping postal code) against a prior guest order under the customer's
 * email, and — on success — retroactively marks every guest order under that
 * email as `is_claimed = true` and links them to the customer.
 *
 * Fires `ap.ecommerce.customer.orderClaimed` for each newly claimed order.
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

use ArtisanPackUI\Ecommerce\Exceptions\ClaimRateLimitedException;
use ArtisanPackUI\Ecommerce\Exceptions\ClaimVerificationFailedException;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\CustomerClaimAttempt;
use ArtisanPackUI\Ecommerce\Models\Order;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class CustomerClaimService
{
    /**
     * @since 1.0.0
     *
     * @param  ConfigRepository  $config  Injected so callers can override
     *                                     rate-limit settings per-app.
     */
    public function __construct( protected ConfigRepository $config )
    {
    }

    /**
     * Claims prior guest orders for `$customer` when `$orderNumber` and
     * `$postalCode` verify against a guest order under the customer's email.
     *
     * The attempt (success or failure) is always recorded in
     * `customer_claim_attempts`. On success, every un-claimed order under
     * the customer's email is marked `is_claimed = true` and linked to the
     * customer; `ap.ecommerce.customer.orderClaimed` fires per order.
     *
     * @since 1.0.0
     *
     * @param  Customer     $customer     The claiming customer.
     * @param  string       $orderNumber  The order number the customer supplied.
     * @param  string       $postalCode   Shipping postal code the customer supplied.
     * @param  string|null  $ipAddress    Requesting client's IP (audit only).
     *
     * @throws ClaimRateLimitedException      When the customer has exhausted the window.
     * @throws ClaimVerificationFailedException When no matching order exists.
     *
     * @return Collection<int, Order> The orders newly marked claimed.
     */
    public function claim(
        Customer $customer,
        string $orderNumber,
        string $postalCode,
        ?string $ipAddress = null,
    ): Collection {
        $outcome = DB::transaction( function () use ( $customer, $orderNumber, $postalCode, $ipAddress ): array {
            $locked = Customer::query()->lockForUpdate()->findOrFail( $customer->id );

            $this->assertWithinRateLimit( $locked );

            $matched = $this->findMatchingOrder( $locked, $orderNumber, $postalCode );

            if ( null === $matched ) {
                $this->recordAttempt( $locked, $orderNumber, $ipAddress, false );

                return [ 'success' => false, 'customer' => $locked, 'claimed' => new Collection() ];
            }

            $claimed = $this->markGuestOrdersClaimed( $locked );

            $this->recordAttempt( $locked, $orderNumber, $ipAddress, true );

            return [ 'success' => true, 'customer' => $locked, 'claimed' => $claimed ];
        } );

        if ( ! $outcome['success'] ) {
            throw new ClaimVerificationFailedException( $outcome['customer'], $orderNumber );
        }

        foreach ( $outcome['claimed'] as $order ) {
            doAction( 'ap.ecommerce.customer.orderClaimed', $outcome['customer'], $order );
        }

        return $outcome['claimed'];
    }

    /**
     * Throws when the customer has hit the configured FAILED claim attempt
     * cap inside the current rate window. Successful attempts stay on record
     * for audit but do not consume the failure budget — that is the whole
     * point of gating the claim behind a rate limit rather than a total.
     *
     * @since 1.0.0
     *
     * @param  Customer  $customer
     *
     * @throws ClaimRateLimitedException
     *
     * @return void
     */
    protected function assertWithinRateLimit( Customer $customer ): void
    {
        $limit  = $this->claimRateLimit();
        $window = $this->claimRateWindowMinutes();

        $attempts = CustomerClaimAttempt::query()
            ->where( 'customer_id', $customer->id )
            ->where( 'was_success', false )
            ->withinLastMinutes( $window )
            ->count();

        if ( $attempts >= $limit ) {
            throw new ClaimRateLimitedException( $customer, $attempts, $limit );
        }
    }

    /**
     * Looks for a prior UNCLAIMED guest order under the customer's email
     * whose `order_number` and shipping postal code both match.
     *
     * Restricting proof to un-claimed orders enforces the one-time nature of
     * the claim: once a guest order has been claimed (by anyone), it cannot
     * be reused as verification for a second claim.
     *
     * Returns null if the `orders` table has not been migrated yet (Phase 1
     * ships without the orders migration; downstream phases add it).
     *
     * @since 1.0.0
     *
     * @param  Customer  $customer
     * @param  string    $orderNumber
     * @param  string    $postalCode
     *
     * @return Order|null
     */
    protected function findMatchingOrder(
        Customer $customer,
        string $orderNumber,
        string $postalCode,
    ): ?Order {
        if ( ! Schema::hasTable( 'orders' ) ) {
            return null;
        }

        $needle = $this->normalizePostal( $postalCode );

        $candidates = Order::query()
            ->whereRaw( 'LOWER(email) = ?', [ strtolower( $customer->email ) ] )
            ->where( 'order_number', $orderNumber )
            ->where( 'is_claimed', false )
            ->get();

        foreach ( $candidates as $candidate ) {
            $shipping = $candidate->shipping_address;
            if ( is_string( $shipping ) ) {
                $decoded  = json_decode( $shipping, true );
                $shipping = is_array( $decoded ) ? $decoded : [];
            }

            if ( ! is_array( $shipping ) ) {
                continue;
            }

            $candidatePostal = $shipping['postal_code'] ?? null;
            if ( ! is_string( $candidatePostal ) ) {
                continue;
            }

            if ( $this->normalizePostal( $candidatePostal ) === $needle ) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Marks every un-claimed order under the customer's email as claimed and
     * links them to the customer id. Returns the freshly updated rows.
     *
     * @since 1.0.0
     *
     * @param  Customer  $customer
     *
     * @return Collection<int, Order>
     */
    protected function markGuestOrdersClaimed( Customer $customer ): Collection
    {
        if ( ! Schema::hasTable( 'orders' ) ) {
            return new Collection();
        }

        $orders = Order::query()
            ->whereRaw( 'LOWER(email) = ?', [ strtolower( $customer->email ) ] )
            ->where( 'is_claimed', false )
            ->lockForUpdate()
            ->get();

        foreach ( $orders as $order ) {
            $order->is_claimed  = true;
            $order->customer_id = $customer->id;
            $order->save();
        }

        return $orders;
    }

    /**
     * Records a claim attempt row for auditing and rate limiting.
     *
     * @since 1.0.0
     *
     * @param  Customer     $customer
     * @param  string       $orderNumber
     * @param  string|null  $ipAddress
     * @param  bool         $wasSuccess
     *
     * @return CustomerClaimAttempt
     */
    protected function recordAttempt(
        Customer $customer,
        string $orderNumber,
        ?string $ipAddress,
        bool $wasSuccess,
    ): CustomerClaimAttempt {
        $attempt = new CustomerClaimAttempt( [
            'customer_id'  => $customer->id,
            'order_number' => $orderNumber,
            'ip_address'   => $ipAddress,
            'was_success'  => $wasSuccess,
            'created_at'   => Carbon::now(),
        ] );
        $attempt->save();

        return $attempt;
    }

    /**
     * @since 1.0.0
     *
     * @return int
     */
    protected function claimRateLimit(): int
    {
        $limit = (int) $this->config->get( 'artisanpack.ecommerce.customers.claim_rate_limit', 5 );

        return $limit > 0 ? $limit : 5;
    }

    /**
     * @since 1.0.0
     *
     * @return int
     */
    protected function claimRateWindowMinutes(): int
    {
        $window = (int) $this->config->get(
            'artisanpack.ecommerce.customers.claim_rate_window_minutes',
            60,
        );

        return $window > 0 ? $window : 60;
    }

    /**
     * Normalizes a postal code for a case- and whitespace-insensitive compare.
     *
     * @since 1.0.0
     *
     * @param  string  $postal
     *
     * @return string
     */
    protected function normalizePostal( string $postal ): string
    {
        return strtoupper( preg_replace( '/\s+/', '', $postal ) ?? $postal );
    }
}
