<?php

/**
 * NotificationPreferenceService.
 *
 * Reads and writes customers' per-channel, per-category notification
 * opt-ins (`customer_notification_preferences`, engine spec §3.30, parent
 * plan §14.3).
 *
 * Without a row, a category is on — except `marketing`, which follows the
 * customer's `accepts_marketing` consent. `transactional` notifications
 * always send: an opt-out row is stored for the audit trail but never
 * consulted.
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
use ArtisanPackUI\Ecommerce\Models\CustomerNotificationPreference;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class NotificationPreferenceService
{
    /**
     * The category that needs the customer's marketing consent.
     *
     * @since 1.3.0
     *
     * @var string
     */
    public const CATEGORY_MARKETING = 'marketing';

    /**
     * Channels customers can set preferences for.
     *
     * @since 1.0.0
     *
     * @return array<int, string>
     */
    public function channels(): array
    {
        return array_values( (array) config( 'artisanpack.ecommerce.notifications.preference_channels', [ 'mail' ] ) );
    }

    /**
     * Whether `$customer` should receive a `$category` notification on
     * `$channel`.
     *
     * @since 1.0.0
     *
     * @param  Customer  $customer  Customer.
     * @param  string    $channel   Channel.
     * @param  string    $category  Category.
     *
     * @return bool
     */
    public function allows( Customer $customer, string $channel, string $category ): bool
    {
        if ( CustomerNotificationPreference::CATEGORY_TRANSACTIONAL === $category ) {
            return true;
        }

        $preference = $customer->notificationPreferences()
            ->where( 'channel', $channel )
            ->where( 'category', $category )
            ->first();

        return $this->effective( $customer, $preference, $category );
    }

    /**
     * Whether mail sent straight to `$email` (a guest order's address) may
     * carry a `$category` notification on `$channel`: the preferences of
     * the customer record with that email, if there is one. Guests who
     * unsubscribe get such a record ({@see self::unsubscribe()}).
     *
     * @since 1.0.0
     *
     * @param  string  $email     Address.
     * @param  string  $channel   Channel.
     * @param  string  $category  Category.
     *
     * @return bool
     */
    public function allowsEmail( string $email, string $channel, string $category ): bool
    {
        if ( CustomerNotificationPreference::CATEGORY_TRANSACTIONAL === $category ) {
            return true;
        }

        $customer = Customer::query()->where( 'email', mb_strtolower( trim( $email ) ) )->first();

        return null === $customer || $this->allows( $customer, $channel, $category );
    }

    /**
     * Turns `$category` off on `$channel` for whoever has `$email`, creating
     * a customer record for a guest so the choice sticks. Transactional
     * mail can't be turned off.
     *
     * @since 1.0.0
     *
     * @param  string  $email     Address.
     * @param  string  $channel   Channel.
     * @param  string  $category  Category.
     *
     * @return bool Whether anything was turned off.
     */
    public function unsubscribe( string $email, string $channel, string $category ): bool
    {
        if ( CustomerNotificationPreference::CATEGORY_TRANSACTIONAL === $category || ! in_array( $category, CustomerNotificationPreference::CATEGORIES, true ) || ! in_array( $channel, $this->channels(), true ) ) {
            return false;
        }

        $customer = app( CustomerService::class )->findOrCreateForEmail( $email );

        $this->update( $customer, [ [ 'channel' => $channel, 'category' => $category, 'is_enabled' => false ] ] );

        return true;
    }

    /**
     * A signed, non-expiring link that turns `$category` off for `$email`
     * (null for transactional mail, which can't be turned off).
     *
     * @since 1.0.0
     *
     * @param  string  $email     Address.
     * @param  string  $channel   Channel.
     * @param  string  $category  Category.
     *
     * @return string|null
     */
    public function unsubscribeUrl( string $email, string $channel, string $category ): ?string
    {
        if ( CustomerNotificationPreference::CATEGORY_TRANSACTIONAL === $category || '' === trim( $email ) ) {
            return null;
        }

        return URL::signedRoute( 'ecommerce.notifications.unsubscribe', [
            'email'    => mb_strtolower( trim( $email ) ),
            'channel'  => $channel,
            'category' => $category,
        ] );
    }

    /**
     * Every channel × category with its effective value.
     *
     * @since 1.0.0
     *
     * @param  Customer  $customer  Customer.
     *
     * @return array<int, array{channel: string, category: string, is_enabled: bool, is_locked: bool}>
     */
    public function all( Customer $customer ): array
    {
        $stored = $customer->notificationPreferences()->get()->keyBy( fn ( CustomerNotificationPreference $row ): string => $row->channel . '|' . $row->category );
        $rows   = [];

        foreach ( $this->channels() as $channel ) {
            foreach ( CustomerNotificationPreference::CATEGORIES as $category ) {
                $locked = CustomerNotificationPreference::CATEGORY_TRANSACTIONAL === $category;

                $rows[] = [
                    'channel'    => $channel,
                    'category'   => $category,
                    'is_enabled' => $locked || $this->effective( $customer, $stored->get( $channel . '|' . $category ), $category ),
                    'is_locked'  => $locked,
                ];
            }
        }

        return $rows;
    }

    /**
     * Stores `$preferences` (`[ { channel, category, is_enabled } ]`).
     *
     * Marketing notifications need the customer's marketing consent
     * (`accepts_marketing`): turning `marketing` on for a customer who
     * hasn't given it is refused before anything is stored. Turning it off
     * is always allowed.
     *
     * @since 1.0.0
     *
     * @param  Customer                                                              $customer     Customer.
     * @param  array<int, array{channel: string, category: string, is_enabled: bool}>  $preferences  Changes.
     *
     * @throws ValidationException When a preference turns marketing on without consent.
     *
     * @return array<int, array{channel: string, category: string, is_enabled: bool, is_locked: bool}>
     */
    public function update( Customer $customer, array $preferences ): array
    {
        if ( ! (bool) $customer->accepts_marketing ) {
            foreach ( $preferences as $preference ) {
                if ( self::CATEGORY_MARKETING === $preference['category'] && (bool) $preference['is_enabled'] ) {
                    throw ValidationException::withMessages( [
                        'preferences' => __( 'Turn on marketing consent before turning on marketing notifications.' ),
                    ] );
                }
            }
        }

        foreach ( $preferences as $preference ) {
            CustomerNotificationPreference::query()->updateOrCreate(
                [ 'customer_id' => $customer->id, 'channel' => $preference['channel'], 'category' => $preference['category'] ],
                [ 'is_enabled' => (bool) $preference['is_enabled'] ],
            );
        }

        return $this->all( $customer );
    }

    /**
     * Stores marketing as off on every channel for a customer who withdrew
     * their marketing consent, as a record of the withdrawal. The consent
     * already gates marketing ({@see self::effective()}); these rows keep it
     * off if consent is given again later, until the customer turns it on.
     *
     * @since 1.3.0
     *
     * @param  Customer  $customer  Customer.
     *
     * @return void
     */
    public function withdrawMarketing( Customer $customer ): void
    {
        foreach ( $this->channels() as $channel ) {
            CustomerNotificationPreference::query()->updateOrCreate(
                [ 'customer_id' => $customer->id, 'channel' => $channel, 'category' => self::CATEGORY_MARKETING ],
                [ 'is_enabled' => false ],
            );
        }
    }

    /**
     * The effective value of `$category` for `$customer`, given its stored
     * row (if any). Marketing needs the customer's consent: a stored row
     * can turn it off but can't turn it on without `accepts_marketing`.
     *
     * @since 1.3.0
     *
     * @param  Customer                             $customer    Customer.
     * @param  CustomerNotificationPreference|null  $preference  Stored row.
     * @param  string                               $category    Category.
     *
     * @return bool
     */
    protected function effective( Customer $customer, ?CustomerNotificationPreference $preference, string $category ): bool
    {
        if ( self::CATEGORY_MARKETING === $category ) {
            return (bool) $customer->accepts_marketing && ( $preference?->is_enabled ?? true );
        }

        return $preference?->is_enabled ?? $this->default( $customer, $category );
    }

    /**
     * The value of a category with no stored row.
     *
     * @since 1.0.0
     *
     * @param  Customer  $customer  Customer.
     * @param  string    $category  Category.
     *
     * @return bool
     */
    protected function default( Customer $customer, string $category ): bool
    {
        return self::CATEGORY_MARKETING === $category ? (bool) $customer->accepts_marketing : true;
    }
}
