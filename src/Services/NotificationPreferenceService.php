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

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class NotificationPreferenceService
{
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

        return $preference?->is_enabled ?? $this->default( $customer, $category );
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
                    'is_enabled' => $locked || ( $stored->get( $channel . '|' . $category )?->is_enabled ?? $this->default( $customer, $category ) ),
                    'is_locked'  => $locked,
                ];
            }
        }

        return $rows;
    }

    /**
     * Stores `$preferences` (`[ { channel, category, is_enabled } ]`).
     *
     * @since 1.0.0
     *
     * @param  Customer                                                              $customer     Customer.
     * @param  array<int, array{channel: string, category: string, is_enabled: bool}>  $preferences  Changes.
     *
     * @return array<int, array{channel: string, category: string, is_enabled: bool, is_locked: bool}>
     */
    public function update( Customer $customer, array $preferences ): array
    {
        foreach ( $preferences as $preference ) {
            CustomerNotificationPreference::query()->updateOrCreate(
                [ 'customer_id' => $customer->id, 'channel' => $preference['channel'], 'category' => $preference['category'] ],
                [ 'is_enabled' => (bool) $preference['is_enabled'] ],
            );
        }

        return $this->all( $customer );
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
        return 'marketing' === $category ? (bool) $customer->accepts_marketing : true;
    }
}
