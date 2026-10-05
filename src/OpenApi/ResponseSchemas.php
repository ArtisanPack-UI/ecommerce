<?php

/**
 * ResponseSchemas.
 *
 * Success-response schemas for the endpoints whose body isn't a resource
 * (audit F6): catalogs, reports, settings, previews, validation answers,
 * quotes. Keyed by operationId; {@see OpenApiGenerator} uses them where a
 * route has no `#[ApiOperation( resource: … )]`.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\OpenApi;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class ResponseSchemas
{
    /**
     * Operations that answer with a file rather than JSON.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const BINARY = [ 'downloadsShow', 'downloadsStream', 'meDownloadsShow', 'meDownloadsStream' ];

    /**
     * The success body schema for `$operationId`, or null when none is known.
     *
     * @since 1.0.0
     *
     * @param  string  $operationId  Operation id.
     *
     * @return array<string, mixed>|null
     */
    public static function for( string $operationId ): ?array
    {
        $data = match ( $operationId ) {
            'adminPromotionActionsIndex',
            'adminPromotionConditionsIndex',
            'adminShippingMethodTypesIndex',
            'kanbanTriggersIndex'               => self::listOf( self::catalogEntry() ),
            'adminReportsIndex'                 => self::listOf( self::object( [ 'key' => self::string(), 'label' => self::string(), 'ranged' => [ 'type' => 'boolean' ] ], [ 'key', 'label', 'ranged' ] ) ),
            'adminReportsShow'                  => self::object( [
                'report'   => self::string(),
                'range'    => self::open(),
                'currency' => self::string(),
                'totals'   => self::open(),
            ], [], true ),
            'adminSettingsIndex'                => self::listOf( self::open() ),
            'adminSettingsShow',
            'adminSettingsUpdate'               => self::object( [
                'key'      => self::string(),
                'label'    => self::string(),
                'settings' => self::listOf( self::object( [ 'key' => self::string(), 'type' => self::string(), 'label' => self::string(), 'value' => [], 'stored' => [ 'type' => 'boolean' ] ], [], true ) ),
                'secrets'  => self::listOf( self::open() ),
            ], [], true ),
            'adminNotificationTemplatesPreview'     => self::object( [ 'subject' => self::string(), 'html' => self::string(), 'text' => self::string() ], [], true ),
            'adminWebhookSubscriptionsReplayParked' => self::object( [ 'requeued' => [ 'type' => 'integer', 'minimum' => 0 ] ], [ 'requeued' ] ),
            'cartsShippingRatesIndex'               => self::listOf( self::shippingRate() ),
            'categoriesIndex'                       => self::listOf( self::categoryNode() ),
            'customersDestroy'                      => self::object( [ 'type' => [ 'type' => 'string', 'enum' => [ 'customer' ] ], 'id' => [ 'type' => 'integer' ], 'deleted' => [ 'type' => 'boolean' ] ], [ 'type', 'id', 'deleted' ] ),
            'licenseValidate'                       => self::object( [
                'valid'      => [ 'type' => 'boolean' ],
                'expires_at' => [ 'type' => [ 'string', 'null' ], 'format' => 'date-time' ],
                'product'    => [ 'type' => [ 'object', 'null' ], 'properties' => [ 'id' => [ 'type' => [ 'integer', 'null' ] ], 'name' => [ 'type' => [ 'string', 'null' ] ] ] ],
                'revoked'    => [ 'type' => 'boolean' ],
                'reason'     => [ 'type' => [ 'string', 'null' ] ],
            ], [ 'valid', 'revoked' ] ),
            'licenseDeactivate'                 => self::object( [
                'deactivated'       => [ 'type' => 'boolean' ],
                'activations_count' => [ 'type' => [ 'integer', 'null' ] ],
                'activations_limit' => [ 'type' => [ 'integer', 'null' ] ],
                'reason'            => [ 'type' => [ 'string', 'null' ], 'enum' => [ 'not-found', 'not-activated', null ] ],
            ], [ 'deactivated' ] ),
            'meNotificationPreferencesShow',
            'meNotificationPreferencesUpdate'   => self::listOf( self::object( [
                'channel'    => self::string(),
                'category'   => self::string(),
                'is_enabled' => [ 'type' => 'boolean' ],
                'is_locked'  => [ 'type' => 'boolean' ],
            ], [ 'channel', 'category', 'is_enabled', 'is_locked' ] ) ),
            'meAccountMenu'                     => self::listOf( self::object( [
                'key'      => self::string(),
                'label'    => self::string(),
                'route'    => self::string(),
                'url'      => [ 'type' => [ 'string', 'null' ] ],
                'icon'     => [ 'type' => [ 'string', 'null' ] ],
                'position' => [ 'type' => 'integer' ],
            ], [ 'key', 'label', 'route', 'position' ] ) ),
            'productsReviewsEligibility'        => self::object( [
                'allowed'           => [ 'type' => 'boolean' ],
                'reason'            => [ 'type' => [ 'string', 'null' ], 'enum' => [ 'guests-not-allowed', 'already-reviewed', 'purchase-required', null ] ],
                'verified_purchase' => [ 'type' => 'boolean' ],
            ], [ 'allowed', 'reason', 'verified_purchase' ] ),
            'productsViewsStore'                => self::object( [ 'recorded' => [ 'type' => 'boolean' ] ], [ 'recorded' ] ),
            'productsPurchaseOptions'           => self::object( [
                'product_id' => [ 'type' => 'integer' ],
                'currency'   => self::string(),
                'price'      => [ 'oneOf' => [ self::displayPrice(), [ 'type' => 'null' ] ] ],
                'stock'      => self::stockStatus(),
                'variants'   => self::listOf( self::object( [
                    'variant_id'          => [ 'type' => 'integer' ],
                    'sku'                 => [ 'type' => [ 'string', 'null' ] ],
                    'attribute_value_ids' => self::listOf( [ 'type' => 'integer' ] ),
                    'available'           => [ 'type' => 'boolean' ],
                    'stock'               => self::stockStatus(),
                    'price'               => [ 'oneOf' => [ self::displayPrice(), [ 'type' => 'null' ] ] ],
                    'image_media_id'      => [ 'type' => [ 'integer', 'null' ] ],
                ], [ 'variant_id', 'attribute_value_ids', 'available', 'stock' ] ) ),
                'options'    => self::listOf( self::object( [
                    'type'  => self::string(),
                    'name'  => self::string(),
                    'label' => self::string(),
                ], [ 'type', 'name', 'label' ], true ) ),
            ], [ 'product_id', 'currency', 'stock', 'variants', 'options' ] ),
            'tagsIndex'                         => self::listOf( self::object( [ 'id' => [ 'type' => 'integer' ], 'name' => self::string(), 'slug' => self::string(), 'products_count' => [ 'type' => 'integer' ] ], [ 'id', 'name', 'slug', 'products_count' ] ) ),
            'webhooks'                          => null,
            default                             => null,
        };

        if ( 'webhooks' === $operationId ) {
            return self::object( [ 'received' => [ 'type' => 'boolean' ], 'event_id' => [ 'type' => [ 'string', 'null' ] ], 'type' => [ 'type' => [ 'string', 'null' ] ], 'duplicate' => [ 'type' => 'boolean' ] ], [ 'received' ] );
        }

        return null === $data ? null : self::object( [ 'data' => $data ], [ 'data' ], true );
    }

    /**
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $properties  Properties.
     * @param  array<int, string>    $required    Required keys.
     * @param  bool                  $open        Allow other keys.
     *
     * @return array<string, mixed>
     */
    private static function object( array $properties, array $required = [], bool $open = false ): array
    {
        return array_filter( [
            'type'                 => 'object',
            'properties'           => $properties,
            'required'             => [] === $required ? null : $required,
            'additionalProperties' => $open,
        ], static fn ( mixed $value ): bool => null !== $value );
    }

    /**
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $items  Item schema.
     *
     * @return array<string, mixed>
     */
    private static function listOf( array $items ): array
    {
        return [ 'type' => 'array', 'items' => $items ];
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    private static function string(): array
    {
        return [ 'type' => 'string' ];
    }

    /**
     * A free-form object.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    private static function open(): array
    {
        return [ 'type' => 'object', 'additionalProperties' => true ];
    }

    /**
     * A registry catalog entry (promotion conditions/actions, shipping method
     * types, kanban triggers).
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    private static function catalogEntry(): array
    {
        return self::object( [
            'key'           => self::string(),
            'label'         => self::string(),
            'provided_by'   => self::string(),
            'config_schema' => self::listOf( self::open() ),
        ], [ 'key', 'label' ], true );
    }

    /**
     * A quoted shipping rate.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    private static function shippingRate(): array
    {
        return self::object( [
            'id'                 => self::string(),
            'method_key'         => self::string(),
            'label'              => self::string(),
            'amount'             => [ 'type' => 'integer' ],
            'currency'           => self::string(),
            'shipping_method_id' => [ 'type' => [ 'integer', 'null' ] ],
            'carrier'            => [ 'type' => [ 'string', 'null' ] ],
            'service'            => [ 'type' => [ 'string', 'null' ] ],
            'meta'               => self::open(),
        ], [ 'id', 'label', 'amount', 'currency' ] );
    }

    /**
     * A {@see \ArtisanPackUI\Ecommerce\Pricing\DisplayPrice}.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    private static function displayPrice(): array
    {
        $money    = self::object( [ 'amount' => [ 'type' => 'integer' ], 'currency' => self::string() ], [ 'amount', 'currency' ] );
        $nullable = [ 'oneOf' => [ $money, [ 'type' => 'null' ] ] ];

        return self::object( [
            'price'               => $money,
            'compare_at'          => $nullable,
            'on_sale'             => [ 'type' => 'boolean' ],
            'is_range'            => [ 'type' => 'boolean' ],
            'min_price'           => $nullable,
            'max_price'           => $nullable,
            'price_including_tax' => $money,
            'price_excluding_tax' => $money,
            'prices_include_tax'  => [ 'type' => 'boolean' ],
            'tax_label'           => [ 'type' => [ 'string', 'null' ] ],
        ], [ 'price', 'on_sale', 'price_including_tax', 'price_excluding_tax', 'prices_include_tax' ] );
    }

    /**
     * A {@see \ArtisanPackUI\Ecommerce\Inventory\StockStatus}.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    private static function stockStatus(): array
    {
        return self::object( [
            'status'      => [ 'type' => 'string', 'enum' => [ 'in_stock', 'low_stock', 'backorder', 'out_of_stock' ] ],
            'purchasable' => [ 'type' => 'boolean' ],
            'quantity'    => [ 'type' => [ 'integer', 'null' ] ],
        ], [ 'status', 'purchasable', 'quantity' ] );
    }

    /**
     * A category tree node.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    private static function categoryNode(): array
    {
        return self::object( [
            'id'             => [ 'type' => 'integer' ],
            'parent_id'      => [ 'type' => [ 'integer', 'null' ] ],
            'name'           => self::string(),
            'slug'           => self::string(),
            'description'    => [ 'type' => [ 'string', 'null' ] ],
            'image_media_id' => [ 'type' => [ 'integer', 'null' ] ],
            'icon'           => [ 'type' => [ 'string', 'null' ] ],
            'position'       => [ 'type' => 'integer' ],
            'children'       => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'description' => 'Nested category node (same shape).', 'additionalProperties' => true ] ],
        ], [ 'id', 'name', 'slug', 'children' ] );
    }
}
