<?php

/**
 * CoreSettings.
 *
 * The engine's own allow-list (engine issue #145): the groups an admin
 * shows and the config keys in each that a store owner may change without
 * a deploy. Gateway credentials and signing keys are listed as secrets —
 * their configured status is shown, their values are not.
 *
 * Registered by {@see \ArtisanPackUI\Ecommerce\Providers\EcommerceServiceProvider}
 * early in `boot()`, before anything reads these keys.
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

use ArtisanPackUI\Ecommerce\Models\TaxClass;
use ArtisanPackUI\Ecommerce\Registries\AbstractContractRegistry;
use ArtisanPackUI\Ecommerce\Registries\FraudProviderRegistry;
use ArtisanPackUI\Ecommerce\Registries\FulfillmentAllocationStrategyRegistry;
use ArtisanPackUI\Ecommerce\Registries\KanbanCardWidgetRegistry;
use ArtisanPackUI\Ecommerce\Registries\SettingsRegistry;
use ArtisanPackUI\Ecommerce\Registries\TaxProviderRegistry;
use ArtisanPackUI\Ecommerce\Services\Fraud\StripeRadarFraudProvider;
use Closure;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class CoreSettings
{
    /**
     * Core group keys, in display order.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const GROUPS = [ 'general', 'checkout', 'tax', 'shipping', 'payments', 'notifications', 'reviews', 'digital', 'licenses', 'kanban' ];

    /**
     * Adds the core groups, settings, and secrets.
     *
     * @since 1.0.0
     *
     * @param  SettingsRegistry  $settings  Registry to fill.
     *
     * @return void
     */
    public static function register( SettingsRegistry $settings ): void
    {
        $settings
            ->addGroup( 'general', __( 'General' ), 10 )
            ->addGroup( 'checkout', __( 'Checkout' ), 20 )
            ->addGroup( 'tax', __( 'Tax' ), 30 )
            ->addGroup( 'shipping', __( 'Shipping' ), 40 )
            ->addGroup( 'payments', __( 'Payments' ), 50, __( 'Gateway credentials are read from the environment and are never shown or stored here.' ) )
            ->addGroup( 'notifications', __( 'Notifications' ), 60 )
            ->addGroup( 'reviews', __( 'Reviews' ), 70 )
            ->addGroup( 'digital', __( 'Digital downloads' ), 80 )
            ->addGroup( 'licenses', __( 'License keys' ), 90 )
            ->addGroup( 'kanban', __( 'Kanban' ), 100 );

        foreach ( self::definitions() as $definition ) {
            $settings->define( $definition );
        }

        $settings
            ->addSecret( new SettingSecret( 'artisanpack.ecommerce.gateways.stripe.secret_key', 'payments', __( 'Stripe secret key' ), 'ECOMMERCE_STRIPE_SECRET_KEY' ) )
            ->addSecret( new SettingSecret( 'artisanpack.ecommerce.gateways.stripe.publishable_key', 'payments', __( 'Stripe publishable key' ), 'ECOMMERCE_STRIPE_PUBLISHABLE_KEY' ) )
            ->addSecret( new SettingSecret( 'artisanpack.ecommerce.gateways.stripe.webhook_secret', 'payments', __( 'Stripe webhook signing secret' ), 'ECOMMERCE_STRIPE_WEBHOOK_SECRET' ) );

        app( SettingsRepository::class )->constrain( 'core.payments', self::paymentsConstraint() );
    }

    /**
     * Payment settings that only work together: Stripe needs its secret key
     * to be enabled, and the Stripe Radar fraud provider only exists while
     * Stripe is enabled (it is registered at boot from that setting), so a
     * chain that names it must keep Stripe on.
     *
     * @since 1.0.0
     *
     * @return Closure(array<string, mixed>): array<int, array{field: string|null, code: string, message: string}>
     */
    public static function paymentsConstraint(): Closure
    {
        return static function ( array $values ): array {
            $errors        = [];
            $stripeEnabled = (bool) ( $values['gateways.stripe.enabled'] ?? false );
            $chain         = array_map( 'trim', explode( ',', (string) ( $values['fraud.provider'] ?? '' ) ) );
            $secretKey     = config( 'artisanpack.ecommerce.gateways.stripe.secret_key' );

            if ( $stripeEnabled && ( null === $secretKey || '' === $secretKey ) ) {
                $errors[] = [
                    'field'   => 'gateways.stripe.enabled',
                    'code'    => 'gateway-not-configured',
                    'message' => __( 'Stripe can\'t be enabled until its secret key is set (ECOMMERCE_STRIPE_SECRET_KEY).' ),
                ];
            }

            if ( ! $stripeEnabled && in_array( StripeRadarFraudProvider::KEY, $chain, true ) ) {
                $errors[] = [
                    'field'   => 'fraud.provider',
                    'code'    => 'fraud-provider-unavailable',
                    'message' => __( 'Stripe Radar only works while Stripe is enabled. Remove it from the fraud providers or keep Stripe enabled.' ),
                ];
            }

            return $errors;
        };
    }

    /**
     * The core definitions.
     *
     * @since 1.0.0
     *
     * @return array<int, SettingDefinition>
     */
    public static function definitions(): array
    {
        return [
            // General.
            new SettingDefinition( 'notifications.store_name', 'general', 'string', __( 'Store name' ), [ 'nullable', 'max:120' ], __( 'Used in e-mails and on documents.' ), position: 10 ),
            new SettingDefinition( 'notifications.support_email', 'general', 'email', __( 'Support e-mail' ), [ 'nullable', 'max:255' ], __( 'Where customers are told to write for help.' ), position: 20 ),
            new SettingDefinition( 'base_currency', 'general', 'currency', __( 'Base currency' ), [ 'required' ], __( 'Reports are shown in this currency. Existing orders keep the base currency and exchange rate they were placed with.' ), position: 30 ),
            new SettingDefinition( 'timezone', 'general', 'timezone', __( 'Store time zone' ), [ 'nullable' ], __( 'Report days, weeks, and months start at midnight in this time zone. Leave blank to use the application time zone.' ), position: 40 ),

            // Checkout.
            new SettingDefinition( 'checkout.reservation_ttl_minutes', 'checkout', 'integer', __( 'Stock reservation (minutes)' ), [ 'required', 'min:1', 'max:1440' ], __( 'How long stock stays held for a shopper at checkout.' ), position: 10 ),

            // Tax.
            new SettingDefinition( 'tax.provider', 'tax', 'select', __( 'Tax provider' ), [ 'required' ], __( 'Calculates tax at checkout. Tax satellites add providers here.' ), self::registryOptions( TaxProviderRegistry::class ), position: 10 ),
            new SettingDefinition( 'tax.prices_include_tax', 'tax', 'boolean', __( 'Prices include tax' ), [], __( 'Catalog prices already contain tax, which is back-calculated at checkout.' ), position: 20 ),
            new SettingDefinition( 'tax.default_class', 'tax', 'select', __( 'Default tax class' ), [ 'required' ], null, self::taxClassOptions(), position: 30 ),
            new SettingDefinition( 'tax.shipping_tax_class', 'tax', 'select', __( 'Shipping tax class' ), [ 'required' ], null, self::taxClassOptions(), position: 40 ),
            new SettingDefinition( 'localization.tax_labels', 'tax', 'map', __( 'Tax label per locale' ), [ 'nullable' ], __( 'Override the word used for tax, e.g. "Sales Tax" for en_US.' ), position: 50, itemRules: [ 'max:60' ] ),

            // Shipping.
            new SettingDefinition( 'fulfillment.allocation_strategy', 'shipping', 'select', __( 'Fulfillment allocation' ), [ 'required' ], __( 'How order-level amounts are split across shipments.' ), self::registryOptions( FulfillmentAllocationStrategyRegistry::class ), position: 10 ),

            // Payments.
            new SettingDefinition( 'gateways.stripe.enabled', 'payments', 'boolean', __( 'Enable Stripe' ), [], __( 'Takes effect on the next request. Stripe also needs its keys set in the environment.' ), position: 10 ),
            new SettingDefinition( 'gateways.stripe.capture_method', 'payments', 'select', __( 'Stripe capture' ), [ 'required' ], null, [ 'automatic' => __( 'Capture immediately' ), 'manual' => __( 'Authorize, capture later' ) ], position: 20 ),
            new SettingDefinition( 'fraud.provider', 'payments', 'string', __( 'Fraud providers' ), [ 'required', 'max:255', self::fraudChainRule() ], __( 'One provider key, or several separated by commas to run them in order.' ), position: 30 ),

            // Notifications.
            new SettingDefinition( 'notifications.enabled', 'notifications', 'boolean', __( 'Send notifications' ), [], null, position: 10 ),
            new SettingDefinition( 'notifications.admin_emails', 'notifications', 'list', __( 'Admin recipients' ), [ 'nullable', 'max:20' ], __( 'Who receives store notifications such as new orders.' ), position: 20, itemRules: [ 'email', 'max:255' ] ),
            new SettingDefinition( 'notifications.default_locale', 'notifications', 'string', __( 'Default locale' ), [ 'nullable', 'max:12', 'regex:/^[a-z]{2,3}([_-][A-Za-z0-9]{2,4})?$/' ], __( 'Used when the customer has no locale. Leave blank for the application locale.' ), position: 30 ),
            new SettingDefinition( 'notifications.preference_channels', 'notifications', 'list', __( 'Customer preference channels' ), [ 'nullable' ], __( 'Channels customers can opt in or out of.' ), position: 40, itemRules: [ 'max:40', 'regex:/^[a-z0-9_-]+$/' ] ),
            new SettingDefinition( 'notifications.review_request_delay_days', 'notifications', 'integer', __( 'Review request delay (days)' ), [ 'required', 'min:0', 'max:365' ], __( 'Days after fulfillment before asking for a review.' ), position: 50 ),

            // Reviews.
            new SettingDefinition( 'reviews.allow_guests', 'reviews', 'boolean', __( 'Allow guest reviews' ), [], __( 'Let shoppers who are not signed in submit reviews.' ), position: 10 ),

            // Digital.
            new SettingDefinition( 'digital.auto_issue', 'digital', 'boolean', __( 'Issue downloads automatically' ), [], __( 'Issue download links as soon as an order is paid.' ), position: 10 ),
            new SettingDefinition( 'digital.download_limit', 'digital', 'integer', __( 'Downloads per link' ), [ 'nullable', 'min:1', 'max:1000' ], __( 'Leave blank for unlimited.' ), position: 20 ),
            new SettingDefinition( 'digital.download_expiry_days', 'digital', 'integer', __( 'Link expiry (days)' ), [ 'nullable', 'min:1', 'max:3650' ], __( 'Leave blank for links that never expire.' ), position: 30 ),
            new SettingDefinition( 'digital.revoke_on_refund', 'digital', 'boolean', __( 'Revoke downloads on refund' ), [], null, position: 40 ),

            // Licenses.
            new SettingDefinition( 'licenses.activations_limit', 'licenses', 'integer', __( 'Activations per key' ), [ 'nullable', 'min:1', 'max:100000' ], __( 'Leave blank for unlimited.' ), position: 10 ),
            new SettingDefinition( 'licenses.expires_in_days', 'licenses', 'integer', __( 'Key lifetime (days)' ), [ 'nullable', 'min:1', 'max:36500' ], __( 'Leave blank for keys that never expire.' ), position: 20 ),

            // Kanban.
            new SettingDefinition( 'kanban.auto_route', 'kanban', 'boolean', __( 'Route new orders to boards' ), [], __( 'Place new orders on the first board whose routing rules match.' ), position: 10 ),
            new SettingDefinition( 'kanban.stale_after_days', 'kanban', 'integer', __( 'Stale card after (days)' ), [ 'required', 'min:1', 'max:365' ], null, position: 20 ),
            new SettingDefinition( 'kanban.default_card_widgets', 'kanban', 'multiselect', __( 'Default card widgets' ), [ 'nullable' ], __( 'Shown on cards in columns that do not choose their own.' ), self::registryOptions( KanbanCardWidgetRegistry::class ), position: 30 ),
        ];
    }

    /**
     * Options from a contract registry: key => label meta (or the key).
     *
     * @since 1.0.0
     *
     * @param  class-string<AbstractContractRegistry<object>>  $registry  Registry class.
     *
     * @return Closure(): array<string, string>
     */
    protected static function registryOptions( string $registry ): Closure
    {
        return static function () use ( $registry ): array {
            /** @var AbstractContractRegistry<object> $instance */
            $instance = app( $registry );
            $options  = [];

            foreach ( $instance->keys() as $key ) {
                $options[ $key ] = (string) ( $instance->meta( $key )['label'] ?? $key );
            }

            return $options;
        };
    }

    /**
     * Tax class options: key => label.
     *
     * @since 1.0.0
     *
     * @return Closure(): array<string, string>
     */
    protected static function taxClassOptions(): Closure
    {
        return static function (): array {
            try {
                return TaxClass::query()->orderBy( 'label' )->pluck( 'label', 'key' )->map( 'strval' )->all();
            } catch ( Throwable ) {
                return [];
            }
        };
    }

    /**
     * A rule accepting a comma-separated chain of registered fraud providers.
     *
     * @since 1.0.0
     *
     * @return Closure(string, mixed, Closure): void
     */
    protected static function fraudChainRule(): Closure
    {
        return static function ( string $attribute, mixed $value, Closure $fail ): void {
            if ( ! is_string( $value ) ) {
                return;
            }

            $registry = app( FraudProviderRegistry::class );
            $keys     = array_filter( array_map( 'trim', explode( ',', (string) $value ) ), static fn ( string $key ): bool => '' !== $key );

            if ( [] === $keys ) {
                $fail( __( 'Choose at least one fraud provider.' ) );

                return;
            }

            foreach ( $keys as $key ) {
                if ( ! $registry->has( $key ) ) {
                    $fail( __( 'Fraud provider ":key" is not registered.', [ 'key' => $key ] ) );
                }
            }
        };
    }
}
