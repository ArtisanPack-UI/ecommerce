<?php

/**
 * ConfigCatalogController.
 *
 * What a promotion rule builder or shipping-method form can offer (engine
 * issue #149): the registered promotion conditions
 * (`GET admin/promotion-conditions`), promotion actions
 * (`GET admin/promotion-actions`), and shipping method types
 * (`GET admin/shipping-method-types`), each with the `config_schema` it
 * declares (`null` when it declares none — show a JSON editor).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1;

use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use ArtisanPackUI\Ecommerce\Registries\PromotionActionRegistry;
use ArtisanPackUI\Ecommerce\Registries\PromotionConditionRegistry;
use ArtisanPackUI\Ecommerce\Registries\ShippingMethodTypeRegistry;
use ArtisanPackUI\Ecommerce\Support\ConfigSchema;
use Illuminate\Http\JsonResponse;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class ConfigCatalogController extends ApiController
{
    /**
     * @since 1.0.0
     *
     * @param  PromotionConditionRegistry  $conditions  Condition registry.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'List the available promotion conditions' )]
    public function promotionConditions( PromotionConditionRegistry $conditions ): JsonResponse
    {
        return new JsonResponse( [ 'data' => ConfigSchema::catalog( $conditions ) ] );
    }

    /**
     * @since 1.0.0
     *
     * @param  PromotionActionRegistry  $actions  Action registry.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'List the available promotion actions' )]
    public function promotionActions( PromotionActionRegistry $actions ): JsonResponse
    {
        return new JsonResponse( [ 'data' => ConfigSchema::catalog( $actions ) ] );
    }

    /**
     * @since 1.0.0
     *
     * @param  ShippingMethodTypeRegistry  $types  Shipping method type registry.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'List the available shipping method types' )]
    public function shippingMethodTypes( ShippingMethodTypeRegistry $types ): JsonResponse
    {
        return new JsonResponse( [ 'data' => ConfigSchema::catalog( $types ) ] );
    }
}
