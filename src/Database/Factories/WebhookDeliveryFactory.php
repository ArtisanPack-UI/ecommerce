<?php

/**
 * WebhookDelivery factory.
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

use ArtisanPackUI\Ecommerce\Models\WebhookDelivery;
use ArtisanPackUI\Ecommerce\Models\WebhookSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebhookDelivery>
 *
 * @since 1.0.0
 */
class WebhookDeliveryFactory extends Factory
{
    /**
     * @var class-string<WebhookDelivery>
     */
    protected $model = WebhookDelivery::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $payload = [
            'id'         => (string) Str::uuid(),
            'event'      => 'order.refunded',
            'created_at' => Carbon::now()->toIso8601String(),
            'data'       => [],
        ];

        return [
            'subscription_id' => WebhookSubscription::factory(),
            'event'           => 'order.refunded',
            'payload_hash'    => hash( 'sha256', (string) json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
            'payload'         => $payload,
            'next_retry_at'   => Carbon::now(),
        ];
    }
}
