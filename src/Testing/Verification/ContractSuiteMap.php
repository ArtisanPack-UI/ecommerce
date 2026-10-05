<?php

/**
 * ContractSuiteMap.
 *
 * The single lookup table the satellite verifier uses to connect an engine
 * contract to the registry (or container binding) that holds its
 * implementations and to the abstract contract-test suite that proves them.
 *
 * Parent plan §15.2.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Testing\Verification;

use ArtisanPackUI\Ecommerce\Contracts\CartStorage;
use ArtisanPackUI\Ecommerce\Contracts\CurrencyRateProvider;
use ArtisanPackUI\Ecommerce\Contracts\FraudProvider;
use ArtisanPackUI\Ecommerce\Contracts\FulfillmentAllocationStrategy;
use ArtisanPackUI\Ecommerce\Contracts\KanbanAutomationTrigger;
use ArtisanPackUI\Ecommerce\Contracts\KanbanCardWidget;
use ArtisanPackUI\Ecommerce\Contracts\NotificationTemplate;
use ArtisanPackUI\Ecommerce\Contracts\OrderNumberGenerator;
use ArtisanPackUI\Ecommerce\Contracts\PaymentGateway;
use ArtisanPackUI\Ecommerce\Contracts\ProductType;
use ArtisanPackUI\Ecommerce\Contracts\PromotionAction;
use ArtisanPackUI\Ecommerce\Contracts\PromotionCondition;
use ArtisanPackUI\Ecommerce\Contracts\ReviewModerator;
use ArtisanPackUI\Ecommerce\Contracts\SearchIndexer;
use ArtisanPackUI\Ecommerce\Contracts\SearchProvider;
use ArtisanPackUI\Ecommerce\Contracts\ShippingLabelProvider;
use ArtisanPackUI\Ecommerce\Contracts\ShippingMethodType;
use ArtisanPackUI\Ecommerce\Contracts\ShippingRateProvider;
use ArtisanPackUI\Ecommerce\Contracts\TaxProvider;
use ArtisanPackUI\Ecommerce\Registries\CurrencyRateProviderRegistry;
use ArtisanPackUI\Ecommerce\Registries\FraudProviderRegistry;
use ArtisanPackUI\Ecommerce\Registries\FulfillmentAllocationStrategyRegistry;
use ArtisanPackUI\Ecommerce\Registries\KanbanAutomationRegistry;
use ArtisanPackUI\Ecommerce\Registries\KanbanCardWidgetRegistry;
use ArtisanPackUI\Ecommerce\Registries\NotificationTemplateRegistry;
use ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry;
use ArtisanPackUI\Ecommerce\Registries\ProductTypeRegistry;
use ArtisanPackUI\Ecommerce\Registries\PromotionActionRegistry;
use ArtisanPackUI\Ecommerce\Registries\PromotionConditionRegistry;
use ArtisanPackUI\Ecommerce\Registries\SearchIndexerRegistry;
use ArtisanPackUI\Ecommerce\Registries\SearchProviderRegistry;
use ArtisanPackUI\Ecommerce\Registries\ShippingLabelProviderRegistry;
use ArtisanPackUI\Ecommerce\Registries\ShippingMethodTypeRegistry;
use ArtisanPackUI\Ecommerce\Registries\ShippingRateProviderRegistry;
use ArtisanPackUI\Ecommerce\Registries\TaxProviderRegistry;
use ArtisanPackUI\Ecommerce\Testing\Contracts\CartStorageContractTest;
use ArtisanPackUI\Ecommerce\Testing\Contracts\FraudProviderContractTest;
use ArtisanPackUI\Ecommerce\Testing\Contracts\FulfillmentAllocationStrategyContractTest;
use ArtisanPackUI\Ecommerce\Testing\Contracts\KanbanAutomationTriggerContractTest;
use ArtisanPackUI\Ecommerce\Testing\Contracts\KanbanCardWidgetContractTest;
use ArtisanPackUI\Ecommerce\Testing\Contracts\NotificationTemplateContractTest;
use ArtisanPackUI\Ecommerce\Testing\Contracts\OrderNumberGeneratorContractTest;
use ArtisanPackUI\Ecommerce\Testing\Contracts\PaymentGatewayContractTest;
use ArtisanPackUI\Ecommerce\Testing\Contracts\ProductTypeContractTest;
use ArtisanPackUI\Ecommerce\Testing\Contracts\PromotionActionContractTest;
use ArtisanPackUI\Ecommerce\Testing\Contracts\PromotionConditionContractTest;
use ArtisanPackUI\Ecommerce\Testing\Contracts\ReviewModeratorContractTest;
use ArtisanPackUI\Ecommerce\Testing\Contracts\SearchProviderContractTest;
use ArtisanPackUI\Ecommerce\Testing\Contracts\ShippingRateProviderContractTest;
use ArtisanPackUI\Ecommerce\Testing\Contracts\TaxProviderContractTest;

/**
 * Contract ↔ registry ↔ suite lookup table.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class ContractSuiteMap
{
    /**
     * Registries walked during discovery, mapped to the contract they hold.
     *
     * @since 1.0.0
     *
     * @var array<class-string, class-string>
     */
    public const REGISTRIES = [
        ProductTypeRegistry::class                   => ProductType::class,
        PaymentGatewayRegistry::class                => PaymentGateway::class,
        ShippingRateProviderRegistry::class          => ShippingRateProvider::class,
        ShippingLabelProviderRegistry::class         => ShippingLabelProvider::class,
        TaxProviderRegistry::class                   => TaxProvider::class,
        CurrencyRateProviderRegistry::class          => CurrencyRateProvider::class,
        FraudProviderRegistry::class                 => FraudProvider::class,
        FulfillmentAllocationStrategyRegistry::class => FulfillmentAllocationStrategy::class,
        PromotionConditionRegistry::class            => PromotionCondition::class,
        PromotionActionRegistry::class               => PromotionAction::class,
        KanbanCardWidgetRegistry::class              => KanbanCardWidget::class,
        KanbanAutomationRegistry::class              => KanbanAutomationTrigger::class,
        NotificationTemplateRegistry::class          => NotificationTemplate::class,
        ShippingMethodTypeRegistry::class            => ShippingMethodType::class,
        SearchProviderRegistry::class                => SearchProvider::class,
        SearchIndexerRegistry::class                 => SearchIndexer::class,
    ];

    /**
     * Single-implementation contracts bound straight into the container.
     *
     * @since 1.0.0
     *
     * @var array<int, class-string>
     */
    public const BINDINGS = [
        CartStorage::class,
        OrderNumberGenerator::class,
        ReviewModerator::class,
    ];

    /**
     * Contracts mapped to the abstract suite satellites extend. Contracts
     * absent from this map have no shared suite yet and report `no-suite`.
     *
     * @since 1.0.0
     *
     * @var array<class-string, class-string>
     */
    public const SUITES = [
        ProductType::class                   => ProductTypeContractTest::class,
        PaymentGateway::class                => PaymentGatewayContractTest::class,
        ShippingRateProvider::class          => ShippingRateProviderContractTest::class,
        TaxProvider::class                   => TaxProviderContractTest::class,
        FraudProvider::class                 => FraudProviderContractTest::class,
        FulfillmentAllocationStrategy::class => FulfillmentAllocationStrategyContractTest::class,
        PromotionCondition::class            => PromotionConditionContractTest::class,
        PromotionAction::class               => PromotionActionContractTest::class,
        KanbanCardWidget::class              => KanbanCardWidgetContractTest::class,
        KanbanAutomationTrigger::class       => KanbanAutomationTriggerContractTest::class,
        NotificationTemplate::class          => NotificationTemplateContractTest::class,
        CartStorage::class                   => CartStorageContractTest::class,
        OrderNumberGenerator::class          => OrderNumberGeneratorContractTest::class,
        ReviewModerator::class               => ReviewModeratorContractTest::class,
        SearchProvider::class                => SearchProviderContractTest::class,
    ];

    /**
     * The abstract suite for `$contract`, or null when none ships.
     *
     * @since 1.0.0
     *
     * @param  string  $contract  Contract interface FQCN.
     *
     * @return class-string|null
     */
    public static function suiteFor( string $contract ): ?string
    {
        return self::SUITES[ $contract ] ?? null;
    }

    /**
     * Short (unqualified) name of a class.
     *
     * @since 1.0.0
     *
     * @param  string  $class  Class FQCN.
     *
     * @return string
     */
    public static function shortName( string $class ): string
    {
        $position = strrpos( $class, '\\' );

        return false === $position ? $class : substr( $class, $position + 1 );
    }
}
