<?php

/**
 * LocalPickupHandoff.
 *
 * Issues and verifies the QR handoff code for local-pickup shipments
 * (parent plan §5.10). {@see self::issue()} mints a random code, stores
 * only its SHA-256 hash in `shipments.meta.pickup`, and returns the
 * payload to encode in the QR code. The payload is also broadcast through
 * the `ap.ecommerce.shipping.pickupReady` action so the notification layer
 * can render + email the QR code. {@see self::redeem()} is what the admin
 * scanner calls at handoff: it verifies the payload in constant time and
 * marks the shipment delivered.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Shipping;

use ArtisanPackUI\Ecommerce\Events\ShipmentDelivered;
use ArtisanPackUI\Ecommerce\Models\Shipment;
use ArtisanPackUI\Ecommerce\ValueObjects\TrackingStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use LogicException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class LocalPickupHandoff
{
    /**
     * Prefix of every QR payload.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const PAYLOAD_PREFIX = 'ap-ecommerce-pickup';

    /**
     * Mints a handoff code for `$shipment` and returns the QR payload
     * (`ap-ecommerce-pickup:{shipmentId}:{code}`). Re-issuing replaces the
     * previous code; a shipment that was already collected can't be
     * re-issued (that would make it redeemable again).
     *
     * @since 1.0.0
     *
     * @param  Shipment  $shipment  Local-pickup shipment.
     *
     * @throws LogicException When the shipment was already collected.
     *
     * @return string
     */
    public function issue( Shipment $shipment ): string
    {
        if ( null !== ( $shipment->meta['pickup']['collected_at'] ?? null ) ) {
            throw new LogicException( __( 'Shipment :id was already collected; its pickup code cannot be re-issued.', [ 'id' => $shipment->id ] ) );
        }

        $code = Str::random( 32 );
        $meta = (array) ( $shipment->meta ?? [] );

        $meta['pickup'] = [
            'code_hash'    => hash( 'sha256', $code ),
            'issued_at'    => Carbon::now()->toIso8601String(),
            'collected_at' => null,
        ];

        $shipment->meta = $meta;
        $shipment->save();

        $payload = self::PAYLOAD_PREFIX . ':' . $shipment->id . ':' . $code;

        doAction( 'ap.ecommerce.shipping.pickupReady', $shipment, $shipment->order, $payload );

        return $payload;
    }

    /**
     * Verifies a scanned payload against `$shipment`.
     *
     * @since 1.0.0
     *
     * @param  Shipment  $shipment  Shipment being handed off.
     * @param  string    $payload   Scanned QR payload.
     *
     * @return bool
     */
    public function verify( Shipment $shipment, string $payload ): bool
    {
        $hash  = $shipment->meta['pickup']['code_hash'] ?? null;
        $parts = explode( ':', $payload, 3 );

        if ( ! is_string( $hash ) || 3 !== count( $parts ) ) {
            return false;
        }

        [ $prefix, $shipmentId, $code ] = $parts;

        return self::PAYLOAD_PREFIX === $prefix
            && (string) $shipment->id === $shipmentId
            && hash_equals( $hash, hash( 'sha256', $code ) );
    }

    /**
     * Verifies `$payload` and, on success, marks the shipment delivered.
     * A code can be redeemed once: the shipment row is re-read under a
     * lock so two simultaneous scans can't both succeed.
     *
     * @since 1.0.0
     *
     * @param  Shipment  $shipment  Shipment being handed off.
     * @param  string    $payload   Scanned QR payload.
     *
     * @return bool Whether the handoff was accepted.
     */
    public function redeem( Shipment $shipment, string $payload ): bool
    {
        $redeemed = DB::transaction( function () use ( $shipment, $payload ): ?Shipment {
            $locked = Shipment::query()->whereKey( $shipment->id )->lockForUpdate()->first();

            if ( null === $locked
                || null !== ( $locked->meta['pickup']['collected_at'] ?? null )
                || ! $this->verify( $locked, $payload ) ) {
                return null;
            }

            $now                            = Carbon::now();
            $meta                           = (array) $locked->meta;
            $meta['pickup']['collected_at'] = $now->toIso8601String();

            $locked->meta         = $meta;
            $locked->status       = Shipment::STATUS_DELIVERED;
            $locked->delivered_at = $now;
            $locked->save();

            return $locked;
        } );

        if ( null === $redeemed ) {
            return false;
        }

        $shipment->setRawAttributes( $redeemed->getAttributes(), true );

        doAction( 'ap.ecommerce.shipping.trackingUpdated', $shipment, new TrackingStatus(
            status: Shipment::STATUS_DELIVERED,
            description: __( 'Collected at local pickup.' ),
            occurredAt: $shipment->delivered_at,
        ) );
        doAction( 'ap.ecommerce.order.delivered', $shipment->order, $shipment );
        Event::dispatch( new ShipmentDelivered( $shipment, $shipment->order ) );

        return true;
    }
}
