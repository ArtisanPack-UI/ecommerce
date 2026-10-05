<?php

/**
 * NotificationTemplate factory.
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

use ArtisanPackUI\Ecommerce\Models\NotificationTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationTemplate>
 *
 * @since 1.0.0
 */
class NotificationTemplateFactory extends Factory
{
    /**
     * @var class-string<NotificationTemplate>
     */
    protected $model = NotificationTemplate::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key'          => 'test.' . $this->faker->unique()->slug( 2 ),
            'channel'      => 'mail',
            'locale'       => 'en',
            'subject'      => 'Order {{ Order.number }}',
            'body'         => '<p>Hello {{ Order.customer.name }}</p>',
            'variables'    => [ 'Order.number', 'Order.customer.name' ],
            'preview_data' => [ 'Order' => [ 'number' => 'A1B2C3D4', 'customer' => [ 'name' => 'Ada' ] ] ],
            'is_active'    => true,
        ];
    }
}
