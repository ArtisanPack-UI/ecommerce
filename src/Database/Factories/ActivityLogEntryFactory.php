<?php

/**
 * ActivityLogEntry factory.
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

use ArtisanPackUI\Ecommerce\Models\ActivityLogEntry;
use ArtisanPackUI\Ecommerce\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<ActivityLogEntry>
 *
 * @since 1.0.0
 */
class ActivityLogEntryFactory extends Factory
{
    /**
     * @var class-string<ActivityLogEntry>
     */
    protected $model = ActivityLogEntry::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subject_type'  => ( new Product() )->getMorphClass(),
            'subject_id'    => Product::factory(),
            'actor_user_id' => null,
            'event_type'    => 'product.updated',
            'payload'       => [],
        ];
    }

    /**
     * State: the entry belongs to `$subject`.
     *
     * @since 1.0.0
     *
     * @param  Model  $subject  Subject.
     *
     * @return static
     */
    public function forSubject( Model $subject ): static
    {
        return $this->state( fn () => [
            'subject_type' => $subject->getMorphClass(),
            'subject_id'   => $subject->getKey(),
        ] );
    }
}
