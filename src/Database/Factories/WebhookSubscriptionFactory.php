<?php

/**
 * WebhookSubscription factory.
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

use ArtisanPackUI\Ecommerce\Models\WebhookSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebhookSubscription>
 *
 * @since 1.0.0
 */
class WebhookSubscriptionFactory extends Factory
{
    /**
     * @var class-string<WebhookSubscription>
     */
    protected $model = WebhookSubscription::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name'   => $this->faker->words( 2, true ),
            'url'    => 'https://hooks.example.test/' . $this->faker->unique()->slug( 2 ),
            'secret' => Str::random( 48 ),
            'events' => [ 'order.refunded' ],
        ];
    }

    /**
     * An inactive (auto-disabled) subscription.
     *
     * @since 1.0.0
     *
     * @return static
     */
    public function inactive(): static
    {
        return $this->state( [ 'is_active' => false ] );
    }

    /**
     * Subscribes to the given events.
     *
     * @since 1.0.0
     *
     * @param  array<int, string>  $events  Wire event names.
     *
     * @return static
     */
    public function events( array $events ): static
    {
        return $this->state( [ 'events' => $events ] );
    }
}
