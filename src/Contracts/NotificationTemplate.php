<?php

/**
 * NotificationTemplate contract.
 *
 * One entry in the notification catalog (parent plan §14.4): what the
 * notification is called, which channel it goes out on, which preference
 * category governs it, which variables its Twig source may reference, and
 * the default copy the engine ships. Store owners override the copy per
 * locale in the `notification_templates` table; the definition stays the
 * source of truth for everything else.
 *
 * Definitions register against
 * {@see \ArtisanPackUI\Ecommerce\Registries\NotificationTemplateRegistry};
 * satellites add their own (`subscriptions.renewal-reminder.customer`, …)
 * from their service-provider `boot()`.
 *
 * Engine spec §4.14.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Contracts;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
interface NotificationTemplate
{
    /**
     * Registry key, e.g. `order.confirmation.customer`.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function key(): string;

    /**
     * Human-readable name for the template editor.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function label(): string;

    /**
     * Delivery channel: `mail`, `database`, or a satellite channel key.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function channel(): string;

    /**
     * Preference category (`transactional`, `shipping-updates`,
     * `review-requests`, `marketing`, …) customers opt in or out of.
     * `transactional` notifications always send.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function category(): string;

    /**
     * Dotted paths the template may reference, e.g. `Order.number`,
     * `Order.customer.name`, `Order.items.*.name` (`*` marks a list).
     * Template sources that reference anything else are rejected when
     * saved, and the editor offers these for autocomplete.
     *
     * @since 1.0.0
     *
     * @return array<int, string>
     */
    public function variables(): array;

    /**
     * Sample context for the editor's live preview, shaped like the
     * runtime context (`[ 'Order' => [ 'number' => …, … ] ]`).
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function previewData(): array;

    /**
     * Default Twig source for the subject line (null for channels without
     * one).
     *
     * @since 1.0.0
     *
     * @return string|null
     */
    public function defaultSubject(): ?string;

    /**
     * Default Twig source for the body.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function defaultBody(): string;
}
