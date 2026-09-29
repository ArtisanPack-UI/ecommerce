<?php

/**
 * EcommerceResource.
 *
 * Base class for every REST resource (engine spec §9.13). Resources are
 * flat JSON objects: `id`, `type`, the resource's own fields (foreign keys
 * as related ids), and any relation the client asked for via `include`.
 * Money is always `{ amount: int (minor units), currency: string }`.
 * Relations flagged admin-only in {@see ResourceSchemas} (internal notes,
 * audit timeline, a cart's customer, …) render only on admin requests.
 *
 * The final array runs through `ap.ecommerce.api.resource.{name}` (engine
 * spec §6.17) so satellites can decorate any resource — e.g. the
 * subscriptions satellite adding `subscription` to orders.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Http\Resources;

use ArtisanPackUI\Ecommerce\Api\ResourceSchemas;
use ArtisanPackUI\Ecommerce\Http\Middleware\EnsureEcommerceAbility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property Model $resource
 */
abstract class EcommerceResource extends JsonResource
{
    /**
     * Resource type / hook name (camelCase).
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = '';

    /**
     * Resources are returned unwrapped inside `data` by the controllers.
     *
     * @since 1.0.0
     *
     * @var string|null
     */
    public static $wrap = 'data';

    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return array<string, mixed>
     */
    public function toArray( Request $request ): array
    {
        // Strip conditional values (admin-only fields) before listeners see
        // the payload, so a filter never has to handle MissingValue.
        // The envelope keys are merged last so no model column can shadow them.
        $data = $this->filter( array_merge(
            $this->fields( $request ),
            $this->includes( $request ),
            [ 'id' => $this->resource->getKey(), 'type' => static::NAME ],
        ) );

        return (array) applyFilters( 'ap.ecommerce.api.resource.' . static::NAME, $data, $this->resource, $request );
    }

    /**
     * The resource's own fields.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return array<string, mixed>
     */
    abstract protected function fields( Request $request ): array;

    /**
     * Relations rendered when loaded: public name → [ relation, resource class ].
     *
     * @since 1.0.0
     *
     * @return array<string, array{0: string, 1: class-string<EcommerceResource>}>
     */
    protected function relations(): array
    {
        return [];
    }

    /**
     * Renders every loaded relation from {@see self::relations()}.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return array<string, mixed>
     */
    protected function includes( Request $request ): array
    {
        $included  = [];
        $adminOnly = $this->isAdmin( $request ) ? [] : $this->adminOnlyRelations();

        foreach ( $this->relations() as $name => [ $relation, $resourceClass ] ) {
            if ( ! $this->resource->relationLoaded( $relation ) || in_array( $name, $adminOnly, true ) ) {
                continue;
            }

            $related = $this->resource->getRelation( $relation );

            $included[ $name ] = match ( true ) {
                null === $related            => null,
                $related instanceof Model    => ( new $resourceClass( $related ) )->resolve( $request ),
                default                      => $resourceClass::collection( $related )->resolve( $request ),
            };
        }

        return $included;
    }

    /**
     * Include names flagged admin-only in {@see ResourceSchemas} — never
     * rendered on a non-admin request, even if something loaded them.
     *
     * @since 1.0.0
     *
     * @return array<int, string>
     */
    protected function adminOnlyRelations(): array
    {
        $type = ResourceSchemas::typeForResource( static::class );

        if ( null === $type ) {
            return [];
        }

        return array_keys( array_filter(
            ResourceSchemas::get( $type )['relations'] ?? [],
            static fn ( array $definition ): bool => (bool) ( $definition[3] ?? false ),
        ) );
    }

    /**
     * `{ amount, currency }` from an amount / currency column pair.
     *
     * @since 1.0.0
     *
     * @param  string       $amountColumn    Amount column.
     * @param  string|null  $currencyColumn  Currency column (defaults to `{prefix}_currency`).
     *
     * @return array{amount: int, currency: string|null}
     */
    protected function money( string $amountColumn, ?string $currencyColumn = null ): array
    {
        $currencyColumn ??= str_ends_with( $amountColumn, '_amount' )
            ? substr( $amountColumn, 0, -7 ) . '_currency'
            : 'currency';

        return [
            'amount'   => (int) $this->resource->getAttribute( $amountColumn ),
            'currency' => $this->resource->getAttribute( $currencyColumn ),
        ];
    }

    /**
     * Whether the request passed an ecommerce ability check (admin routes).
     *
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return bool
     */
    protected function isAdmin( Request $request ): bool
    {
        return true === $request->attributes->get( EnsureEcommerceAbility::ADMIN_ATTRIBUTE );
    }

    /**
     * `$value` on admin requests, omitted otherwise.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     * @param  mixed    $value    Value.
     *
     * @return mixed
     */
    protected function adminOnly( Request $request, mixed $value ): mixed
    {
        return $this->isAdmin( $request ) ? $value : new MissingValue();
    }
}
