<?php

/**
 * CatalogNotificationTemplate.
 *
 * A data-defined {@see NotificationTemplate}: every value is passed in at
 * construction. The engine's catalog ({@see NotificationCatalog}) is made
 * of these; satellites can use it too rather than writing a class per
 * template.
 *
 * The label, subject, and body may be strings or closures taking the
 * locale (`fn ( ?string $locale ): string => __( '…', [], $locale )`), so
 * copy is translated when it's used — in the recipient's language — not
 * once when the catalog is registered.
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
use Closure;

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
     * @param  string                                            $key             Registry key.
     * @param  Closure(string|null): string|string                $label           Editor label.
     * @param  string                                            $category        Preference category.
     * @param  array<int, string>                                $variables       Declared variable paths.
     * @param  array<string, mixed>                              $previewData     Sample context.
     * @param  Closure(string|null): (string|null)|string|null  $defaultSubject  Default subject source.
     * @param  Closure(string|null): string|string                $defaultBody     Default body source.
     * @param  string                                            $channel         Delivery channel.
     */
    public function __construct(
        protected string $key,
        protected Closure|string $label,
        protected string $category,
        protected array $variables,
        protected array $previewData,
        protected Closure|string|null $defaultSubject,
        protected Closure|string $defaultBody,
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
        return $this->label instanceof Closure ? (string) ( $this->label )( null ) : $this->label;
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
     * @param  string|null  $locale  Locale (null: the current app locale).
     *
     * @return string|null
     */
    public function defaultSubject( ?string $locale = null ): ?string
    {
        if ( $this->defaultSubject instanceof Closure ) {
            $subject = ( $this->defaultSubject )( $locale );

            return null === $subject ? null : (string) $subject;
        }

        return $this->defaultSubject;
    }

    /**
     * @since 1.0.0
     *
     * @param  string|null  $locale  Locale (null: the current app locale).
     *
     * @return string
     */
    public function defaultBody( ?string $locale = null ): string
    {
        return $this->defaultBody instanceof Closure ? (string) ( $this->defaultBody )( $locale ) : $this->defaultBody;
    }
}
