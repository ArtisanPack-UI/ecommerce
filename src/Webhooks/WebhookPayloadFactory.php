<?php

/**
 * WebhookPayloadFactory.
 *
 * Turns a domain event (engine spec §7) into the `data` object of an
 * outbound webhook payload, and names the event on the wire (§8.3:
 * `OrderRefunded` → `order.refunded`).
 *
 * Each public property of the event becomes a snake_case key. Models are
 * rendered through their REST resource (engine spec §9.13), so a webhook
 * body matches the API and the `ap.ecommerce.api.resource.{name}` filters
 * apply to both. Admin-only fields (payment references, IP / user agent,
 * product meta, cost prices) are left out unless
 * `artisanpack.ecommerce.webhooks.include_admin_fields` is on — they would
 * otherwise go to every third party that subscribes.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Webhooks;

use ArtisanPackUI\Ecommerce\Api\ResourceSchemas;
use ArtisanPackUI\Ecommerce\Http\Middleware\EnsureEcommerceAbility;
use ArtisanPackUI\Ecommerce\Support\Timestamp;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Money\Money;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class WebhookPayloadFactory
{
    /**
     * Whether rendered models include admin-only fields.
     *
     * @since 1.0.0
     *
     * @var bool
     */
    protected bool $includeAdminFields;

    /**
     * @since 1.0.0
     *
     * @param  bool|null  $includeAdminFields  Render admin-only fields (defaults to
     *                                         `webhooks.include_admin_fields`).
     */
    public function __construct( ?bool $includeAdminFields = null )
    {
        $this->includeAdminFields = $includeAdminFields ?? (bool) config( 'artisanpack.ecommerce.webhooks.include_admin_fields', false );
    }

    /**
     * The wire name for an event class: `OrderRefunded` → `order.refunded`.
     *
     * @since 1.0.0
     *
     * @param  object|string  $event  Event instance or class.
     *
     * @return string
     */
    public static function eventName( object|string $event ): string
    {
        return Str::snake( class_basename( is_object( $event ) ? $event::class : $event ), '.' );
    }

    /**
     * Serializes an event's public properties.
     *
     * @since 1.0.0
     *
     * @param  object  $event  Domain event.
     *
     * @return array<string, mixed>
     */
    public function fromEvent( object $event ): array
    {
        return $this->properties( $event );
    }

    /**
     * Serializes one value, or returns null when it has no wire form.
     * Value objects without a `toArray()` serialize their public
     * properties (e.g. a `PaymentResult`).
     *
     * @since 1.0.0
     *
     * @param  mixed  $value  Value.
     *
     * @return mixed
     */
    public function serialize( mixed $value ): mixed
    {
        return match ( true ) {
            null === $value, is_scalar( $value ) => $value,
            is_array( $value )                   => array_map( fn ( mixed $item ): mixed => $this->serialize( $item ), $value ),
            $value instanceof Model              => $this->model( $value ),
            $value instanceof Money              => [ 'amount' => (int) $value->getAmount(), 'currency' => $value->getCurrency()->getCode() ],
            $value instanceof DateTimeInterface  => Timestamp::format( $value ),
            $value instanceof BackedEnum         => $value->value,
            $value instanceof Throwable          => [ 'type' => class_basename( $value ), 'message' => $value->getMessage() ],
            ! is_object( $value )                => null,
            method_exists( $value, 'toArray' )   => $this->serialize( $value->toArray() ),
            method_exists( $value, 'key' )       => $value->key(),
            default                              => $this->properties( $value ),
        };
    }

    /**
     * Serializes an object's public properties under snake_case keys,
     * skipping values with no wire form.
     *
     * @since 1.0.0
     *
     * @param  object  $object  Object.
     *
     * @return array<string, mixed>
     */
    protected function properties( object $object ): array
    {
        $data = [];

        foreach ( get_object_vars( $object ) as $property => $value ) {
            $serialized = $this->serialize( $value );

            if ( null !== $serialized || null === $value ) {
                $data[ Str::snake( $property ) ] = $serialized;
            }
        }

        return $data;
    }

    /**
     * Renders a model through its REST resource, falling back to its
     * visible attributes when the engine has no resource for it.
     *
     * @since 1.0.0
     *
     * @param  Model  $model  Model.
     *
     * @return array<string, mixed>
     */
    protected function model( Model $model ): array
    {
        $resource = ResourceSchemas::resourceForModel( $model );

        if ( null === $resource ) {
            return $model->attributesToArray();
        }

        $request = Request::create( '/' );
        $request->attributes->set( EnsureEcommerceAbility::ADMIN_ATTRIBUTE, $this->includeAdminFields );

        return json_decode( (string) json_encode( ( new $resource( $model ) )->resolve( $request ) ), true );
    }
}
