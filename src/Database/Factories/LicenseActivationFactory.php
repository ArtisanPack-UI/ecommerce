<?php

/**
 * LicenseActivation factory.
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

use ArtisanPackUI\Ecommerce\Models\LicenseActivation;
use ArtisanPackUI\Ecommerce\Models\LicenseKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LicenseActivation>
 *
 * @since 1.0.0
 */
class LicenseActivationFactory extends Factory
{
    /**
     * @var class-string<LicenseActivation>
     */
    protected $model = LicenseActivation::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'license_key_id'      => LicenseKey::factory(),
            'machine_fingerprint' => $this->faker->sha1(),
            'activated_at'        => now(),
            'last_seen_at'        => now(),
            'ip_address'          => $this->faker->ipv4(),
        ];
    }
}
