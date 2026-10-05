<?php

/**
 * LicenseKey factory.
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

use ArtisanPackUI\Ecommerce\Models\LicenseKey;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LicenseKey>
 *
 * @since 1.0.0
 */
class LicenseKeyFactory extends Factory
{
    /**
     * @var class-string<LicenseKey>
     */
    protected $model = LicenseKey::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_item_id'     => OrderItem::factory(),
            'digital_file_id'   => null,
            'key'               => strtoupper( implode( '-', str_split( Str::random( 25 ), 5 ) ) ),
            'activations_limit' => 5,
            'activations_count' => 0,
            'expires_at'        => null,
            'is_revoked'        => false,
            'revoked_at'        => null,
            'meta'              => [],
        ];
    }
}
