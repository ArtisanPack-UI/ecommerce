<?php

/**
 * KanbanCardWidget factory.
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

use ArtisanPackUI\Ecommerce\Models\KanbanCardWidget;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<KanbanCardWidget>
 *
 * @since 1.0.0
 */
class KanbanCardWidgetFactory extends Factory
{
    /**
     * @var class-string<KanbanCardWidget>
     */
    protected $model = KanbanCardWidget::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $label = ucfirst( $this->faker->unique()->words( 2, true ) );

        return [
            'key'            => Str::slug( $label ),
            'label'          => $label,
            'default_config' => [],
            'provided_by'    => 'ecommerce',
        ];
    }
}
