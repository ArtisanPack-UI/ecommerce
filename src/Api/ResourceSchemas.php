<?php

/**
 * ResourceSchemas.
 *
 * The typed shape of every REST resource (engine spec §9.13), declared once
 * and consumed by every API surface that needs types:
 *
 * - the GraphQL schema builds one object type per entry (engine spec §10.1);
 * - the OpenAPI generator emits one component schema per entry;
 * - outbound webhook payloads serialize models through the mapped resource.
 *
 * Field types use GraphQL notation (`String`, `Int!`, `[String!]`, `Money`,
 * `BigInt` (64-bit integers GraphQL `Int` cannot hold),
 * `DateTime`, `JSON`); a trailing `!` marks the field non-null. Field names
 * are the resource's own snake_case keys, so REST and GraphQL payloads are
 * the same shape — and the `ap.ecommerce.api.resource.{name}` filter
 * decorates both. A test asserts every key a resource emits is declared
 * here, so the two cannot drift.
 *
 * Relations map the resource's public include name to
 * `[ TypeName, isList, eloquentRelation, adminOnly ]`. Admin-only relations
 * (internal order notes, the audit timeline, a cart's customer record, …)
 * are only ever loaded for callers that passed an admin ability check.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Api;

use ArtisanPackUI\Ecommerce\Http\Resources;
use ArtisanPackUI\Ecommerce\Models;
use Illuminate\Database\Eloquent\Model;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @phpstan-type ResourceSchema array{
 *     resource: class-string<Resources\EcommerceResource>,
 *     model: class-string<Model>,
 *     description: string,
 *     fields: array<string, string>,
 *     relations: array<string, array{0: string, 1: bool, 2: string, 3?: bool}>,
 * }
 */
final class ResourceSchemas
{
    /**
     * Cached definitions (built once per process).
     *
     * @since 1.0.0
     *
     * @var array<string, ResourceSchema>|null
     */
    private static ?array $definitions = null;

    /**
     * Every resource schema, keyed by type name (PascalCase).
     *
     * @since 1.0.0
     *
     * @return array<string, ResourceSchema>
     */
    public static function all(): array
    {
        return self::$definitions ??= self::define();
    }

    /**
     * One resource schema by type name.
     *
     * @since 1.0.0
     *
     * @param  string  $type  Type name.
     *
     * @return ResourceSchema|null
     */
    public static function get( string $type ): ?array
    {
        return self::all()[ $type ] ?? null;
    }

    /**
     * The type name whose resource is `$resourceClass`.
     *
     * @since 1.0.0
     *
     * @param  string  $resourceClass  Resource class.
     *
     * @return string|null
     */
    public static function typeForResource( string $resourceClass ): ?string
    {
        foreach ( self::all() as $type => $schema ) {
            if ( $schema['resource'] === $resourceClass ) {
                return $type;
            }
        }

        return null;
    }

    /**
     * The resource class that renders `$model`, if the engine has one.
     *
     * @since 1.0.0
     *
     * @param  Model|string  $model  Model instance or class.
     *
     * @return class-string<Resources\EcommerceResource>|null
     */
    public static function resourceForModel( Model|string $model ): ?string
    {
        $class = $model instanceof Model ? $model::class : $model;

        foreach ( self::all() as $schema ) {
            if ( $schema['model'] === $class ) {
                return $schema['resource'];
            }
        }

        return null;
    }

    /**
     * Builds the definitions.
     *
     * @since 1.0.0
     *
     * @return array<string, ResourceSchema>
     */
    private static function define(): array
    {
        $timestamps = [ 'created_at' => 'DateTime', 'updated_at' => 'DateTime' ];

        return [
            'Product' => self::schema( Resources\ProductResource::class, Models\Product::class, 'A catalog product.', [
                'product_type'            => 'String!',
                'name'                    => 'String!',
                'slug'                    => 'String!',
                'sku'                     => 'String',
                'barcode'                 => 'String',
                'description'             => 'String',
                'short_description'       => 'String',
                'status'                  => 'String!',
                'featured_image_media_id' => 'Int',
                'is_taxable'              => 'Boolean',
                'tax_class_key'           => 'String',
                'weight'                  => 'Float',
                'weight_unit'             => 'String',
                'length'                  => 'Float',
                'width'                   => 'Float',
                'height'                  => 'Float',
                'dim_unit'                => 'String',
                'avg_rating'              => 'Float',
                'reviews_count'           => 'Int',
                'meta'                    => 'JSON',
                'published_at'            => 'DateTime',
            ] + $timestamps, [
                'variants'   => [ 'ProductVariant', true, 'variants' ],
                'prices'     => [ 'ProductPrice', true, 'prices' ],
                'attributes' => [ 'ProductAttribute', true, 'productAttributes' ],
            ] ),

            'ProductVariant' => self::schema( Resources\ProductVariantResource::class, Models\ProductVariant::class, 'A purchasable variant of a product.', [
                'product_id'     => 'Int!',
                'sku'            => 'String',
                'barcode'        => 'String',
                'name'           => 'String',
                'image_media_id' => 'Int',
                'weight'         => 'Float',
                'weight_unit'    => 'String',
                'length'         => 'Float',
                'width'          => 'Float',
                'height'         => 'Float',
                'dim_unit'       => 'String',
                'position'       => 'Int',
                'meta'           => 'JSON',
            ] + $timestamps, [
                'product' => [ 'Product', false, 'product' ],
                'prices'  => [ 'ProductPrice', true, 'prices' ],
            ] ),

            'ProductPrice' => self::schema( Resources\ProductPriceResource::class, Models\ProductPrice::class, 'A per-currency price row for a product or variant.', [
                'priceable_type' => 'String!',
                'priceable_id'   => 'Int!',
                'currency'       => 'String!',
                'price'          => 'Money!',
                'compare_at'     => 'Money',
                'cost'           => 'Money',
                'starts_at'      => 'DateTime',
                'ends_at'        => 'DateTime',
            ] ),

            'ProductAttribute' => self::schema( Resources\ProductAttributeResource::class, Models\ProductAttribute::class, 'An attribute definition (size, color, …) on a product.', [
                'product_id'   => 'Int!',
                'key'          => 'String!',
                'label'        => 'String',
                'position'     => 'Int',
                'is_variation' => 'Boolean',
            ], [
                'values' => [ 'ProductAttributeValue', true, 'values' ],
            ] ),

            'ProductAttributeValue' => self::schema( Resources\ProductAttributeValueResource::class, Models\ProductAttributeValue::class, 'One allowed value of a product attribute.', [
                'product_attribute_id' => 'Int!',
                'value'                => 'String!',
                'label'                => 'String',
                'swatch'               => 'String',
                'position'             => 'Int',
            ] ),

            'Customer' => self::schema( Resources\CustomerResource::class, Models\Customer::class, 'A shopper record (guest or linked to a user).', [
                'user_id'              => 'Int',
                'email'                => 'String!',
                'first_name'           => 'String',
                'last_name'            => 'String',
                'phone'                => 'String',
                'accepts_marketing'    => 'Boolean',
                'accepts_marketing_at' => 'DateTime',
                'total_spent'          => 'Money',
                'orders_count'         => 'Int',
                'last_ordered_at'      => 'DateTime',
                'meta'                 => 'JSON',
            ] + $timestamps, [
                'addresses' => [ 'CustomerAddress', true, 'addresses' ],
            ] ),

            'CustomerAddress' => self::schema( Resources\CustomerAddressResource::class, Models\CustomerAddress::class, 'A saved customer address.', [
                'customer_id'         => 'Int!',
                'label'               => 'String',
                'is_default_shipping' => 'Boolean',
                'is_default_billing'  => 'Boolean',
                'first_name'          => 'String',
                'last_name'           => 'String',
                'company'             => 'String',
                'phone'               => 'String',
                'address1'            => 'String',
                'address2'            => 'String',
                'city'                => 'String',
                'region'              => 'String',
                'region_code'         => 'String',
                'postal_code'         => 'String',
                'country_code'        => 'String',
            ] ),

            'Cart' => self::schema( Resources\CartResource::class, Models\Cart::class, 'A shopping cart, addressed by its opaque token.', [
                'token'               => 'String!',
                'customer_id'         => 'Int',
                'currency'            => 'String!',
                'email'               => 'String',
                'subtotal'            => 'Money!',
                'discount'            => 'Money!',
                'tax'                 => 'Money!',
                'shipping'            => 'Money!',
                'total'               => 'Money!',
                'checkout_started_at' => 'DateTime',
                'abandoned_at'        => 'DateTime',
                'completed_order_id'  => 'Int',
                'meta'                => 'JSON',
                'expires_at'          => 'DateTime',
            ] + $timestamps, [
                'items'    => [ 'CartItem', true, 'items' ],
                'customer' => [ 'Customer', false, 'customer', true ],
            ] ),

            'CartItem' => self::schema( Resources\CartItemResource::class, Models\CartItem::class, 'A line in a cart.', [
                'cart_id'            => 'Int!',
                'product_id'         => 'Int!',
                'product_variant_id' => 'Int',
                'quantity'           => 'Int!',
                'unit_price'         => 'Money!',
                'line_subtotal'      => 'Money!',
                'line_total'         => 'Money!',
                'options'            => 'JSON',
                'meta'               => 'JSON',
            ], [
                'product' => [ 'Product', false, 'product' ],
                'variant' => [ 'ProductVariant', false, 'variant' ],
            ] ),

            'Order' => self::schema( Resources\OrderResource::class, Models\Order::class, 'A placed order.', [
                'order_number'        => 'String!',
                'customer_id'         => 'Int',
                'email'               => 'String',
                'phone'               => 'String',
                'system_status'       => 'String!',
                'substatus_id'        => 'Int',
                'payment_status'      => 'String',
                'fulfillment_status'  => 'String',
                'currency'            => 'String!',
                'base_currency'       => 'String',
                'fx_rate_to_base_e8'  => 'BigInt',
                'subtotal'            => 'Money!',
                'discount'            => 'Money!',
                'tax'                 => 'Money!',
                'shipping'            => 'Money!',
                'total'               => 'Money!',
                'total_refunded'      => 'Money!',
                'shipping_address'    => 'JSON',
                'billing_address'     => 'JSON',
                'shipping_method_key' => 'String',
                'payment_gateway_key' => 'String',
                'payment_reference'   => 'String',
                'ip_address'          => 'String',
                'user_agent'          => 'String',
                'customer_note'       => 'String',
                'is_claimed'          => 'Boolean',
                'meta'                => 'JSON',
                'placed_at'           => 'DateTime',
            ] + $timestamps, [
                'items'            => [ 'OrderItem', true, 'items' ],
                'customer'         => [ 'Customer', false, 'customer' ],
                'notes'            => [ 'OrderNote', true, 'notes', true ],
                'timeline'         => [ 'OrderTimelineEntry', true, 'timelineEntries', true ],
                'edits'            => [ 'OrderEdit', true, 'edits', true ],
                'refunds'          => [ 'Refund', true, 'refunds' ],
                'shipments'        => [ 'Shipment', true, 'shipments' ],
                'promotion_usages' => [ 'PromotionUsage', true, 'promotionUsages', true ],
            ] ),

            'OrderItem' => self::schema( Resources\OrderItemResource::class, Models\OrderItem::class, 'A line on an order.', [
                'order_id'           => 'Int!',
                'product_id'         => 'Int',
                'product_variant_id' => 'Int',
                'product_snapshot'   => 'JSON',
                'quantity'           => 'Int!',
                'unit_price'         => 'Money!',
                'discount'           => 'Money!',
                'tax'                => 'Money!',
                'shipping'           => 'Money!',
                'total'              => 'Money!',
                'fulfillment_status' => 'String',
                'meta'               => 'JSON',
            ] ),

            'OrderNote' => self::schema( Resources\OrderNoteResource::class, Models\OrderNote::class, 'A note attached to an order.', [
                'order_id'            => 'Int!',
                'author_user_id'      => 'Int',
                'body'                => 'String!',
                'is_customer_visible' => 'Boolean',
                'created_at'          => 'DateTime',
            ] ),

            'OrderTimelineEntry' => self::schema( Resources\OrderTimelineEntryResource::class, Models\OrderTimelineEntry::class, 'An append-only order timeline event.', [
                'order_id'      => 'Int!',
                'actor_user_id' => 'Int',
                'event_type'    => 'String!',
                'payload'       => 'JSON',
                'created_at'    => 'DateTime',
            ] ),

            'OrderEdit' => self::schema( Resources\OrderEditResource::class, Models\OrderEdit::class, 'An audited post-placement order edit.', [
                'order_id'          => 'Int!',
                'actor_user_id'     => 'Int',
                'reason'            => 'String',
                'diff'              => 'JSON',
                'pre_edit_snapshot' => 'JSON',
                'created_at'        => 'DateTime',
            ] ),

            'Refund' => self::schema( Resources\RefundResource::class, Models\Refund::class, 'A refund issued against an order.', [
                'order_id'          => 'Int!',
                'amount'            => 'Money!',
                'reason'            => 'String',
                'gateway_reference' => 'String',
                'issued_by_user_id' => 'Int',
                'created_at'        => 'DateTime',
            ], [
                'items' => [ 'RefundItem', true, 'items' ],
            ] ),

            'RefundItem' => self::schema( Resources\RefundItemResource::class, Models\RefundItem::class, 'A per-line allocation of a refund.', [
                'refund_id'     => 'Int!',
                'order_item_id' => 'Int!',
                'quantity'      => 'Int!',
                'amount'        => 'Money!',
                'restock'       => 'Boolean',
            ] ),

            'InventoryItem' => self::schema( Resources\InventoryItemResource::class, Models\InventoryItem::class, 'Stock levels for a product or variant.', [
                'stockable_type'      => 'String!',
                'stockable_id'        => 'Int!',
                'track_inventory'     => 'Boolean',
                'quantity_on_hand'    => 'Int',
                'quantity_reserved'   => 'Int',
                'quantity_available'  => 'Int',
                'allow_backorder'     => 'Boolean',
                'low_stock_threshold' => 'Int',
                'warehouse_id'        => 'Int',
            ], [
                'reservations' => [ 'InventoryReservation', true, 'reservations', true ],
            ] ),

            'InventoryReservation' => self::schema( Resources\InventoryReservationResource::class, Models\InventoryReservation::class, 'A temporary stock hold.', [
                'inventory_item_id' => 'Int!',
                'reservable_type'   => 'String',
                'reservable_id'     => 'Int',
                'quantity'          => 'Int!',
                'expires_at'        => 'DateTime',
            ] ),

            'ShippingZone' => self::schema( Resources\ShippingZoneResource::class, Models\ShippingZone::class, 'A geographic shipping zone.', [
                'name'            => 'String!',
                'country_codes'   => '[String!]',
                'region_codes'    => '[String!]',
                'postal_patterns' => '[String!]',
                'priority'        => 'Int',
                'is_active'       => 'Boolean',
            ] + $timestamps, [
                'methods' => [ 'ShippingMethod', true, 'methods' ],
            ] ),

            'ShippingMethod' => self::schema( Resources\ShippingMethodResource::class, Models\ShippingMethod::class, 'A shipping method offered in a zone.', [
                'zone_id'       => 'Int!',
                'key'           => 'String!',
                'label'         => 'String',
                'config'        => 'JSON',
                'tax_class_key' => 'String',
                'is_active'     => 'Boolean',
                'position'      => 'Int',
            ] + $timestamps, [
                'zone' => [ 'ShippingZone', false, 'zone' ],
            ] ),

            'Shipment' => self::schema( Resources\ShipmentResource::class, Models\Shipment::class, 'A shipment fulfilling order lines.', [
                'order_id'        => 'Int!',
                'method_key'      => 'String',
                'carrier'         => 'String',
                'service'         => 'String',
                'tracking_number' => 'String',
                'tracking_url'    => 'String',
                'label_id'        => 'Int',
                'status'          => 'String',
                'shipped_at'      => 'DateTime',
                'delivered_at'    => 'DateTime',
                'meta'            => 'JSON',
            ] + $timestamps, [
                'items' => [ 'ShipmentItem', true, 'items' ],
            ] ),

            'ShipmentItem' => self::schema( Resources\ShipmentItemResource::class, Models\ShipmentItem::class, 'A line shipped in a shipment.', [
                'shipment_id'   => 'Int!',
                'order_item_id' => 'Int!',
                'quantity'      => 'Int!',
            ] ),

            'TaxClass' => self::schema( Resources\TaxClassResource::class, Models\TaxClass::class, 'A tax class grouping tax rates.', [
                'key'   => 'String!',
                'label' => 'String',
            ] + $timestamps, [
                'rates' => [ 'TaxRate', true, 'rates' ],
            ] ),

            'TaxRate' => self::schema( Resources\TaxRateResource::class, Models\TaxRate::class, 'A manual tax rate.', [
                'tax_class_key'       => 'String!',
                'country_code'        => 'String',
                'region_code'         => 'String',
                'postal_pattern'      => 'String',
                'rate_ubps'           => 'Int!',
                'rate_percent'        => 'String',
                'is_compound'         => 'Boolean',
                'priority'            => 'Int',
                'label'               => 'String',
                'is_shipping_taxable' => 'Boolean',
                'is_active'           => 'Boolean',
            ] + $timestamps ),

            'Promotion' => self::schema( Resources\PromotionResource::class, Models\Promotion::class, 'An automatic or coupon-driven promotion.', [
                'key'                      => 'String',
                'name'                     => 'String!',
                'description'              => 'String',
                'source_type'              => 'String!',
                'is_exclusive'             => 'Boolean',
                'priority'                 => 'Int',
                'starts_at'                => 'DateTime',
                'ends_at'                  => 'DateTime',
                'usage_limit_total'        => 'Int',
                'usage_limit_per_customer' => 'Int',
                'times_used'               => 'Int',
                'is_active'                => 'Boolean',
            ] + $timestamps, [
                'conditions' => [ 'PromotionCondition', true, 'conditions' ],
                'actions'    => [ 'PromotionAction', true, 'actions' ],
                'coupons'    => [ 'Coupon', true, 'coupons' ],
            ] ),

            'PromotionCondition' => self::schema( Resources\PromotionConditionResource::class, Models\PromotionCondition::class, 'A condition a cart must meet for a promotion.', [
                'promotion_id'   => 'Int!',
                'condition_type' => 'String!',
                'config'         => 'JSON',
            ] ),

            'PromotionAction' => self::schema( Resources\PromotionActionResource::class, Models\PromotionAction::class, 'The discount a promotion applies.', [
                'promotion_id' => 'Int!',
                'action_type'  => 'String!',
                'config'       => 'JSON',
            ] ),

            'Coupon' => self::schema( Resources\CouponResource::class, Models\Coupon::class, 'A redeemable code for a promotion.', [
                'promotion_id' => 'Int!',
                'code'         => 'String!',
            ] + $timestamps, [
                'promotion' => [ 'Promotion', false, 'promotion' ],
            ] ),

            'PromotionUsage' => self::schema( Resources\PromotionUsageResource::class, Models\PromotionUsage::class, 'A recorded redemption of a promotion.', [
                'promotion_id'      => 'Int!',
                'order_id'          => 'Int!',
                'customer_id'       => 'Int',
                'amount_discounted' => 'Money!',
                'created_at'        => 'DateTime',
            ] ),

            'WebhookSubscription' => self::schema( Resources\WebhookSubscriptionResource::class, Models\WebhookSubscription::class, 'An outbound webhook endpoint.', [
                'name'                 => 'String!',
                'url'                  => 'String!',
                'events'               => '[String!]!',
                'is_active'            => 'Boolean!',
                'secret'               => 'String',
                'last_success_at'      => 'DateTime',
                'last_failure_at'      => 'DateTime',
                'consecutive_failures' => 'Int!',
            ] + $timestamps, [
                'deliveries' => [ 'WebhookDelivery', true, 'deliveries', true ],
            ] ),

            'WebhookDelivery' => self::schema( Resources\WebhookDeliveryResource::class, Models\WebhookDelivery::class, 'One outbound webhook delivery and its retry state.', [
                'subscription_id' => 'Int!',
                'event'           => 'String!',
                'payload_hash'    => 'String!',
                'payload'         => 'JSON',
                'response_status' => 'Int',
                'response_body'   => 'String',
                'attempts'        => 'Int!',
                'delivered_at'    => 'DateTime',
                'next_retry_at'   => 'DateTime',
                'created_at'      => 'DateTime',
            ] ),
        ];
    }

    /**
     * Builds one definition.
     *
     * @since 1.0.0
     *
     * @param  class-string<Resources\EcommerceResource>       $resource     Resource class.
     * @param  class-string<Model>                             $model        Model class.
     * @param  string                                          $description  Type description.
     * @param  array<string, string>                           $fields       Field → type.
     * @param  array<string, array{0: string, 1: bool, 2: string, 3?: bool}>  $relations  Relations.
     *
     * @return ResourceSchema
     */
    private static function schema( string $resource, string $model, string $description, array $fields, array $relations = [] ): array
    {
        return [
            'resource'    => $resource,
            'model'       => $model,
            'description' => $description,
            'fields'      => [ 'id' => 'ID!', 'type' => 'String!' ] + $fields,
            'relations'   => $relations,
        ];
    }
}
