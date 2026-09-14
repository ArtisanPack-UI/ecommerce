<?php

/**
 * LinkCustomerOnUserVerified listener.
 *
 * Reacts to Laravel's {@see \Illuminate\Auth\Events\Verified} event by
 * back-filling `customers.user_id` for the verified user's email, per
 * engine spec §5.8 / §3.22.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Listeners;

use ArtisanPackUI\Ecommerce\Services\CustomerService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class LinkCustomerOnUserVerified
{
    /**
     * @since 1.0.0
     *
     * @param  CustomerService  $customers
     */
    public function __construct( protected CustomerService $customers )
    {
    }

    /**
     * @since 1.0.0
     *
     * @param  Verified  $event
     *
     * @return void
     */
    public function handle( Verified $event ): void
    {
        $user = $event->user;

        if ( ! $user instanceof Authenticatable ) {
            return;
        }

        $this->customers->linkUser( $user );
    }
}
