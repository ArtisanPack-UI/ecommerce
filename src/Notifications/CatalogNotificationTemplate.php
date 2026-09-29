<?php

/**
 * CatalogNotificationTemplate.
 *
 * A data-defined {@see NotificationTemplate}: every value is passed in at
 * construction. The engine's catalog ({@see NotificationCatalog}) is made
 * of these; satellites can use it too rather than writing a class per
 * template.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Notifications;

use ArtisanPackUI\Ecommerce\Contracts\NotificationTemplate;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class CatalogNotificationTemplate implements NotificationTemplate
{
    /**
     * @since 1.0.0
     *
     * @param  string                $key             Registry key.
     * @param  string                $label           Editor label.
     * @param  string                $category        Preference category.
     * @param  array<int, string>    $variables       Declared variable paths.
     * @param  array<string, mixed>  $previewData     Sample context.
     * @param  string|null           $defaultSubject  Default subject source.
     * @param  string                $defaultBody     Default body source.
     * @param  string                $channel         Delivery channel.
     */
    public function __construct(
        protected string $key,
        protected string $label,
        protected string $category,
        protected array $variables,
        protected array $previewData,
        protected ?string $defaultSubject,
        protected string $defaultBody,
        protected string $channel = 'mail',
    ) {
    }

    /**
     * @since 1.0.0
     *
     * @return string
     */
    public function key(): string
    {
        return $this->key;
    }

    /**
     * @since 1.0.0
     *
     * @return string
     */
    public function label(): string
    {
        return $this->label;
    }

    /**
     * @since 1.0.0
     *
     * @return string
     */
    public function channel(): string
    {
        return $this->channel;
    }

    /**
     * @since 1.0.0
     *
     * @return string
     */
    public function category(): string
    {
        return $this->category;
    }

    /**
     * @since 1.0.0
     *
     * @return array<int, string>
     */
    public function variables(): array
    {
        return $this->variables;
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function previewData(): array
    {
        return $this->previewData;
    }

    /**
     * @since 1.0.0
     *
     * @return string|null
     */
    public function defaultSubject(): ?string
    {
        return $this->defaultSubject;
    }

    /**
     * @since 1.0.0
     *
     * @return string
     */
    public function defaultBody(): string
    {
        return $this->defaultBody;
    }
}
