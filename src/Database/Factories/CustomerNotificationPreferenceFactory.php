<?php

/**
 * CustomerNotificationPreference factory.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Database\Factories;

use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\CustomerNotificationPreference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerNotificationPreference>
 *
 * @since 1.0.0
 */
class CustomerNotificationPreferenceFactory extends Factory
{
    /**
     * @var class-string<CustomerNotificationPreference>
     */
    protected $model = CustomerNotificationPreference::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'channel'     => 'mail',
            'category'    => 'marketing',
            'is_enabled'  => true,
        ];
    }
}
