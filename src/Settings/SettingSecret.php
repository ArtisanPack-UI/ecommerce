<?php

/**
 * SettingSecret.
 *
 * A credential that lives only in env / config — a gateway key, a webhook
 * signing secret — listed on a settings group so an admin can see whether
 * it is configured (engine issue #145). The value itself is never stored
 * in `ecommerce_settings`, returned by the API, or editable.
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
final class SettingSecret
{
    /**
     * @since 1.0.0
     *
     * @param  string       $configKey  Full config path, e.g. `artisanpack.ecommerce.gateways.stripe.secret_key`.
     * @param  string       $group      Group key.
     * @param  string       $label      Translated label.
     * @param  string|null  $envName    Environment variable that sets it, shown as a hint.
     */
    public function __construct(
        public readonly string $configKey,
        public readonly string $group,
        public readonly string $label,
        public readonly ?string $envName = null,
    ) {
    }

    /**
     * Whether a non-empty value is configured.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function isConfigured(): bool
    {
        $value = config( $this->configKey );

        return null !== $value && '' !== $value && [] !== $value;
    }

    /**
     * Serializes the secret's status — never its value.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key'        => $this->configKey,
            'label'      => $this->label,
            'env'        => $this->envName,
            'configured' => $this->isConfigured(),
        ];
    }
}
