<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Auth\TokenAbilities;
use ArtisanPackUI\Ecommerce\Exceptions\CartOperationException;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\Models\InventoryReservation;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductPrice;
use ArtisanPackUI\Ecommerce\Services\CartMergeService;
use ArtisanPackUI\Ecommerce\Services\CurrentCart;
use ArtisanPackUI\Ecommerce\Services\StorefrontCartService;
use ArtisanPackUI\Ecommerce\Support\GuestCartCookie;
use ArtisanPackUI\Ecommerce\ValueObjects\CartMergeResolution;
use ArtisanPackUI\Ecommerce\ValueObjects\PendingCartMerge;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;
use Laravel\Sanctum\Sanctum;
use Tests\Fixtures\ApiUser;

require_once __DIR__ . '/../Api/ApiTestHelpers.php';
require_once __DIR__ . '/../GraphQL/GraphQLTestHelpers.php';

uses( RefreshDatabase::class );

/**
 * A signed-in user with an email (verified when `$verified`).
 */
function currentCartUser( int $id, string $email, bool $verified = false ): ApiUser
{
    $user = $verified
        ? new class( [ 'id' => $id, 'email' => $email, 'email_verified_at' => Carbon::now() ] ) extends ApiUser implements MustVerifyEmail {
            use MustVerifyEmailTrait;
        }
    : new ApiUser( [ 'id' => $id, 'email' => $email ] );

    $user->exists = true;

    return $user;
}

/**
 * A product priced in each given currency.
 *
 * @param  array<string, int>  $prices
 */
function currentCartProduct( array $prices = [ 'USD' => 1_000 ] ): Product
{
    $product = Product::factory()->create();

    foreach ( $prices as $currency => $amount ) {
        ProductPrice::factory()->forPriceable( $product )->create( [ 'currency' => $currency, 'price_amount' => $amount ] );
    }

    return $product;
}

/**
 * Puts a request carrying the guest cookie (and a session) in the container.
 */
function currentCartRequest( ?string $token ): Request
{
    $request = Request::create( '/login', 'POST', [], null === $token ? [] : [ GuestCartCookie::name() => $token ] );
    $request->setLaravelSession( app( 'session.store' ) );
    app()->instance( 'request', $request );

    return $request;
}

beforeEach( function (): void {
    config()->set( 'artisanpack.ecommerce.currency.enabled', [ 'EUR' ] );

    $this->carts   = app( StorefrontCartService::class );
    $this->current = app( CurrentCart::class );
    $this->product = currentCartProduct( [ 'USD' => 1_000, 'EUR' => 900 ] );
} );

describe( 'resolve', function (): void {
    it( 'returns a guest\'s cart by token and creates one only when asked', function (): void {
        $cart = $this->carts->create( 'USD' );

        expect( $this->current->resolve( $cart->token, null )?->id )->toBe( $cart->id )
            ->and( $this->current->resolve( null, null ) )->toBeNull()
            ->and( $this->current->resolve( null, null, true ) )->toBeInstanceOf( Cart::class );
    } );

    it( 'does not hand an account\'s cart to a guest holding its token', function (): void {
        $customer = Customer::factory()->create();
        $cart     = $this->carts->create( 'USD', null, $customer );

        expect( $this->current->resolve( $cart->token, null ) )->toBeNull();
    } );

    it( 'ignores carts that became orders or expired', function ( array $state ): void {
        $cart = $this->carts->create( 'USD' );
        $cart->forceFill( $state )->save();

        expect( $this->current->resolve( $cart->token, null ) )->toBeNull();
    } )->with( [
        'completed' => [ [ 'completed_order_id' => 42 ] ],
        'expired'   => [ [ 'expires_at' => Carbon::now()->subMinute() ] ],
    ] );

    it( 'returns a signed-in shopper\'s account cart', function (): void {
        $user     = currentCartUser( 5, 'ada@example.test' );
        $customer = Customer::factory()->forUser( 5 )->create( [ 'email' => 'ada@example.test' ] );
        $account  = $this->carts->create( 'USD', null, $customer );
        $guest    = $this->carts->create( 'USD' );

        expect( $this->current->resolve( $guest->token, $user )?->id )->toBe( $account->id );
    } );

    it( 'attaches the guest cart to a signed-in shopper who has no cart yet, rotating its token', function (): void {
        $user  = currentCartUser( 5, 'ada@example.test' );
        $guest = $this->carts->create( 'USD' );
        $token = $guest->token;

        $cart = $this->current->resolve( $token, $user );

        expect( $cart->id )->toBe( $guest->id )
            ->and( $cart->token )->not->toBe( $token )
            ->and( $cart->customer->user_id )->toBe( 5 )
            ->and( $cart->email )->toBe( 'ada@example.test' );
    } );

    it( 'never lets an unverified email claim an existing customer', function (): void {
        Customer::factory()->create( [ 'email' => 'ada@example.test' ] );
        $guest = $this->carts->create( 'USD' );

        $cart = $this->current->resolve( $guest->token, currentCartUser( 5, 'ada@example.test' ) );

        expect( $cart->id )->toBe( $guest->id )
            ->and( $cart->customer_id )->toBeNull()
            ->and( Customer::query()->where( 'user_id', 5 )->exists() )->toBeFalse();
    } );

    it( 'links a verified user to the guest customer under their email', function (): void {
        $existing = Customer::factory()->create( [ 'email' => 'ada@example.test' ] );
        $guest    = $this->carts->create( 'USD' );

        $cart = $this->current->resolve( $guest->token, currentCartUser( 5, 'ada@example.test', true ) );

        expect( $cart->customer_id )->toBe( $existing->id )
            ->and( $existing->refresh()->user_id )->toBe( 5 );
    } );
} );

describe( 'attachCustomer', function (): void {
    it( 'refuses a cart that belongs to another customer', function (): void {
        $cart = $this->carts->create( 'USD', null, Customer::factory()->create() );

        expect( fn () => $this->carts->attachCustomer( $cart, Customer::factory()->create() ) )
            ->toThrow( fn ( CartOperationException $e ) => expect( $e->errorCode )->toBe( 'cart-owned' ) );
    } );
} );

describe( 'merge (D13)', function (): void {
    it( 'sums matching lines and re-totals the merged cart', function (): void {
        $customer = Customer::factory()->create();
        $account  = $this->carts->create( 'USD', null, $customer );
        $guest    = $this->carts->create( 'USD' );

        $this->carts->addItem( $account, $this->product->id, null, 1 );
        $this->carts->addItem( $guest, $this->product->id, null, 2 );

        $result = app( CartMergeService::class )->merge( $guest, $account );

        expect( $result->items()->sole()->quantity )->toBe( 3 )
            ->and( $result->refresh()->subtotal_amount )->toBe( 3_000 )
            ->and( $result->total_amount )->toBe( 3_000 );
    } );

    it( 'refuses to merge a cart that became an order', function ( string $which ): void {
        $account = $this->carts->create( 'USD', null, Customer::factory()->create() );
        $guest   = $this->carts->create( 'USD' );
        ( 'guest' === $which ? $guest : $account )->forceFill( [ 'completed_order_id' => 99 ] )->save();

        expect( fn () => app( CartMergeService::class )->merge( $guest, $account ) )
            ->toThrow( fn ( CartOperationException $e ) => expect( $e->errorCode )->toBe( 'cart-closed' ) );

        expect( Cart::query()->whereKey( $guest->id )->exists() )->toBeTrue();
    } )->with( [ 'guest', 'account' ] );

    it( 'caps merged quantities at what is in stock without shrinking the account line', function (): void {
        InventoryItem::factory()->create( [ 'stockable_type' => $this->product->getMorphClass(), 'stockable_id' => $this->product->id, 'quantity_on_hand' => 4 ] );
        $account = $this->carts->create( 'USD', null, Customer::factory()->create() );
        $guest   = $this->carts->create( 'USD' );

        $this->carts->addItem( $account, $this->product->id, null, 3 );
        $this->carts->addItem( $guest, $this->product->id, null, 3 );

        $result = app( CartMergeService::class )->merge( $guest, $account );

        expect( $result->items()->sole()->quantity )->toBe( 4 );
    } );

    it( 'stops carrying new lines at the cart line limit', function (): void {
        config()->set( 'artisanpack.ecommerce.cart.max_lines', 2 );
        $account = $this->carts->create( 'USD', null, Customer::factory()->create() );
        $guest   = $this->carts->create( 'USD' );

        $this->carts->addItem( $account, $this->product->id, null, 1 );
        $this->carts->addItem( $guest, currentCartProduct()->id, null, 1 );
        $this->carts->addItem( $guest, currentCartProduct()->id, null, 1 );

        expect( app( CartMergeService::class )->merge( $guest, $account )->items()->count() )->toBe( 2 );
    } );

    it( 'moves the guest cart\'s stock reservations to the merged cart', function (): void {
        $account     = $this->carts->create( 'USD', null, Customer::factory()->create() );
        $guest       = $this->carts->create( 'USD' );
        $reservation = InventoryReservation::factory()->create( [ 'reservable_type' => $guest->getMorphClass(), 'reservable_id' => $guest->id ] );
        $expiresAt   = $reservation->expires_at->toDateTimeString();

        $result = app( CartMergeService::class )->merge( $guest, $account );

        expect( $reservation->refresh()->reservable_id )->toBe( $result->id )
            ->and( $reservation->expires_at->toDateTimeString() )->toBe( $expiresAt );
    } );

    it( 'leaves promotion-granted lines behind', function (): void {
        $account = $this->carts->create( 'USD', null, Customer::factory()->create() );
        $guest   = $this->carts->create( 'USD' );
        CartItem::factory()->create( [ 'cart_id' => $guest->id, 'product_id' => $this->product->id, 'unit_price_amount' => 0, 'meta' => [ 'free_item' => true, 'promotion_id' => 1 ] ] );

        expect( app( CartMergeService::class )->merge( $guest, $account )->items()->count() )->toBe( 0 );
    } );
} );

describe( 'login merge', function (): void {
    it( 'merges the guest cart from the cookie into the account cart and clears the cookie', function (): void {
        $user     = currentCartUser( 5, 'ada@example.test' );
        $customer = Customer::factory()->forUser( 5 )->create( [ 'email' => 'ada@example.test' ] );
        $account  = $this->carts->create( 'USD', null, $customer );
        $guest    = $this->carts->create( 'USD' );
        $this->carts->addItem( $account, $this->product->id, null, 1 );
        $this->carts->addItem( $guest, $this->product->id, null, 2 );

        currentCartRequest( $guest->token );
        event( new Login( 'web', $user, false ) );

        expect( Cart::query()->whereKey( $guest->id )->exists() )->toBeFalse()
            ->and( $account->items()->sole()->quantity )->toBe( 3 )
            ->and( Cookie::queued( GuestCartCookie::name() )?->getValue() )->toBeNull();
    } );

    it( 'gives a shopper without a saved cart their guest cart', function (): void {
        $guest = $this->carts->create( 'USD' );

        currentCartRequest( $guest->token );
        event( new Login( 'web', currentCartUser( 5, 'ada@example.test' ), false ) );

        expect( $guest->refresh()->customer->user_id )->toBe( 5 );
    } );

    it( 'leaves a currency mismatch pending in the session until the shopper chooses', function (): void {
        $user     = currentCartUser( 5, 'ada@example.test' );
        $customer = Customer::factory()->forUser( 5 )->create( [ 'email' => 'ada@example.test' ] );
        $account  = $this->carts->create( 'EUR', null, $customer );
        $guest    = $this->carts->create( 'USD' );
        $this->carts->addItem( $guest, $this->product->id, null, 2 );

        $request = currentCartRequest( $guest->token );
        event( new Login( 'web', $user, false ) );

        $pending = $this->current->pendingMerge( $request->session() );

        expect( $pending )->toBeInstanceOf( PendingCartMerge::class )
            ->and( $pending->toPublicArray() )->toBe( [
                'guest_currency'   => 'USD',
                'account_currency' => 'EUR',
                'resolutions'      => [ 'keep_guest_currency', 'switch_to_account_currency', 'cancel_merge' ],
            ] )
            ->and( Cart::query()->whereKey( $guest->id )->exists() )->toBeTrue();

        $cart = $this->current->resolvePendingMerge( $request->session(), $user, CartMergeResolution::SwitchToAccountCurrency );

        expect( $cart->id )->toBe( $account->id )
            ->and( $cart->items()->sole()->unit_price_amount )->toBe( 900 )
            ->and( $this->current->pendingMerge( $request->session() ) )->toBeNull();
    } );

    it( 'does nothing when merging on login is off', function (): void {
        config()->set( 'artisanpack.ecommerce.cart.merge_on_login', false );
        $guest = $this->carts->create( 'USD' );

        currentCartRequest( $guest->token );
        event( new Login( 'web', currentCartUser( 5, 'ada@example.test' ), false ) );

        expect( $guest->refresh()->customer_id )->toBeNull();
    } );
} );

describe( 'REST', function (): void {
    it( 'requires a signed-in shopper to merge', function (): void {
        $guest = $this->carts->create( 'USD' );

        $this->postJson( "/api/ecommerce/v1/carts/{$guest->token}/merge", [], idem() )->assertStatus( 401 );
    } );

    it( 'merges a guest cart into the shopper\'s cart', function (): void {
        $customer = Customer::factory()->forUser( 5 )->create( [ 'email' => 'ada@example.test' ] );
        $account  = $this->carts->create( 'USD', null, $customer );
        $guest    = $this->carts->create( 'USD' );
        $this->carts->addItem( $guest, $this->product->id, null, 2 );

        Sanctum::actingAs( currentCartUser( 5, 'ada@example.test' ), [ TokenAbilities::STOREFRONT ] );

        $this->postJson( "/api/ecommerce/v1/carts/{$guest->token}/merge", [], idem() )
            ->assertOk()
            ->assertJsonPath( 'data.token', $account->refresh()->token )
            ->assertJsonPath( 'data.subtotal.amount', 2_000 );
    } );

    it( 'answers 409 with the choices on a currency mismatch, then merges with a resolution', function (): void {
        $customer = Customer::factory()->forUser( 5 )->create( [ 'email' => 'ada@example.test' ] );
        $this->carts->create( 'EUR', null, $customer );
        $guest = $this->carts->create( 'USD' );
        $this->carts->addItem( $guest, $this->product->id, null, 1 );

        Sanctum::actingAs( currentCartUser( 5, 'ada@example.test' ), [ TokenAbilities::STOREFRONT ] );

        $this->postJson( "/api/ecommerce/v1/carts/{$guest->token}/merge", [], idem() )
            ->assertStatus( 409 )
            ->assertJsonPath( 'errors.0.code', 'currency-mismatch' )
            ->assertJsonPath( 'merge.account_currency', 'EUR' );

        $this->postJson( "/api/ecommerce/v1/carts/{$guest->token}/merge", [ 'resolution' => 'keep_guest_currency' ], idem() )
            ->assertOk()
            ->assertJsonPath( 'data.currency', 'USD' );

        $this->postJson( "/api/ecommerce/v1/carts/{$guest->token}/merge", [ 'resolution' => 'nope' ], idem() )
            ->assertStatus( 422 );
    } );

    it( 'opens an account\'s cart by token only to that account', function (): void {
        $customer = Customer::factory()->forUser( 5 )->create();
        $cart     = $this->carts->create( 'USD', null, $customer );

        $this->getJson( "/api/ecommerce/v1/carts/{$cart->token}" )->assertNotFound();

        Sanctum::actingAs( currentCartUser( 6, 'eve@example.test' ), [ TokenAbilities::STOREFRONT ] );
        $this->getJson( "/api/ecommerce/v1/carts/{$cart->token}" )->assertNotFound();

        Sanctum::actingAs( currentCartUser( 5, 'ada@example.test' ), [ TokenAbilities::STOREFRONT ] );
        $this->getJson( "/api/ecommerce/v1/carts/{$cart->token}" )->assertOk();
    } );

    it( 'gives a cart created by a signed-in shopper to their account', function (): void {
        Sanctum::actingAs( currentCartUser( 5, 'ada@example.test' ), [ TokenAbilities::STOREFRONT ] );

        $this->postJson( '/api/ecommerce/v1/carts', [ 'currency' => 'USD' ], idem() )->assertCreated();

        expect( Cart::query()->sole()->customer->user_id )->toBe( 5 );
    } );
} );

describe( 'GraphQL', function (): void {
    it( 'merges through mergeCart and reports a pending currency choice', function (): void {
        $customer = Customer::factory()->forUser( 5 )->create( [ 'email' => 'ada@example.test' ] );
        $this->carts->create( 'EUR', null, $customer );
        $guest    = $this->carts->create( 'USD' );
        $mutation = 'mutation ($t: String!, $r: String) { mergeCart(input: { cart_token: $t, resolution: $r }) { merged cart { currency } pending { account_currency resolutions } errors { code } } }';

        gql( $this, $mutation, [ 't' => $guest->token ] )->assertJsonPath( 'errors.0.extensions.code', 'UNAUTHENTICATED' );

        Sanctum::actingAs( currentCartUser( 5, 'ada@example.test' ), [ TokenAbilities::STOREFRONT ] );

        gql( $this, $mutation, [ 't' => $guest->token ] )
            ->assertJsonPath( 'data.mergeCart.merged', false )
            ->assertJsonPath( 'data.mergeCart.pending.account_currency', 'EUR' )
            ->assertJsonPath( 'data.mergeCart.errors.0.code', 'currency-mismatch' );

        gql( $this, $mutation, [ 't' => $guest->token, 'r' => 'switch_to_account_currency' ] )
            ->assertJsonPath( 'data.mergeCart.merged', true )
            ->assertJsonPath( 'data.mergeCart.cart.currency', 'EUR' );
    } );
} );

it( 'reads the guest cookie name and lifetime from config', function (): void {
    config()->set( 'artisanpack.ecommerce.cart.cookie', 'shop_cart' );
    config()->set( 'artisanpack.ecommerce.cart.cookie_lifetime', 60 );
    $cart = $this->carts->create( 'USD' );

    $cookie = GuestCartCookie::make( $cart );

    expect( $cookie->getName() )->toBe( 'shop_cart' )
        ->and( $cookie->getValue() )->toBe( $cart->token )
        ->and( $cookie->isHttpOnly() )->toBeTrue()
        ->and( GuestCartCookie::tokenFrom( Request::create( '/', 'GET', [], [ 'shop_cart' => $cart->token ] ) ) )->toBe( $cart->token )
        ->and( GuestCartCookie::tokenFrom( Request::create( '/', 'GET', [], [ 'shop_cart' => 'short' ] ) ) )->toBeNull();
} );
