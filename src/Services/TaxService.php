<?php

/**
 * TaxService.
 *
 * Entry point for tax calculation. Resolves the single active
 * {@see TaxProvider} from `artisanpack.ecommerce.tax.provider`, runs the
 * `ap.ecommerce.tax.calculating` / `ap.ecommerce.tax.calculated` filters
 * around it (engine spec §6.5), and guards the provider's output so a
 * mis-behaving satellite can't hand back a result in the wrong currency.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Services;

use ArtisanPackUI\Ecommerce\Contracts\ContextAwareTaxProvider;
use ArtisanPackUI\Ecommerce\Contracts\TaxProvider;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Registries\TaxProviderRegistry;
use ArtisanPackUI\Ecommerce\Tax\ManualTaxProvider;
use ArtisanPackUI\Ecommerce\ValueObjects\Address;
use ArtisanPackUI\Ecommerce\ValueObjects\TaxContext;
use ArtisanPackUI\Ecommerce\ValueObjects\TaxResult;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use UnexpectedValueException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class TaxService
{
    /**
     * @since 1.0.0
     *
     * @param  TaxProviderRegistry  $providers  Registered tax providers.
     * @param  ConfigRepository     $config     Config repository.
     */
    public function __construct(
        private readonly TaxProviderRegistry $providers,
        private readonly ConfigRepository $config,
    ) {
    }

    /**
     * Returns the active provider.
     *
     * @since 1.0.0
     *
     * @return TaxProvider
     */
    public function activeProvider(): TaxProvider
    {
        return $this->providers->get( $this->activeProviderKey() );
    }

    /**
     * Calculates tax for `$cart` shipped to `$destination`.
     *
     * @since 1.0.0
     *
     * @param  Cart     $cart         Cart being taxed.
     * @param  Address  $destination  Destination address.
     * @param  array<int, int>  $lineDiscounts  Discount each line received (cart-item id → minor units), when known.
     *
     * @throws UnexpectedValueException When a listener or provider returns an invalid value.
     *
     * @return TaxResult
     */
    public function calculate( Cart $cart, Address $destination, array $lineDiscounts = [] ): TaxResult
    {
        $context = new TaxContext(
            destination: $destination,
            providerKey: $this->activeProviderKey(),
            pricesIncludeTax: (bool) $this->config->get( 'artisanpack.ecommerce.tax.prices_include_tax', false ),
            lineDiscounts: array_map( 'intval', $lineDiscounts ),
        );

        $context = applyFilters( 'ap.ecommerce.tax.calculating', $context, $cart );

        if ( ! $context instanceof TaxContext ) {
            throw new UnexpectedValueException( 'ap.ecommerce.tax.calculating listeners must return a TaxContext.' );
        }

        $provider = $this->providers->get( $context->providerKey );
        $result   = $provider instanceof ContextAwareTaxProvider
            ? $provider->calculateWithContext( $cart, $context )
            : $provider->calculate( $cart, $context->destination );
        $result = applyFilters( 'ap.ecommerce.tax.calculated', $result, $cart );

        if ( ! $result instanceof TaxResult ) {
            throw new UnexpectedValueException( 'ap.ecommerce.tax.calculated listeners must return a TaxResult.' );
        }

        if ( $result->total->getCurrency()->getCode() !== strtoupper( (string) $cart->currency ) ) {
            throw new UnexpectedValueException( sprintf(
                'Tax provider "%s" returned %s for a %s cart.',
                $context->providerKey,
                $result->total->getCurrency()->getCode(),
                $cart->currency,
            ) );
        }

        return $result;
    }

    /**
     * @since 1.0.0
     *
     * @return string
     */
    protected function activeProviderKey(): string
    {
        return (string) $this->config->get( 'artisanpack.ecommerce.tax.provider', ManualTaxProvider::KEY );
    }
}
