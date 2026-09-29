<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend( Tests\TestCase::class )
 // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in( 'Feature' );

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend( 'toBeOne', function () {
    return $this->toBe( 1 );
} );

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something(): void
{
    // ..
}

if ( ! function_exists( 'makeAnonymousModel' ) ) {
    /**
     * Builds a throwaway Eloquent model for cast unit tests.
     *
     * Kept in Pest bootstrap so multiple test files can share it without
     * redeclaring a file-scoped helper (which would fatal at load time).
     *
     * @since 1.0.0
     */
    function makeAnonymousModel(): Illuminate\Database\Eloquent\Model
    {
        return new class extends Illuminate\Database\Eloquent\Model {};
    }
}

if ( ! function_exists( 'cartWithLines' ) ) {
    /**
     * Builds a persisted cart whose lines are described by `$lines`, with
     * the `items` relation (and each item's product) loaded.
     *
     * Each line: `[ 'unit' => int, 'qty' => int, 'product' => array|Product, 'variant' => ?ProductVariant ]`.
     *
     * @since 1.0.0
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @param  string                            $currency
     * @param  array<string, mixed>              $cartAttributes
     */
    function cartWithLines( array $lines, string $currency = 'USD', array $cartAttributes = [] ): ArtisanPackUI\Ecommerce\Models\Cart
    {
        $cart = ArtisanPackUI\Ecommerce\Models\Cart::factory()
            ->currency( $currency )
            ->create( $cartAttributes );

        foreach ( $lines as $line ) {
            $product = $line['product'] ?? [];
            $product = $product instanceof ArtisanPackUI\Ecommerce\Models\Product
                ? $product
                : ArtisanPackUI\Ecommerce\Models\Product::factory()->create( $product );

            $unit = (int) ( $line['unit'] ?? 1_000 );
            $qty  = (int) ( $line['qty'] ?? 1 );

            ArtisanPackUI\Ecommerce\Models\CartItem::factory()->create( [
                'cart_id'                => $cart->id,
                'product_id'             => $product->id,
                'product_variant_id'     => isset( $line['variant'] ) ? $line['variant']->id : null,
                'quantity'               => $qty,
                'unit_price_amount'      => $unit,
                'unit_price_currency'    => $currency,
                'line_subtotal_amount'   => $unit * $qty,
                'line_subtotal_currency' => $currency,
                'line_total_amount'      => $unit * $qty,
                'line_total_currency'    => $currency,
            ] );
        }

        return $cart->load( 'items.product' );
    }
}
