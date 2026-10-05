<?php

/**
 * Queries.
 *
 * Root `Query` fields of the ecommerce GraphQL schema (engine spec §10.2).
 * Each field enforces the same auth and rate-limit policy as its REST
 * counterpart (§9): catalog fields are public (`ecommerce.catalog.read`),
 * `cart` is addressed by its token (`ecommerce.cart.mutate`), `me` /
 * `myOrders` need a signed-in shopper with a storefront-capable token, and
 * admin fields need the matching `ecommerce.{resource}.{action}` ability
 * (`ecommerce.admin.mutate`).
 *
 * Nested relations are eager-loaded from the selection, so e.g.
 * `product { variants { prices } attributes { values } }` is four queries
 * however many variants there are.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\GraphQL\Fields;

use ArtisanPackUI\Ecommerce\Auth\TokenAbilities;
use ArtisanPackUI\Ecommerce\GraphQL\GraphQLError;
use ArtisanPackUI\Ecommerce\GraphQL\Support\Resolvers;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\Models\NotificationTemplate;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\Promotion;
use ArtisanPackUI\Ecommerce\Models\Refund;
use ArtisanPackUI\Ecommerce\Models\ShippingZone;
use ArtisanPackUI\Ecommerce\Models\TaxClass;
use ArtisanPackUI\Ecommerce\Models\TaxRate;
use ArtisanPackUI\Ecommerce\Models\WebhookSubscription;
use ArtisanPackUI\Ecommerce\Registries\SubStatusRegistry;
use ArtisanPackUI\Ecommerce\Services\NotificationTemplateService;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class Queries
{
    /**
     * Rate policy for public catalog fields.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected const CATALOG = 'ecommerce.catalog.read';

    /**
     * Rate policy for authenticated / admin fields.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected const ADMIN = 'ecommerce.admin.mutate';

    /**
     * Deepest result offset `search` will page to.
     *
     * @since 1.0.0
     *
     * @var int
     */
    protected const MAX_SEARCH_OFFSET = 10_000;

    /**
     * @since 1.0.0
     *
     * @param  Resolvers  $r  Shared resolver plumbing.
     */
    public function __construct( protected Resolvers $r )
    {
    }

    /**
     * Filter input types used by the list fields.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, mixed>>
     */
    public function types(): array
    {
        return [
            'ProductFilter' => [
                'kind'   => 'input',
                'fields' => [ 'type' => 'String', 'sku' => 'String', 'slug' => 'String', 'search' => 'String' ],
            ],
            'OrderFilter' => [
                'kind'   => 'input',
                'fields' => [
                    'system_status'      => 'String',
                    'payment_status'     => 'String',
                    'fulfillment_status' => 'String',
                    'customer_id'        => 'Int',
                    'email'              => 'String',
                    'order_number'       => 'String',
                ],
            ],
            'CustomerFilter' => [
                'kind'   => 'input',
                'fields' => [ 'email' => 'String' ],
            ],
        ];
    }

    /**
     * Root query fields.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, mixed>>
     */
    public function fields(): array
    {
        $page = [ 'first' => 'Int', 'after' => 'String' ];

        return [
            // Catalog (public).
            'product' => [
                'type'    => 'Product',
                'args'    => [ 'id' => 'ID!' ],
                'resolve' => fn ( $root, array $args, $context, ResolveInfo $info ): ?array => $this->catalog(
                    fn () => $this->r->present( Product::query()->storefrontVisible()->find( $args['id'] ), 'Product', $this->r->selection( $info ) ),
                ),
            ],
            'productBySlug' => [
                'type'    => 'Product',
                'args'    => [ 'slug' => 'String!' ],
                'resolve' => fn ( $root, array $args, $context, ResolveInfo $info ): ?array => $this->catalog(
                    fn () => $this->r->present( Product::query()->storefrontVisible()->where( 'slug', $args['slug'] )->first(), 'Product', $this->r->selection( $info ) ),
                ),
            ],
            'products' => [
                'type'    => 'ProductConnection!',
                'args'    => [ 'filter' => 'ProductFilter' ] + $page,
                'resolve' => fn ( $root, array $args, $context, ResolveInfo $info ): array => $this->catalog(
                    fn () => $this->r->connection( $this->filterProducts( Product::query()->storefrontVisible(), (array) ( $args['filter'] ?? [] ) ), 'Product', $args, $info ),
                ),
            ],
            'search' => [
                'type'        => 'ProductConnection!',
                'description' => 'Full-text product search through Laravel Scout.',
                'args'        => [ 'query' => 'String!' ] + $page,
                'resolve'     => fn ( $root, array $args, $context, ResolveInfo $info ): array => $this->catalog(
                    fn () => $this->search( $args, $info ),
                ),
            ],

            // Cart (the token is the credential for a guest cart).
            'cart' => [
                'type'    => 'Cart',
                'args'    => [ 'token' => 'String!' ],
                'resolve' => function ( $root, array $args, $context, ResolveInfo $info ): ?array {
                    $this->r->throttle( 'ecommerce.cart.mutate', [ 'cart_token' => $args['token'] ] );

                    $cart = Cart::query()->where( 'token', $args['token'] )->first();

                    // An account's cart also needs that account's session (engine spec §9.2).
                    if ( null !== $cart && ! $cart->isAccessibleBy( $this->r->user() ) ) {
                        $cart = null;
                    }

                    return $this->r->present( $cart, 'Cart', $this->r->selection( $info ) );
                },
            ],

            // Shopper.
            'me' => [
                'type'    => 'Customer',
                'resolve' => function ( $root, array $args, $context, ResolveInfo $info ): ?array {
                    $user = $this->shopper();

                    return $this->r->present( $this->customerFor( $user ), 'Customer', $this->r->selection( $info ) );
                },
            ],
            'myOrders' => [
                'type'    => 'OrderConnection!',
                'args'    => $page,
                'resolve' => function ( $root, array $args, $context, ResolveInfo $info ): array {
                    $customer = $this->customerFor( $this->shopper() );
                    $query    = Order::query()->where( 'customer_id', $customer?->id ?? 0 );

                    return $this->r->connection( $query, 'Order', $args, $info );
                },
            ],
            'order' => [
                'type'    => 'Order',
                'args'    => [ 'id' => 'ID!' ],
                'resolve' => fn ( $root, array $args, $context, ResolveInfo $info ): ?array => $this->order( $args, $info ),
            ],

            // Admin.
            'orders' => [
                'type'    => 'OrderConnection!',
                'args'    => [ 'filter' => 'OrderFilter' ] + $page,
                'resolve' => fn ( $root, array $args, $context, ResolveInfo $info ): array => $this->admin(
                    'order',
                    'viewAny',
                    fn () => $this->r->connection( $this->filterOrders( Order::query(), (array) ( $args['filter'] ?? [] ) ), 'Order', $args, $info, true ),
                ),
            ],
            'refund'    => $this->adminFind( 'Refund', 'refund', 'view', Refund::class ),
            'customer'  => $this->adminFind( 'Customer', 'customer', 'view', Customer::class ),
            'customers' => [
                'type'    => 'CustomerConnection!',
                'args'    => [ 'filter' => 'CustomerFilter' ] + $page,
                'resolve' => fn ( $root, array $args, $context, ResolveInfo $info ): array => $this->admin(
                    'customer',
                    'viewAny',
                    fn () => $this->r->connection(
                        Customer::query()->when( $args['filter']['email'] ?? null, static fn ( Builder $q, string $email ) => $q->where( 'email', $email ) ),
                        'Customer',
                        $args,
                        $info,
                        true,
                    ),
                ),
            ],
            'orderSubstatuses' => [
                'type'        => '[OrderSubstatus!]!',
                'description' => 'Order sub-statuses grouped by system status, in position order. Pass systemStatus to list one group.',
                'args'        => [ 'systemStatus' => 'String' ],
                'resolve'     => fn ( $root, array $args, $context, ResolveInfo $info ): array => $this->admin(
                    'orderSubstatus',
                    'viewAny',
                    function () use ( $args ): array {
                        $registry = app( SubStatusRegistry::class );

                        return $this->r->renderMany(
                            null === ( $args['systemStatus'] ?? null ) ? $registry->all() : $registry->forSystemStatus( (string) $args['systemStatus'] ),
                            true,
                        );
                    },
                ),
            ],
            'promotion'             => $this->adminFind( 'Promotion', 'promotion', 'view', Promotion::class ),
            'promotions'            => $this->adminConnection( 'Promotion', 'promotion', Promotion::class ),
            'taxClasses'            => $this->adminList( 'TaxClass', 'taxRate', TaxClass::class, 'key' ),
            'taxRates'              => $this->adminConnection( 'TaxRate', 'taxRate', TaxRate::class ),
            'shippingZones'         => $this->adminList( 'ShippingZone', 'shippingZone', ShippingZone::class, 'priority' ),
            'shippingZone'          => $this->adminFind( 'ShippingZone', 'shippingZone', 'viewAny', ShippingZone::class ),
            'inventoryItems'        => $this->adminConnection( 'InventoryItem', 'inventory', InventoryItem::class ),
            'webhookSubscriptions'  => $this->adminList( 'WebhookSubscription', 'webhookSubscription', WebhookSubscription::class, 'name' ),
            'webhookSubscription'   => $this->adminFind( 'WebhookSubscription', 'webhookSubscription', 'viewAny', WebhookSubscription::class ),
            'notificationTemplates' => [
                'type'        => '[NotificationTemplate!]!',
                'description' => 'Every notification template, with its declared variables for editor autocomplete.',
                'resolve'     => fn ( $root, array $args, $context, ResolveInfo $info ): array => $this->admin(
                    'notificationTemplate',
                    'viewAny',
                    function (): array {
                        app( NotificationTemplateService::class )->sync();

                        return $this->r->renderMany( NotificationTemplate::query()->orderBy( 'key' )->orderBy( 'locale' )->get(), true );
                    },
                ),
            ],
            'notificationTemplate' => $this->adminFind( 'NotificationTemplate', 'notificationTemplate', 'view', NotificationTemplate::class ),
        ];
    }

    /**
     * Runs a public catalog resolver under the catalog rate policy.
     *
     * @since 1.0.0
     *
     * @template T
     *
     * @param  callable(): T  $resolve  Resolver.
     *
     * @return T
     */
    protected function catalog( callable $resolve ): mixed
    {
        $this->r->throttle( self::CATALOG );

        return $resolve();
    }

    /**
     * Runs an admin resolver after the ability check and admin rate policy.
     *
     * @since 1.0.0
     *
     * @template T
     *
     * @param  string         $resource  Resource name.
     * @param  string         $action    Action name.
     * @param  callable(): T  $resolve   Resolver.
     *
     * @return T
     */
    protected function admin( string $resource, string $action, callable $resolve ): mixed
    {
        $this->r->authorize( $resource, $action );
        $this->r->throttle( self::ADMIN );

        return $resolve();
    }

    /**
     * An admin `thing(id: ID!)` field.
     *
     * @since 1.0.0
     *
     * @param  string                                           $type      GraphQL type.
     * @param  string                                           $resource  Resource name.
     * @param  string                                           $action    Action name.
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model     Model class.
     *
     * @return array<string, mixed>
     */
    protected function adminFind( string $type, string $resource, string $action, string $model ): array
    {
        return [
            'type'    => $type,
            'args'    => [ 'id' => 'ID!' ],
            'resolve' => fn ( $root, array $args, $context, ResolveInfo $info ): ?array => $this->admin(
                $resource,
                $action,
                fn () => $this->r->present( $model::query()->find( $args['id'] ), $type, $this->r->selection( $info ), true ),
            ),
        ];
    }

    /**
     * An admin connection field.
     *
     * @since 1.0.0
     *
     * @param  string                                           $type      GraphQL type.
     * @param  string                                           $resource  Resource name.
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model     Model class.
     *
     * @return array<string, mixed>
     */
    protected function adminConnection( string $type, string $resource, string $model ): array
    {
        return [
            'type'    => $type . 'Connection!',
            'args'    => [ 'first' => 'Int', 'after' => 'String' ],
            'resolve' => fn ( $root, array $args, $context, ResolveInfo $info ): array => $this->admin(
                $resource,
                'viewAny',
                fn () => $this->r->connection( $model::query(), $type, $args, $info, true ),
            ),
        ];
    }

    /**
     * An admin plain-list field for small, unpaginated tables.
     *
     * @since 1.0.0
     *
     * @param  string                                           $type      GraphQL type.
     * @param  string                                           $resource  Resource name.
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model     Model class.
     * @param  string                                           $orderBy   Sort column.
     *
     * @return array<string, mixed>
     */
    protected function adminList( string $type, string $resource, string $model, string $orderBy ): array
    {
        return [
            'type'    => '[' . $type . '!]!',
            'resolve' => fn ( $root, array $args, $context, ResolveInfo $info ): array => $this->admin(
                $resource,
                'viewAny',
                fn () => $this->r->renderMany(
                    $model::query()->with( $this->r->eagerLoads( $type, $this->r->selection( $info ), true ) )->orderBy( $orderBy )->get(),
                    true,
                ),
            ),
        ];
    }

    /**
     * `order(id:)` — admins with `ecommerce.order.view`, or the shopper who
     * owns the order (via {@see \ArtisanPackUI\Ecommerce\Policies\OrderPolicy}).
     * Callers who are neither get FORBIDDEN whether or not the id exists,
     * so the field can't be used to probe for order ids.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $args  Arguments.
     * @param  ResolveInfo           $info  Resolve info.
     *
     * @throws GraphQLError When not allowed.
     *
     * @return array<string, mixed>|null
     */
    protected function order( array $args, ResolveInfo $info ): ?array
    {
        $user = $this->r->requireUser();
        $this->r->throttle( self::ADMIN );

        $order = Order::query()->find( $args['id'] );
        $admin = $this->r->allows( 'order', 'view' );

        if ( ! $admin && ( null === $order || ! Gate::forUser( $user )->allows( 'view', $order ) ) ) {
            throw GraphQLError::forbidden( 'ecommerce.order.view' );
        }

        return $this->r->present( $order, 'Order', $this->r->selection( $info ), $admin );
    }

    /**
     * The signed-in shopper, whose token must allow storefront access.
     *
     * @since 1.0.0
     *
     * @throws GraphQLError When unauthenticated or the token is not storefront-capable.
     *
     * @return Authenticatable
     */
    protected function shopper(): Authenticatable
    {
        $user = $this->r->requireUser();

        if ( ! TokenAbilities::allowsStorefront( $user ) ) {
            throw GraphQLError::forbidden( TokenAbilities::STOREFRONT );
        }

        $this->r->throttle( self::ADMIN );

        return $user;
    }

    /**
     * The customer record linked to a user.
     *
     * @since 1.0.0
     *
     * @param  Authenticatable  $user  User.
     *
     * @return Customer|null
     */
    protected function customerFor( Authenticatable $user ): ?Customer
    {
        $id = $user->getAuthIdentifier();

        return is_numeric( $id ) ? Customer::query()->where( 'user_id', (int) $id )->first() : null;
    }

    /**
     * Applies `ProductFilter`.
     *
     * @since 1.0.0
     *
     * @param  Builder<Product>      $query   Query.
     * @param  array<string, mixed>  $filter  Filter.
     *
     * @return Builder<Product>
     */
    protected function filterProducts( Builder $query, array $filter ): Builder
    {
        foreach ( [ 'type', 'sku', 'slug' ] as $column ) {
            if ( isset( $filter[ $column ] ) ) {
                $query->where( $column, $filter[ $column ] );
            }
        }

        if ( isset( $filter['search'] ) && '' !== $filter['search'] ) {
            $query->whereRaw( "name LIKE ? ESCAPE '!'", [ '%' . str_replace( [ '!', '%', '_' ], [ '!!', '!%', '!_' ], (string) $filter['search'] ) . '%' ] );
        }

        return $query;
    }

    /**
     * Applies `OrderFilter`.
     *
     * @since 1.0.0
     *
     * @param  Builder<Order>        $query   Query.
     * @param  array<string, mixed>  $filter  Filter.
     *
     * @return Builder<Order>
     */
    protected function filterOrders( Builder $query, array $filter ): Builder
    {
        foreach ( [ 'system_status', 'payment_status', 'fulfillment_status', 'customer_id', 'email', 'order_number' ] as $column ) {
            if ( isset( $filter[ $column ] ) ) {
                $query->where( $column, $filter[ $column ] );
            }
        }

        return $query;
    }

    /**
     * `search(query:)` through Scout. Scout engines paginate by page
     * number, so cursors here encode the result offset, which must be a
     * multiple of `first`.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $args  Arguments.
     * @param  ResolveInfo           $info  Resolve info.
     *
     * @return array<string, mixed>
     */
    protected function search( array $args, ResolveInfo $info ): array
    {
        $term = trim( (string) $args['query'] );

        if ( '' === $term || mb_strlen( $term ) > 200 ) {
            throw new GraphQLError( __( 'The query must be 1–200 characters.' ), 'BAD_USER_INPUT' );
        }

        $max    = max( 1, (int) config( 'artisanpack.ecommerce.api.max_per_page', 100 ) );
        $first  = max( 1, min( $max, (int) ( $args['first'] ?? config( 'artisanpack.ecommerce.api.default_per_page', 25 ) ) ) );
        $offset = isset( $args['after'] ) ? (int) base64_decode( (string) $args['after'], true ) : 0;

        // Scout pages by number, so a cursor must fall on a page boundary of
        // the requested size; the offset is also capped so deep paging can't
        // be used to make the engine scan the whole catalog.
        if ( $offset < 0 || 0 !== $offset % $first || $offset > self::MAX_SEARCH_OFFSET ) {
            throw new GraphQLError( __( 'Invalid search cursor: pass the endCursor of the previous page with the same `first`.' ), 'BAD_USER_INPUT' );
        }

        $page   = intdiv( $offset, $first ) + 1;
        $loads  = $this->r->eagerLoads( 'Product', array_replace_recursive(
            (array) ( $this->r->selection( $info, 'nodes' ) ),
            (array) ( $this->r->selection( $info, 'edges.node' ) ),
        ) );

        $paginator = Product::search( $term )
            ->where( 'status', 'active' )
            ->query( static fn ( $query ) => $query->storefrontVisible()->with( $loads ) )
            ->paginate( $first, 'page', $page );

        $edges = [];

        foreach ( array_values( $paginator->items() ) as $index => $product ) {
            $edges[] = [
                'cursor' => base64_encode( (string) ( ( $page - 1 ) * $first + $index + 1 ) ),
                'node'   => $this->r->render( $product ),
            ];
        }

        return [
            'edges'    => $edges,
            'nodes'    => array_column( $edges, 'node' ),
            'pageInfo' => [
                'hasNextPage'     => $paginator->hasMorePages(),
                'hasPreviousPage' => $page > 1,
                'startCursor'     => $edges[0]['cursor'] ?? null,
                'endCursor'       => [] === $edges ? null : $edges[ count( $edges ) - 1 ]['cursor'],
            ],
        ];
    }
}
