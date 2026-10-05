<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Contracts\PromotionAction;
use ArtisanPackUI\Ecommerce\Contracts\ShippingMethodType;
use ArtisanPackUI\Ecommerce\Gateways\Stripe\StripeGateway;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\ProductTypes\SimpleProductType;
use ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry;
use ArtisanPackUI\Ecommerce\Registries\ProductTypeRegistry;
use ArtisanPackUI\Ecommerce\Registries\PromotionActionRegistry;
use ArtisanPackUI\Ecommerce\Registries\ShippingMethodTypeRegistry;
use ArtisanPackUI\Ecommerce\Shipping\Methods\FlatRateMethod;
use ArtisanPackUI\Ecommerce\Support\DiscountLedger;
use ArtisanPackUI\Ecommerce\Support\RegistryHookRegistrar;
use ArtisanPackUI\Ecommerce\ValueObjects\Address;
use Money\Money;

beforeEach( function (): void {
    $this->registrar = app( RegistryHookRegistrar::class );
} );

it( 'registers what each registered* filter returns into its registry', function (): void {
    $received = [];

    foreach ( array_keys( RegistryHookRegistrar::FILTERS ) as $filter ) {
        addFilter( $filter, function ( array $entries ) use ( &$received, $filter ): array {
            $received[ $filter ] = $entries;

            return $entries;
        } );
    }

    addFilter( 'ap.ecommerce.payment.registeredGateways', fn ( array $entries ): array => $entries + [ StripeGateway::KEY => StripeGateway::class ] );
    $courier = new class implements ShippingMethodType {
        public function key(): string
        {
            return 'courier';
        }

        public function label(): string
        {
            return 'Courier';
        }

        public function calculate( Cart $cart, Address $destination, array $config ): ?Money
        {
            return null;
        }
    };
    $loyalty = new class implements PromotionAction {
        public function key(): string
        {
            return 'loyalty-percent';
        }

        public function label(): string
        {
            return 'Loyalty percent';
        }

        public function apply( Cart $cart, DiscountLedger $ledger, array $config ): void
        {
        }
    };

    addFilter( 'ap.ecommerce.shipping.registeredMethods', fn ( array $entries ): array => $entries + [ 'courier' => $courier ] );
    addFilter( 'ap.ecommerce.product.registeredTypes', fn ( array $entries ): array => $entries + [
        'gift-card' => [ 'entry' => SimpleProductType::class, 'meta' => [ 'label' => 'Gift card' ] ],
    ] );
    addFilter( 'ap.ecommerce.pricing.registeredDiscountTypes', fn ( array $entries ): array => $entries + [ 'loyalty-percent' => $loyalty ] );

    $this->registrar->apply();

    expect( $received )->toBe( array_fill_keys( array_keys( RegistryHookRegistrar::FILTERS ), [] ) );
    expect( app( PaymentGatewayRegistry::class )->get( StripeGateway::KEY ) )->toBeInstanceOf( StripeGateway::class );
    expect( app( ShippingMethodTypeRegistry::class )->get( 'courier' ) )->toBe( $courier );
    expect( app( ProductTypeRegistry::class )->has( 'gift-card' ) )->toBeTrue();
    expect( app( ProductTypeRegistry::class )->meta( 'gift-card' ) )->toBe( [ 'label' => 'Gift card' ] );
    expect( app( PromotionActionRegistry::class )->get( 'loyalty-percent' ) )->toBe( $loyalty );
} );

it( 'is a no-op when nothing hooks the filters', function (): void {
    $before = app( ShippingMethodTypeRegistry::class )->keys();

    $this->registrar->apply();

    expect( app( ShippingMethodTypeRegistry::class )->keys() )->toBe( $before );
} );

it( 'applies the registry contract check to filtered entries', function (): void {
    addFilter( 'ap.ecommerce.shipping.registeredMethods', fn (): array => [ 'bogus' => stdClass::class ] );

    $this->registrar->apply();
} )->throws( InvalidArgumentException::class );

it( 'applies the double-registration policy to filtered entries', function (): void {
    addFilter( 'ap.ecommerce.shipping.registeredMethods', fn (): array => [ FlatRateMethod::KEY => FlatRateMethod::class ] );

    $this->registrar->apply();
} )->throws( InvalidArgumentException::class );

it( 'refuses a filter that returns something other than an array', function (): void {
    addFilter( 'ap.ecommerce.product.registeredTypes', fn (): string => SimpleProductType::class );

    $this->registrar->apply();
} )->throws( UnexpectedValueException::class );

it( 'refuses entries without a string registry key', function (): void {
    addFilter( 'ap.ecommerce.product.registeredTypes', fn (): array => [ SimpleProductType::class ] );

    $this->registrar->apply();
} )->throws( UnexpectedValueException::class );

it( 'refuses entries that are not a class name, instance, or entry array', function (): void {
    addFilter( 'ap.ecommerce.product.registeredTypes', fn (): array => [ 'odd' => [ 'class' => SimpleProductType::class ] ] );

    $this->registrar->apply();
} )->throws( UnexpectedValueException::class );
