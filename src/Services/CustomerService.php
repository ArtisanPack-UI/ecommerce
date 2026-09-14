<?php

/**
 * CustomerService.
 *
 * Encapsulates the customer-side lookup and linking logic for the hybrid
 * guest-plus-registered flow described in engine spec §3.22:
 *
 * - {@see findOrCreateForEmail()} — returns the row for an email (creating a
 *   guest snapshot on first sighting) and fires
 *   `ap.ecommerce.customer.registered` when the row is new.
 * - {@see linkUser()} — back-fills `customers.user_id` on verified-email
 *   registration and fires `ap.ecommerce.customer.userLinked`.
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

use ArtisanPackUI\Ecommerce\Models\Customer;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class CustomerService
{
    /**
     * Returns the customer row for `$email`, creating a guest snapshot on
     * first sighting. Fires `ap.ecommerce.customer.registered` when the row
     * is newly created.
     *
     * Email matching is case-insensitive: the stored `email` is normalized to
     * lowercase.
     *
     * @since 1.0.0
     *
     * @param  string               $email       Email to look up (or create).
     * @param  array<string, mixed> $attributes  Extra column values to seed on create.
     *
     * @return Customer
     */
    public function findOrCreateForEmail( string $email, array $attributes = [] ): Customer
    {
        $normalized = $this->normalizeEmail( $email );

        return DB::transaction( function () use ( $normalized, $attributes ): Customer {
            $existing = Customer::query()
                ->whereRaw( 'LOWER(email) = ?', [ $normalized ] )
                ->lockForUpdate()
                ->first();

            if ( null !== $existing ) {
                return $existing;
            }

            $customer = new Customer( array_merge( $attributes, [ 'email' => $normalized ] ) );
            $customer->save();

            doAction( 'ap.ecommerce.customer.registered', $customer );

            return $customer;
        } );
    }

    /**
     * Back-fills `customers.user_id` on verified-email registration.
     *
     * Idempotent: if the customer is already linked to `$user`, this is a
     * no-op. If the row is linked to a different user, this is also a no-op
     * (spoof-registration guard) — the caller is responsible for creating a
     * fresh customer row under a different email for the new user if needed.
     *
     * Fires `ap.ecommerce.customer.userLinked` when the link is written.
     *
     * @since 1.0.0
     *
     * @param  Authenticatable  $user  Auth user with `email` and a scalar key.
     *
     * @return Customer|null The linked (or already-linked) customer row, or
     *                       null when the user has no email or the row is
     *                       already claimed by a different user.
     */
    public function linkUser( Authenticatable $user ): ?Customer
    {
        $email = $this->extractEmail( $user );
        if ( null === $email ) {
            return null;
        }

        $userId   = (int) $user->getAuthIdentifier();
        $customer = $this->findOrCreateForEmail( $email );

        if ( null !== $customer->user_id ) {
            return $customer->user_id === $userId ? $customer : null;
        }

        $customer->user_id = $userId;
        $customer->save();

        doAction( 'ap.ecommerce.customer.userLinked', $customer, $user );

        return $customer;
    }

    /**
     * Normalizes an email to lowercase for consistent lookup.
     *
     * @since 1.0.0
     *
     * @param  string  $email
     *
     * @return string
     */
    protected function normalizeEmail( string $email ): string
    {
        return strtolower( trim( $email ) );
    }

    /**
     * Extracts the email from an authenticatable, tolerating missing fields.
     *
     * @since 1.0.0
     *
     * @param  Authenticatable  $user
     *
     * @return string|null
     */
    protected function extractEmail( Authenticatable $user ): ?string
    {
        $email = null;

        if ( isset( $user->email ) && is_string( $user->email ) ) {
            $email = $user->email;
        } elseif ( method_exists( $user, 'getEmailForVerification' ) ) {
            $candidate = $user->getEmailForVerification();
            if ( is_string( $candidate ) ) {
                $email = $candidate;
            }
        }

        if ( null === $email || '' === trim( $email ) ) {
            return null;
        }

        return $email;
    }
}
