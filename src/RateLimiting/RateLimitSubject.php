<?php

/**
 * RateLimitSubject.
 *
 * Who an in-process call counts against (engine issue #180). The engine's
 * rate-limit policies read a request: the cart token, the signed-in user,
 * the client IP. Under Livewire every call is `POST /livewire/update`, so a
 * component describes the subject explicitly and
 * {@see EcommerceRateLimiter} turns it into the request the policy reads.
 * The REST routes and an in-process caller therefore share the same
 * buckets.
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

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Customer;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class RateLimitSubject
{
    /**
     * @since 1.0.0
     *
     * @param  string|null           $cartToken  Cart the call is about.
     * @param  Authenticatable|null  $user       Signed-in shopper.
     * @param  string|null           $ip         Client IP (defaults to the current request's).
     * @param  array<string, mixed>  $input      Input the policy reads (`email`, `key`, …).
     */
    private function __construct(
        public readonly ?string $cartToken = null,
        public readonly ?Authenticatable $user = null,
        public readonly ?string $ip = null,
        public readonly array $input = [],
    ) {
    }

    /**
     * A call about `$cart` (per-cart limits).
     *
     * @since 1.0.0
     *
     * @param  Cart|string  $cart  Cart or its token.
     * @param  string|null  $ip    Client IP.
     *
     * @return self
     */
    public static function cart( Cart|string $cart, ?string $ip = null ): self
    {
        return new self( $cart instanceof Cart ? (string) $cart->token : $cart, null, $ip );
    }

    /**
     * A call by a signed-in user or a customer (per-customer limits).
     *
     * @since 1.0.0
     *
     * @param  Authenticatable|Customer  $who  User, or customer record.
     * @param  string|null               $ip   Client IP.
     *
     * @return self
     */
    public static function customer( Authenticatable|Customer $who, ?string $ip = null ): self
    {
        $user = $who instanceof Customer ? new GenericUser( [ 'id' => 'customer:' . $who->getKey() ] ) : $who;

        return new self( null, $user, $ip );
    }

    /**
     * A call counted only by IP.
     *
     * @since 1.0.0
     *
     * @param  string  $ip  Client IP.
     *
     * @return self
     */
    public static function ip( string $ip ): self
    {
        return new self( null, null, $ip );
    }

    /**
     * The same subject with extra input the policy reads.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $input  Input.
     *
     * @return self
     */
    public function with( array $input ): self
    {
        return new self( $this->cartToken, $this->user, $this->ip, array_replace( $this->input, $input ) );
    }

    /**
     * The same subject with a cart as well.
     *
     * @since 1.0.0
     *
     * @param  Cart|string  $cart  Cart or its token.
     *
     * @return self
     */
    public function forCart( Cart|string $cart ): self
    {
        return new self( $cart instanceof Cart ? (string) $cart->token : $cart, $this->user, $this->ip, $this->input );
    }

    /**
     * The request a rate-limit policy reads for this subject.
     *
     * @since 1.0.0
     *
     * @return Request
     */
    public function toRequest(): Request
    {
        $ip = $this->ip ?? ( app()->bound( 'request' ) ? app( 'request' )->ip() : null ) ?? '127.0.0.1';

        $request = Request::create( '/', 'POST', $this->input, server: [ 'REMOTE_ADDR' => $ip ] );

        if ( null !== $this->cartToken ) {
            $request->headers->set( 'X-Cart-Token', $this->cartToken );
        }

        $user = $this->user;
        $request->setUserResolver( static fn () => $user );

        return $request;
    }
}
