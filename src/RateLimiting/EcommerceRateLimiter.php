<?php

/**
 * EcommerceRateLimiter.
 *
 * Applies the engine's named rate-limit policies (engine spec §11.3). The
 * HTTP middleware ({@see \ArtisanPackUI\Ecommerce\Http\Middleware\RateLimitEcommerce})
 * uses it for routes; in-process callers such as Livewire storefronts call
 * {@see self::attempt()} with a {@see RateLimitSubject} (engine issue #180):
 *
 * ```php
 * app( EcommerceRateLimiter::class )->attempt(
 *     'ecommerce.coupon.attempt',
 *     RateLimitSubject::cart( $cart ),
 *     fn () => $carts->applyCoupon( $cart, $code ),
 * );
 * ```
 *
 * Both share the same buckets, so a shopper can't dodge a limit by
 * switching between the REST API and the Livewire storefront.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\RateLimiting;

use ArtisanPackUI\Ecommerce\Exceptions\RateLimitExceededException;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use InvalidArgumentException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class EcommerceRateLimiter
{
    /**
     * @since 1.0.0
     *
     * @param  RateLimiter  $limiter  Laravel's rate limiter.
     */
    public function __construct( protected RateLimiter $limiter )
    {
    }

    /**
     * Runs `$callback` if `$subject` is within `$policy`, counting the
     * attempt; otherwise throws without running it.
     *
     * @since 1.0.0
     *
     * @template T
     *
     * @param  string            $policy    Policy name (`ecommerce.cart.mutate`, …).
     * @param  RateLimitSubject  $subject   Who the call counts against.
     * @param  callable(): T     $callback  The call.
     *
     * @throws RateLimitExceededException When the subject is over a limit.
     *
     * @return T
     */
    public function attempt( string $policy, RateLimitSubject $subject, callable $callback ): mixed
    {
        $request  = $subject->toRequest();
        $exceeded = $this->exceeded( $policy, $request );

        if ( null !== $exceeded ) {
            throw new RateLimitExceededException( $policy, $exceeded['retry_after'] );
        }

        $this->hit( $policy, $request );

        return $callback();
    }

    /**
     * The first limit of `$policy` that `$request` is over, or null.
     *
     * @since 1.0.0
     *
     * @param  string   $policy   Policy name.
     * @param  Request  $request  Request the policy reads.
     *
     * @return array{limit: Limit, key: string, retry_after: int}|null
     */
    public function exceeded( string $policy, Request $request ): ?array
    {
        foreach ( $this->limitsFor( $policy, $request ) as $limit ) {
            $key = $this->keyFor( $policy, $limit );

            if ( $this->limiter->tooManyAttempts( $key, $limit->maxAttempts ) ) {
                return [ 'limit' => $limit, 'key' => $key, 'retry_after' => $this->limiter->availableIn( $key ) ];
            }
        }

        return null;
    }

    /**
     * Counts one attempt against every limit of `$policy`.
     *
     * @since 1.0.0
     *
     * @param  string   $policy   Policy name.
     * @param  Request  $request  Request the policy reads.
     *
     * @return void
     */
    public function hit( string $policy, Request $request ): void
    {
        foreach ( $this->limitsFor( $policy, $request ) as $limit ) {
            $this->limiter->hit( $this->keyFor( $policy, $limit ), $limit->decaySeconds );
        }
    }

    /**
     * The limits `$policy` applies to `$request`.
     *
     * @since 1.0.0
     *
     * @param  string   $policy   Policy name.
     * @param  Request  $request  Request.
     *
     * @throws InvalidArgumentException When the policy isn't registered.
     *
     * @return array<int, Limit>
     */
    public function limitsFor( string $policy, Request $request ): array
    {
        $resolver = RateLimiterFacade::limiter( $policy );

        if ( null === $resolver ) {
            throw new InvalidArgumentException( sprintf(
                'Ecommerce rate-limit policy "%s" is not registered.',
                $policy,
            ) );
        }

        $result = $resolver( $request );

        if ( $result instanceof Limit ) {
            return [ $result ];
        }

        return is_array( $result )
            ? array_values( array_filter( $result, static fn ( mixed $item ): bool => $item instanceof Limit ) )
            : [];
    }

    /**
     * Cache key of one limit.
     *
     * @since 1.0.0
     *
     * @param  string  $policy  Policy name.
     * @param  Limit   $limit   Limit.
     *
     * @return string
     */
    public function keyFor( string $policy, Limit $limit ): string
    {
        return $policy . '|' . $limit->key;
    }
}
