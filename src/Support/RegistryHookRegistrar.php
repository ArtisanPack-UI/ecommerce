<?php

/**
 * RegistryHookRegistrar.
 *
 * Runs the four `registered*` filters from the hooks spec once, after
 * every service provider has booted, and registers what they return:
 *
 * | Filter                                         | Registry                       |
 * |------------------------------------------------|--------------------------------|
 * | `ap.ecommerce.payment.registeredGateways`      | {@see PaymentGatewayRegistry}     |
 * | `ap.ecommerce.shipping.registeredMethods`      | {@see ShippingMethodTypeRegistry} |
 * | `ap.ecommerce.product.registeredTypes`         | {@see ProductTypeRegistry}        |
 * | `ap.ecommerce.pricing.registeredDiscountTypes` | {@see PromotionActionRegistry}    |
 *
 * Each filter starts from an empty array and returns entries keyed by
 * registry key. An entry is a class name or instance, or
 * `[ 'entry' => class|instance, 'meta' => [ 'label' => … ] ]`. Every
 * entry goes through the registry's own `register()`, so contract checks
 * and the double-registration policy are the same as for a direct
 * `register()` call from a satellite's `boot()`.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Support;

use ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry;
use ArtisanPackUI\Ecommerce\Registries\ProductTypeRegistry;
use ArtisanPackUI\Ecommerce\Registries\PromotionActionRegistry;
use ArtisanPackUI\Ecommerce\Registries\ShippingMethodTypeRegistry;
use Illuminate\Contracts\Foundation\Application;
use UnexpectedValueException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class RegistryHookRegistrar
{
    /**
     * Filter name → registry class.
     *
     * @since 1.0.0
     *
     * @var array<string, class-string>
     */
    public const FILTERS = [
        'ap.ecommerce.payment.registeredGateways'      => PaymentGatewayRegistry::class,
        'ap.ecommerce.shipping.registeredMethods'      => ShippingMethodTypeRegistry::class,
        'ap.ecommerce.product.registeredTypes'         => ProductTypeRegistry::class,
        'ap.ecommerce.pricing.registeredDiscountTypes' => PromotionActionRegistry::class,
    ];

    /**
     * @since 1.0.0
     *
     * @param  Application  $app  Resolves the registries.
     */
    public function __construct( protected Application $app )
    {
    }

    /**
     * Applies every `registered*` filter and registers the entries returned.
     *
     * @since 1.0.0
     *
     * @throws UnexpectedValueException When a filter returns a non-array or a malformed entry.
     *
     * @return void
     */
    public function apply(): void
    {
        foreach ( self::FILTERS as $filter => $registryClass ) {
            $entries = applyFilters( $filter, [] );

            if ( ! is_array( $entries ) ) {
                throw new UnexpectedValueException( sprintf( '%s must return an array, %s returned.', $filter, get_debug_type( $entries ) ) );
            }

            if ( [] === $entries ) {
                continue;
            }

            $registry = $this->app->make( $registryClass );

            foreach ( $entries as $key => $value ) {
                [ $entry, $meta ] = $this->normalize( $filter, $key, $value );

                $registry->register( $key, $entry, $meta );
            }
        }
    }

    /**
     * Splits a filter entry into the `register()` arguments.
     *
     * @since 1.0.0
     *
     * @param  string      $filter  Filter name, for the error message.
     * @param  int|string  $key     Array key the entry was returned under.
     * @param  mixed       $value   Class name, instance, or `{entry, meta}` array.
     *
     * @throws UnexpectedValueException When the key isn't a string or the entry isn't one of the accepted shapes.
     *
     * @return array{0: object|string, 1: array<string, mixed>}
     */
    protected function normalize( string $filter, int|string $key, mixed $value ): array
    {
        if ( ! is_string( $key ) ) {
            throw new UnexpectedValueException( sprintf( '%s entries must be keyed by registry key; got integer key %d.', $filter, $key ) );
        }

        if ( is_array( $value ) && isset( $value['entry'] ) ) {
            $value = [ $value['entry'], (array) ( $value['meta'] ?? [] ) ];
        } else {
            $value = [ $value, [] ];
        }

        if ( ! is_string( $value[0] ) && ! is_object( $value[0] ) ) {
            throw new UnexpectedValueException( sprintf( '%s entry "%s" must be a class name, an instance, or an [entry, meta] array.', $filter, $key ) );
        }

        return $value;
    }
}
