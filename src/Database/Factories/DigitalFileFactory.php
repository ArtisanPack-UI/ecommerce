<?php

/**
 * DigitalFile factory.
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

use ArtisanPackUI\Ecommerce\Models\DigitalFile;
use ArtisanPackUI\Ecommerce\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DigitalFile>
 *
 * @since 1.0.0
 */
class DigitalFileFactory extends Factory
{
    /**
     * @var class-string<DigitalFile>
     */
    protected $model = DigitalFile::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id'         => Product::factory(),
            'product_variant_id' => null,
            'media_id'           => null,
            'disk'               => 'local',
            'path'               => 'digital/' . $this->faker->uuid() . '.pdf',
            'label'              => ucfirst( $this->faker->words( 3, true ) ),
            'version'            => '1.0.0',
            'is_streaming_only'  => false,
            'checksum_sha256'    => null,
        ];
    }

    /**
     * A file that may only be streamed, never downloaded.
     *
     * @since 1.0.0
     *
     * @return static
     */
    public function streamingOnly(): static
    {
        return $this->state( fn (): array => [ 'is_streaming_only' => true ] );
    }
}
