<?php

/**
 * NotificationCatalog.
 *
 * The engine's built-in notifications (parent plan §14.4) with their
 * declared variables, preview data, and default Twig copy. Registered
 * against {@see \ArtisanPackUI\Ecommerce\Registries\NotificationTemplateRegistry}
 * by the service provider; store owners override the copy per locale in
 * `notification_templates`.
 *
 * Variables are dotted paths into the render context that
 * {@see NotificationContext} builds (`Order.number`,
 * `Order.customer.name`, `Order.items.*.name` — `*` marks a list).
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

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class NotificationCatalog
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const ORDER_CONFIRMATION = 'order.confirmation.customer';

    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const ORDER_PAID_ADMIN = 'order.paid.admin';

    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const ORDER_SHIPPED = 'order.shipped.customer';

    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const ORDER_DELIVERED = 'order.delivered.customer';

    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const ORDER_CANCELLED = 'order.cancelled.customer';

    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const ORDER_REFUNDED = 'order.refunded.customer';

    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const REVIEW_REQUEST = 'review.request.customer';

    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const DOWNLOAD_READY = 'digital.download-ready.customer';

    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const DIGITAL_PRODUCT_UPDATED = 'digital.product-updated.customer';

    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const LICENSE_ACTIVATED = 'license.activated.customer';

    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const LOW_STOCK_ADMIN = 'inventory.low-stock.admin';

    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const OUT_OF_STOCK_ADMIN = 'inventory.out-of-stock.admin';

    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const REVIEW_AWAITING_MODERATION_ADMIN = 'review.awaiting-moderation.admin';

    /**
     * Every catalog definition.
     *
     * @since 1.0.0
     *
     * @return array<int, CatalogNotificationTemplate>
     */
    public static function definitions(): array
    {
        $store = [ 'Store.name', 'Store.url', 'Store.support_email' ];
        $order = [
            'Order.number',
            'Order.status',
            'Order.placed_at',
            'Order.email',
            'Order.currency',
            'Order.subtotal',
            'Order.discount',
            'Order.shipping',
            'Order.tax',
            'Order.total',
            'Order.shipping_address',
            'Order.customer.name',
            'Order.customer.first_name',
            'Order.customer.email',
            'Order.items.*.name',
            'Order.items.*.sku',
            'Order.items.*.quantity',
            'Order.items.*.unit_price',
            'Order.items.*.total',
        ];
        $review  = [ 'Review.rating', 'Review.title', 'Review.body', 'Review.author_name', 'Review.is_verified_purchase', 'Review.status' ];
        $product = [ 'Product.name', 'Product.sku' ];

        $sample = self::sampleContext();
        $pick   = static fn ( string ...$roots ): array => array_intersect_key( $sample, array_flip( $roots ) );

        $orderTable = '{% for item in Order.items %}<tr><td>{{ item.name }} &times; {{ item.quantity }}</td><td>{{ item.total }}</td></tr>{% endfor %}';

        return [
            new CatalogNotificationTemplate(
                self::ORDER_CONFIRMATION,
                __( 'Order confirmation' ),
                'transactional',
                array_merge( $store, $order ),
                $pick( 'Store', 'Order' ),
                __( 'Your :store order :number', [ 'store' => '{{ Store.name }}', 'number' => '{{ Order.number }}' ] ),
                __( '<p>Hi {{ Order.customer.first_name|default(Order.customer.name) }},</p><p>Thanks for your order! Here is your summary.</p>' )
                    . '<table>' . $orderTable . '</table>'
                    . __( '<p>Total: <strong>{{ Order.total }}</strong></p><p>We will let you know when it ships.</p>' ),
            ),
            new CatalogNotificationTemplate(
                self::ORDER_PAID_ADMIN,
                __( 'Order paid (admin)' ),
                'transactional',
                array_merge( $store, $order ),
                $pick( 'Store', 'Order' ),
                __( 'Order :number paid (:total)', [ 'number' => '{{ Order.number }}', 'total' => '{{ Order.total }}' ] ),
                __( '<p>Order <strong>{{ Order.number }}</strong> from {{ Order.customer.name }} ({{ Order.email }}) has been paid.</p>' )
                    . '<table>' . $orderTable . '</table>'
                    . __( '<p>Total: <strong>{{ Order.total }}</strong></p>' ),
            ),
            new CatalogNotificationTemplate(
                self::ORDER_SHIPPED,
                __( 'Order shipped' ),
                'shipping-updates',
                array_merge( $store, $order, [ 'Shipment.carrier', 'Shipment.service', 'Shipment.tracking_number', 'Shipment.tracking_url', 'Shipment.shipped_at' ] ),
                $pick( 'Store', 'Order', 'Shipment' ),
                __( 'Your order :number is on its way', [ 'number' => '{{ Order.number }}' ] ),
                __( '<p>Hi {{ Order.customer.first_name|default(Order.customer.name) }},</p><p>Your order {{ Order.number }} has shipped{% if Shipment.carrier %} with {{ Shipment.carrier }}{% endif %}.</p>{% if Shipment.tracking_url %}<p><a href="{{ Shipment.tracking_url }}">Track your package</a> ({{ Shipment.tracking_number }})</p>{% endif %}' ),
            ),
            new CatalogNotificationTemplate(
                self::ORDER_DELIVERED,
                __( 'Order delivered' ),
                'shipping-updates',
                array_merge( $store, $order, [ 'Shipment.carrier', 'Shipment.tracking_number', 'Shipment.delivered_at' ] ),
                $pick( 'Store', 'Order', 'Shipment' ),
                __( 'Your order :number was delivered', [ 'number' => '{{ Order.number }}' ] ),
                __( '<p>Hi {{ Order.customer.first_name|default(Order.customer.name) }},</p><p>Your order {{ Order.number }} has been delivered. We hope you enjoy it!</p>' ),
            ),
            new CatalogNotificationTemplate(
                self::ORDER_CANCELLED,
                __( 'Order cancelled' ),
                'transactional',
                array_merge( $store, $order ),
                $pick( 'Store', 'Order' ),
                __( 'Your order :number was cancelled', [ 'number' => '{{ Order.number }}' ] ),
                __( '<p>Hi {{ Order.customer.first_name|default(Order.customer.name) }},</p><p>Your order {{ Order.number }} has been cancelled. If you have questions, contact us at {{ Store.support_email }}.</p>' ),
            ),
            new CatalogNotificationTemplate(
                self::ORDER_REFUNDED,
                __( 'Order refunded' ),
                'transactional',
                array_merge( $store, $order, [ 'Refund.amount', 'Refund.reason', 'Refund.created_at' ] ),
                $pick( 'Store', 'Order', 'Refund' ),
                __( 'A refund for order :number', [ 'number' => '{{ Order.number }}' ] ),
                __( '<p>Hi {{ Order.customer.first_name|default(Order.customer.name) }},</p><p>We have refunded <strong>{{ Refund.amount }}</strong> for order {{ Order.number }}.{% if Refund.reason %} Reason: {{ Refund.reason }}.{% endif %}</p>' ),
            ),
            new CatalogNotificationTemplate(
                self::REVIEW_REQUEST,
                __( 'Review request' ),
                'review-requests',
                array_merge( $store, $order ),
                $pick( 'Store', 'Order' ),
                __( 'How was your order from :store?', [ 'store' => '{{ Store.name }}' ] ),
                __( '<p>Hi {{ Order.customer.first_name|default(Order.customer.name) }},</p><p>We would love to hear what you think of your purchase:</p><ul>{% for item in Order.items %}<li>{{ item.name }}</li>{% endfor %}</ul><p>Leaving a review helps other shoppers.</p>' ),
            ),
            new CatalogNotificationTemplate(
                self::DOWNLOAD_READY,
                __( 'Digital download ready' ),
                'transactional',
                array_merge( $store, $order, [
                    'Downloads.*.label',
                    'Downloads.*.url',
                    'Downloads.*.stream_url',
                    'Downloads.*.is_streaming_only',
                    'Downloads.*.downloads_remaining',
                    'Downloads.*.expires_at',
                    'Licenses.*.key',
                    'Licenses.*.product',
                    'Licenses.*.activations_limit',
                    'Licenses.*.expires_at',
                ] ),
                $pick( 'Store', 'Order', 'Downloads', 'Licenses' ),
                __( 'Your downloads for order :number', [ 'number' => '{{ Order.number }}' ] ),
                __( '<p>Hi {{ Order.customer.first_name|default(Order.customer.name) }},</p><p>Your files are ready:</p><ul>{% for download in Downloads %}<li>{% if download.is_streaming_only %}<a href="{{ download.stream_url }}">Watch {{ download.label }}</a>{% else %}<a href="{{ download.url }}">{{ download.label }}</a>{% endif %}{% if download.downloads_remaining %} ({{ download.downloads_remaining }} downloads{% if download.expires_at %}, until {{ download.expires_at|date("F j, Y") }}{% endif %}){% endif %}</li>{% endfor %}</ul>{% if Licenses %}<p>Your license keys:</p><ul>{% for license in Licenses %}<li>{{ license.product }}: <code>{{ license.key }}</code></li>{% endfor %}</ul>{% endif %}' ),
            ),
            new CatalogNotificationTemplate(
                self::DIGITAL_PRODUCT_UPDATED,
                __( 'Digital product updated' ),
                'transactional',
                array_merge( $store, $product, [ 'File.label', 'File.version', 'Customer.name', 'Customer.first_name' ] ),
                $pick( 'Store', 'Product', 'File', 'Customer' ),
                __( ':product has been updated', [ 'product' => '{{ Product.name }}' ] ),
                __( '<p>Hi {{ Customer.first_name|default(Customer.name) }},</p><p>A new version of {{ File.label }} ({{ File.version }}) is available. Use the download link from your order email or your account at {{ Store.url }}.</p>' ),
            ),
            new CatalogNotificationTemplate(
                self::LICENSE_ACTIVATED,
                __( 'License activated' ),
                'transactional',
                array_merge( $store, $product, [
                    'License.key_hint',
                    'License.activations_count',
                    'License.activations_limit',
                    'License.expires_at',
                    'Activation.activated_at',
                    'Activation.ip_address',
                    'Customer.name',
                    'Customer.first_name',
                ] ),
                $pick( 'Store', 'Product', 'License', 'Activation', 'Customer' ),
                __( 'Your :product license was activated on a new device', [ 'product' => '{{ Product.name }}' ] ),
                __( '<p>Hi {{ Customer.first_name|default(Customer.name) }},</p><p>Your license ending in <code>{{ License.key_hint }}</code> was activated on a new device. It is now active on {{ License.activations_count }}{% if License.activations_limit %} of {{ License.activations_limit }}{% endif %} devices.</p><p>If this was not you, contact {{ Store.support_email }}.</p>' ),
            ),
            new CatalogNotificationTemplate(
                self::LOW_STOCK_ADMIN,
                __( 'Low stock (admin)' ),
                'transactional',
                array_merge( $store, $product, [ 'InventoryItem.sku', 'InventoryItem.name', 'InventoryItem.on_hand', 'InventoryItem.threshold' ] ),
                $pick( 'Store', 'Product', 'InventoryItem' ),
                __( 'Low stock: :name', [ 'name' => '{{ InventoryItem.name }}' ] ),
                __( '<p>{{ InventoryItem.name }}{% if InventoryItem.sku %} ({{ InventoryItem.sku }}){% endif %} is down to <strong>{{ InventoryItem.on_hand }}</strong> in stock (threshold {{ InventoryItem.threshold }}).</p>' ),
            ),
            new CatalogNotificationTemplate(
                self::OUT_OF_STOCK_ADMIN,
                __( 'Out of stock (admin)' ),
                'transactional',
                array_merge( $store, $product, [ 'InventoryItem.sku', 'InventoryItem.name', 'InventoryItem.on_hand' ] ),
                $pick( 'Store', 'Product', 'InventoryItem' ),
                __( 'Out of stock: :name', [ 'name' => '{{ InventoryItem.name }}' ] ),
                __( '<p>{{ InventoryItem.name }}{% if InventoryItem.sku %} ({{ InventoryItem.sku }}){% endif %} is out of stock.</p>' ),
            ),
            new CatalogNotificationTemplate(
                self::REVIEW_AWAITING_MODERATION_ADMIN,
                __( 'New review awaiting moderation (admin)' ),
                'transactional',
                array_merge( $store, $product, $review ),
                $pick( 'Store', 'Product', 'Review' ),
                __( 'New :rating-star review of :product', [ 'rating' => '{{ Review.rating }}', 'product' => '{{ Product.name }}' ] ),
                __( '<p>{{ Review.author_name }} left a {{ Review.rating }}-star review of {{ Product.name }}{% if Review.is_verified_purchase %} (verified purchase){% endif %}:</p><blockquote><strong>{{ Review.title }}</strong><br>{{ Review.body|nl2br }}</blockquote><p>It is waiting for moderation.</p>' ),
            ),
        ];
    }

    /**
     * Sample values for every context root, used as preview data.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public static function sampleContext(): array
    {
        return [
            'Store'         => [ 'name' => 'Acme Store', 'url' => 'https://shop.example.com', 'support_email' => 'support@example.com' ],
            'Order'         => [
                'number'           => 'K7QM2XW9',
                'status'           => 'processing',
                'placed_at'        => '2026-09-29T14:03:00+00:00',
                'email'            => 'ada@example.com',
                'currency'         => 'USD',
                'subtotal'         => '$42.00',
                'discount'         => '$0.00',
                'shipping'         => '$5.00',
                'tax'              => '$3.36',
                'total'            => '$50.36',
                'shipping_address' => 'Ada Lovelace, 12 Analytical Way, London, NW1 6XE, GB',
                'customer'         => [ 'name' => 'Ada Lovelace', 'first_name' => 'Ada', 'email' => 'ada@example.com' ],
                'items'            => [
                    [ 'name' => 'Difference Engine Poster', 'sku' => 'POSTER-01', 'quantity' => 2, 'unit_price' => '$12.00', 'total' => '$24.00' ],
                    [ 'name' => 'Notes on the Engine (PDF)', 'sku' => 'EBOOK-01', 'quantity' => 1, 'unit_price' => '$18.00', 'total' => '$18.00' ],
                ],
            ],
            'Customer'      => [ 'name' => 'Ada Lovelace', 'first_name' => 'Ada', 'email' => 'ada@example.com' ],
            'Shipment'      => [
                'carrier'         => 'UPS',
                'service'         => 'Ground',
                'tracking_number' => '1Z999AA10123456784',
                'tracking_url'    => 'https://www.ups.com/track?tracknum=1Z999AA10123456784',
                'shipped_at'      => '2026-09-30T09:00:00+00:00',
                'delivered_at'    => '2026-10-02T16:30:00+00:00',
            ],
            'Refund'        => [ 'amount' => '$18.00', 'reason' => 'Damaged in transit', 'created_at' => '2026-10-03T10:00:00+00:00' ],
            'Product'       => [ 'name' => 'Notes on the Engine (PDF)', 'sku' => 'EBOOK-01' ],
            'Review'        => [
                'rating'               => 5,
                'title'                => 'Beautifully written',
                'body'                 => "Clear, thorough, and inspiring.\nHighly recommended.",
                'author_name'          => 'Ada Lovelace',
                'is_verified_purchase' => true,
                'status'               => 'pending',
            ],
            'Downloads'     => [
                [
                    'label'               => 'Notes on the Engine (PDF)',
                    'url'                 => 'https://shop.example.com/api/ecommerce/v1/downloads/sample-token',
                    'stream_url'          => 'https://shop.example.com/api/ecommerce/v1/downloads/sample-token/stream',
                    'is_streaming_only'   => false,
                    'downloads_remaining' => 5,
                    'expires_at'          => '2026-10-29T14:03:00+00:00',
                ],
            ],
            'Licenses'      => [
                [ 'key' => 'K7QM2-XW9RT-4HJ8P-LMN3Q-ZX2CV', 'product' => 'Notes on the Engine (PDF)', 'activations_limit' => 5, 'expires_at' => null ],
            ],
            'File'          => [ 'label' => 'Notes on the Engine (PDF)', 'version' => '2.0.0' ],
            'License'       => [ 'key_hint' => 'ZX2CV', 'activations_count' => 2, 'activations_limit' => 5, 'expires_at' => null ],
            'Activation'    => [ 'activated_at' => '2026-10-04T08:15:00+00:00', 'ip_address' => '203.0.113.7' ],
            'InventoryItem' => [ 'sku' => 'POSTER-01', 'name' => 'Difference Engine Poster', 'on_hand' => 3, 'threshold' => 5 ],
        ];
    }
}
