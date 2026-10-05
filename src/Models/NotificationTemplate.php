<?php

/**
 * NotificationTemplate model.
 *
 * The store owner's editable copy of one catalog notification on one
 * channel, in one locale. `subject` and `body` are Twig sources rendered
 * inside the sandbox ({@see \ArtisanPackUI\Ecommerce\Notifications\NotificationTemplateRenderer});
 * `variables` mirrors the catalog definition so editors can autocomplete,
 * and `preview_data` is the sample context for the live preview.
 * Engine spec §3.30, parent plan §14.2.
 *
 * Named after the table rather than the contract: the definition a row
 * overrides is {@see \ArtisanPackUI\Ecommerce\Contracts\NotificationTemplate}.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Models;

use ArtisanPackUI\Ecommerce\Contracts\NotificationTemplate as NotificationTemplateDefinition;
use ArtisanPackUI\Ecommerce\Database\Factories\NotificationTemplateFactory;
use ArtisanPackUI\Ecommerce\Registries\NotificationTemplateRegistry;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * NotificationTemplate Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int                   $id
 * @property string                $key
 * @property string                $channel
 * @property string                $locale
 * @property string|null           $subject
 * @property string                $body
 * @property array<int, string>    $variables
 * @property array<string, mixed>  $preview_data
 * @property bool                  $is_active
 * @property Carbon|null           $created_at
 * @property Carbon|null           $updated_at
 */
class NotificationTemplate extends Model
{
    use HasFactory;

    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'ecommerce_notification_templates';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'key',
        'channel',
        'locale',
        'subject',
        'body',
        'variables',
        'preview_data',
        'is_active',
    ];

    /**
     * @since 1.0.0
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'locale'       => 'en',
        'variables'    => '[]',
        'preview_data' => '{}',
        'is_active'    => true,
    ];

    /**
     * The catalog definition this row overrides, if still registered.
     *
     * @since 1.0.0
     *
     * @return NotificationTemplateDefinition|null
     */
    public function definition(): ?NotificationTemplateDefinition
    {
        $registry = app( NotificationTemplateRegistry::class );

        return $registry->has( $this->key ) ? $registry->get( $this->key ) : null;
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'variables'    => 'array',
            'preview_data' => 'array',
            'is_active'    => 'boolean',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return NotificationTemplateFactory
     */
    protected static function newFactory(): NotificationTemplateFactory
    {
        return NotificationTemplateFactory::new();
    }
}
