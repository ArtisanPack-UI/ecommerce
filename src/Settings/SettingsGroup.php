<?php

/**
 * SettingsGroup.
 *
 * A group of settings shown on one admin screen (engine issue #145):
 * `general`, `checkout`, `tax`, and so on. Satellites add their own.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Settings;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class SettingsGroup
{
    /**
     * @since 1.0.0
     *
     * @param  string       $key          Group key (URL-safe).
     * @param  string       $label        Translated label.
     * @param  int          $position     Sort order.
     * @param  string|null  $description  Translated help text.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly int $position = 100,
        public readonly ?string $description = null,
    ) {
    }

    /**
     * Serializes the group for an API response.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key'         => $this->key,
            'label'       => $this->label,
            'description' => $this->description,
            'position'    => $this->position,
        ];
    }
}
