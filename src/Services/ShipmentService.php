<?php

/**
 * ShipmentService.
 *
 * Creates shipments against an order and keeps their tracking state in
 * sync. Quantities are validated against what remains unshipped on each
 * line so a line can never be shipped twice. Fires the shipment hooks
 * from engine spec §6.3 / §6.6.
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

use ArtisanPackUI\Ecommerce\Contracts\ShippingLabelProvider;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\Shipment;
use ArtisanPackUI\Ecommerce\Models\ShipmentItem;
use ArtisanPackUI\Ecommerce\Registries\ShippingLabelProviderRegistry;
use ArtisanPackUI\Ecommerce\Shipping\LocalPickupHandoff;
use ArtisanPackUI\Ecommerce\Shipping\Methods\LocalPickupMethod;
use ArtisanPackUI\Ecommerce\ValueObjects\ShippingLabel;
use ArtisanPackUI\Ecommerce\ValueObjects\TrackingStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class ShipmentService
{
    /**
     * `meta` key holding an in-progress label purchase claim.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const LABEL_CLAIM_META = 'label_purchase';

    /**
     * System statuses an order can no longer be shipped from.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const UNSHIPPABLE_STATUSES = [ 'cancelled', 'refunded', 'failed' ];

    /**
     * @since 1.0.0
     *
     * @param  LocalPickupHandoff             $pickupHandoff   QR handoff issuer.
     * @param  ShippingLabelProviderRegistry  $labelProviders  Label providers.
     */
    public function __construct(
        private readonly LocalPickupHandoff $pickupHandoff,
        private readonly ShippingLabelProviderRegistry $labelProviders,
    ) {
    }

    /**
     * Creates a shipment for `$order`.
     *
     * The order row is locked for the duration of the transaction so two
     * concurrent requests can't both see the same units as unshipped.
     * Line and order `fulfillment_status` roll up afterwards
     * (`unfulfilled` → `partial` → `fulfilled`).
     *
     * Fires `ap.ecommerce.order.fulfilling` (action) with the locked order
     * once the order, quantities, and status have all been validated, before
     * any shipment row is written; a listener that throws aborts the
     * shipment. A rejected request never fires it.
     *
     * @since 1.0.0
     *
     * @param  Order                 $order       Order being fulfilled.
     * @param  string                $methodKey   Shipping method key (e.g. `flat-rate`, `local-pickup`).
     * @param  array<int, int>       $quantities  Order-item id → quantity. Empty ships every unshipped unit.
     * @param  array<string, mixed>  $attributes  Extra shipment columns (carrier, service, tracking_number, …).
     *
     * @throws InvalidArgumentException When the order can't be shipped, an item is foreign to it, or over-shipped.
     *
     * @return Shipment
     */
    public function create( Order $order, string $methodKey, array $quantities = [], array $attributes = [] ): Shipment
    {
        [ $shipment, $fulfilledItems, $orderFulfilled ] = DB::transaction( function () use ( $order, $methodKey, $quantities, $attributes ): array {
            $locked = Order::query()->whereKey( $order->id )->lockForUpdate()->firstOrFail();

            if ( in_array( $locked->system_status, self::UNSHIPPABLE_STATUSES, true ) ) {
                throw new InvalidArgumentException( __( 'Order :id is :status and can no longer be shipped.', [ 'id' => $order->id, 'status' => $locked->system_status ] ) );
            }

            $remaining = $this->remainingQuantities( $locked );

            if ( [] === $quantities ) {
                $quantities = array_filter( $remaining );
            }

            if ( [] === $quantities ) {
                throw new InvalidArgumentException( __( 'Order has no unshipped items.' ) );
            }

            foreach ( $quantities as $orderItemId => $quantity ) {
                if ( ! array_key_exists( (int) $orderItemId, $remaining ) ) {
                    throw new InvalidArgumentException( __( 'Order item :item does not belong to order :order or does not need shipping.', [ 'item' => $orderItemId, 'order' => $order->id ] ) );
                }

                if ( (int) $quantity < 1 || (int) $quantity > $remaining[ (int) $orderItemId ] ) {
                    throw new InvalidArgumentException( __( 'Cannot ship :quantity of order item :item; :remaining remain unshipped.', [
                        'quantity'  => $quantity,
                        'item'      => $orderItemId,
                        'remaining' => $remaining[ (int) $orderItemId ],
                    ] ) );
                }
            }

            $status = (string) ( $attributes['status'] ?? Shipment::STATUS_PENDING );

            if ( ! in_array( $status, Shipment::STATUSES, true ) ) {
                throw new InvalidArgumentException( __( 'Unknown shipment status ":status".', [ 'status' => $status ] ) );
            }

            doAction( 'ap.ecommerce.order.fulfilling', $locked );

            $shipment = Shipment::query()->create( array_merge(
                array_intersect_key( $attributes, array_flip( [ 'carrier', 'service', 'tracking_number', 'tracking_url', 'shipped_at', 'meta' ] ) ),
                [
                    'order_id'     => $order->id,
                    'method_key'   => $methodKey,
                    'status'       => $status,
                    'delivered_at' => Shipment::STATUS_DELIVERED === $status ? Carbon::now() : null,
                ],
            ) );

            foreach ( $quantities as $orderItemId => $quantity ) {
                ShipmentItem::query()->create( [
                    'shipment_id'   => $shipment->id,
                    'order_item_id' => (int) $orderItemId,
                    'quantity'      => (int) $quantity,
                ] );
            }

            return [ $shipment, ...$this->rollUpFulfillment( $locked ) ];
        } );

        $order->refresh();
        $shipment->setRelation( 'order', $order );

        if ( LocalPickupMethod::KEY === $methodKey ) {
            $this->pickupHandoff->issue( $shipment );
        }

        doAction( 'ap.ecommerce.shipping.shipmentCreated', $shipment, $order );
        doAction( 'ap.ecommerce.order.shipped', $order, $shipment );

        foreach ( $fulfilledItems as $item ) {
            doAction( 'ap.ecommerce.order.itemFulfilled', $order, $item );
        }

        if ( $orderFulfilled ) {
            doAction( 'ap.ecommerce.order.fulfilled', $order );
        }

        if ( Shipment::STATUS_DELIVERED === $shipment->status ) {
            doAction( 'ap.ecommerce.order.delivered', $order, $shipment );
        }

        return $shipment->load( 'items' );
    }

    /**
     * Applies a carrier tracking update.
     *
     * @since 1.0.0
     *
     * @param  Shipment        $shipment  Shipment to update.
     * @param  TrackingStatus  $status    Normalized tracking state.
     *
     * @throws InvalidArgumentException When `$status->status` is unknown.
     *
     * @return Shipment
     */
    public function updateTracking( Shipment $shipment, TrackingStatus $status ): Shipment
    {
        if ( ! in_array( $status->status, Shipment::STATUSES, true ) ) {
            throw new InvalidArgumentException( __( 'Unknown shipment status ":status".', [ 'status' => $status->status ] ) );
        }

        $wasDelivered = Shipment::STATUS_DELIVERED === $shipment->status;

        $shipment->status          = $status->status;
        $shipment->tracking_number = $this->trackingValue( $status->trackingNumber, $shipment->tracking_number );
        $shipment->tracking_url    = $this->trackingValue( $status->trackingUrl, $shipment->tracking_url );

        if ( Shipment::STATUS_IN_TRANSIT === $status->status && null === $shipment->shipped_at ) {
            $shipment->shipped_at = $status->occurredAt ?? Carbon::now();
        }

        if ( Shipment::STATUS_DELIVERED === $status->status && null === $shipment->delivered_at ) {
            $shipment->delivered_at = $status->occurredAt ?? Carbon::now();
        }

        $shipment->save();

        doAction( 'ap.ecommerce.shipping.trackingUpdated', $shipment, $status );

        if ( ! $wasDelivered && Shipment::STATUS_DELIVERED === $status->status ) {
            doAction( 'ap.ecommerce.order.delivered', $shipment->order, $shipment );
        }

        return $shipment;
    }

    /**
     * Purchases a label for `$shipment` through the provider registered
     * under `$providerKey` and copies its tracking details onto the row.
     *
     * The purchase runs in three steps so no row lock or transaction is
     * held while the carrier responds (engine issue #155):
     *
     * 1. **Claim** — a short locked transaction refuses a shipment that
     *    already has a label or a live claim, then records
     *    `meta.label_purchase = { key, provider, claimed_at }`.
     * 2. **Buy** — the provider is called outside any transaction. The
     *    claim key, on the shipment's `meta.label_purchase.key`, is the
     *    purchase's idempotency key for carriers that accept one.
     * 3. **Finalize** — a second short locked transaction writes the label
     *    and tracking fields and clears the claim. A failed purchase clears
     *    the claim and rethrows. A request whose claim was taken over (by a
     *    claim with a different key) or whose shipment already has another
     *    label loses: it voids the label it bought and throws.
     *
     * A claim older than `artisanpack.ecommerce.fulfillment.label_claim_ttl_minutes`
     * (a crashed worker) may be taken over; the retry reuses its key when
     * the provider is the same, so the carrier can return the label it
     * already sold instead of charging again.
     *
     * @since 1.0.0
     *
     * @param  Shipment  $shipment     Shipment to label.
     * @param  string    $providerKey  Label provider registry key.
     *
     * @throws InvalidArgumentException When the shipment already has a label, or another purchase is in progress.
     *
     * @return ShippingLabel
     */
    public function buyLabel( Shipment $shipment, string $providerKey ): ShippingLabel
    {
        $provider = $this->labelProviders->get( $providerKey );
        $claimKey = $this->claimLabelPurchase( $shipment, $providerKey );

        try {
            $label = $provider->buyLabel( $shipment );
        } catch ( Throwable $exception ) {
            $this->releaseLabelClaim( $shipment, $claimKey );

            throw $exception;
        }

        // Null when this request won; otherwise why it lost.
        $lost = DB::transaction( function () use ( $shipment, $label, $claimKey ): ?string {
            $locked = Shipment::query()->whereKey( $shipment->id )->lockForUpdate()->firstOrFail();

            // A request that took over our claim as stale finished first.
            if ( null !== $locked->label_id && (int) $locked->label_id !== (int) $label->id ) {
                return __( 'Shipment :id already has a label.', [ 'id' => $locked->id ] );
            }

            $meta       = (array) ( $locked->meta ?? [] );
            $currentKey = $meta[ self::LABEL_CLAIM_META ]['key'] ?? null;

            // Another request took over our (stale) claim and is still
            // buying: it wins, and this request voids its own label.
            if ( null !== $currentKey && $claimKey !== $currentKey ) {
                return __( 'A label for shipment :id is already being bought.', [ 'id' => $locked->id ] );
            }

            unset( $meta[ self::LABEL_CLAIM_META ] );

            $locked->meta            = [] === $meta ? null : $meta;
            $locked->label_id        = $label->id;
            $locked->tracking_number = $label->trackingNumber ?? $locked->tracking_number;
            $locked->tracking_url    = $label->trackingUrl ?? $locked->tracking_url;
            $locked->carrier         = $label->carrier ?? $locked->carrier;
            $locked->service         = $label->service ?? $locked->service;
            $locked->save();

            $shipment->setRawAttributes( $locked->getAttributes(), true );

            return null;
        } );

        if ( null !== $lost ) {
            $this->voidDuplicateLabel( $provider, $label, $shipment );

            throw new InvalidArgumentException( $lost );
        }

        return $label;
    }

    /**
     * Unshipped quantity per shippable order item, keyed by order-item id.
     * Lines whose product type does not require fulfillment (digital
     * goods) are left out entirely.
     *
     * @since 1.0.0
     *
     * @param  Order  $order  Order.
     *
     * @return array<int, int>
     */
    public function remainingQuantities( Order $order ): array
    {
        $shipped   = $this->shippedQuantities( $order );
        $remaining = [];

        /** @var OrderItem $item */
        foreach ( $order->items()->with( 'product' )->get() as $item ) {
            if ( ! $this->requiresFulfillment( $item ) ) {
                continue;
            }

            $remaining[ $item->id ] = max( 0, (int) $item->quantity - (int) ( $shipped[ $item->id ] ?? 0 ) );
        }

        return $remaining;
    }

    /**
     * A tracking column's new value: `null` keeps the current one, an empty
     * string clears it, anything else replaces it.
     *
     * @since 1.0.0
     *
     * @param  string|null  $incoming  The value from the tracking update.
     * @param  string|null  $current   The stored value.
     *
     * @return string|null
     */
    protected function trackingValue( ?string $incoming, ?string $current ): ?string
    {
        return match ( true ) {
            null === $incoming        => $current,
            '' === trim( $incoming )  => null,
            default                   => $incoming,
        };
    }

    /**
     * Shipped quantity per order item, keyed by order-item id.
     *
     * @since 1.0.0
     *
     * @param  Order  $order  Order.
     *
     * @return array<int, int>
     */
    protected function shippedQuantities( Order $order ): array
    {
        return ShipmentItem::query()
            ->whereIn( 'shipment_id', Shipment::query()->where( 'order_id', $order->id )->select( 'id' ) )
            ->groupBy( 'order_item_id' )
            ->selectRaw( 'order_item_id, SUM(quantity) as shipped' )
            ->pluck( 'shipped', 'order_item_id' )
            ->map( static fn ( mixed $qty ): int => (int) $qty )
            ->all();
    }

    /**
     * Rolls shipped quantities up into line and order `fulfillment_status`.
     *
     * @since 1.0.0
     *
     * @param  Order  $order  Locked order row.
     *
     * @return array{0: array<int, OrderItem>, 1: bool} Lines that just became fulfilled, and whether the order just did.
     */
    protected function rollUpFulfillment( Order $order ): array
    {
        $shipped        = $this->shippedQuantities( $order );
        $justFulfilled  = [];
        $allFulfilled   = true;
        $anyShipped     = false;

        /** @var OrderItem $item */
        foreach ( $order->items()->with( 'product' )->get() as $item ) {
            if ( ! $this->requiresFulfillment( $item ) ) {
                continue;
            }

            $qty    = (int) ( $shipped[ $item->id ] ?? 0 );
            $status = match ( true ) {
                $qty >= (int) $item->quantity => 'fulfilled',
                $qty > 0                      => 'partial',
                default                       => 'unfulfilled',
            };

            $anyShipped   = $anyShipped || $qty > 0;
            $allFulfilled = $allFulfilled && 'fulfilled' === $status;

            if ( $status !== $item->fulfillment_status ) {
                if ( 'fulfilled' === $status ) {
                    $justFulfilled[] = $item;
                }

                $item->fulfillment_status = $status;
                $item->save();
            }
        }

        $orderStatus = match ( true ) {
            $anyShipped && $allFulfilled => 'fulfilled',
            $anyShipped                  => 'partial',
            default                      => 'unfulfilled',
        };

        $orderJustFulfilled = 'fulfilled' === $orderStatus && 'fulfilled' !== $order->fulfillment_status;

        if ( $orderStatus !== $order->fulfillment_status ) {
            $order->fulfillment_status = $orderStatus;
            $order->save();
        }

        return [ $justFulfilled, $orderJustFulfilled ];
    }

    /**
     * Whether a line produces a physical shipment. Lines whose product was
     * deleted, or whose type is no longer registered, are treated as
     * shippable so nothing silently drops out of fulfillment.
     *
     * @since 1.0.0
     *
     * @param  OrderItem  $item  Order line.
     *
     * @return bool
     */
    protected function requiresFulfillment( OrderItem $item ): bool
    {
        try {
            return null === $item->product || $item->product->productType()->requiresFulfillment();
        } catch ( RuntimeException ) {
            return true;
        }
    }

    /**
     * Step 1 of {@see self::buyLabel()}: claims the shipment for one label
     * purchase and returns the claim key.
     *
     * @since 1.0.0
     *
     * @param  Shipment  $shipment     Shipment to label.
     * @param  string    $providerKey  Label provider key.
     *
     * @throws InvalidArgumentException When the shipment has a label or a live claim.
     *
     * @return string
     */
    protected function claimLabelPurchase( Shipment $shipment, string $providerKey ): string
    {
        return DB::transaction( function () use ( $shipment, $providerKey ): string {
            $locked = Shipment::query()->whereKey( $shipment->id )->lockForUpdate()->firstOrFail();

            if ( null !== $locked->label_id ) {
                throw new InvalidArgumentException( __( 'Shipment :id already has a label.', [ 'id' => $locked->id ] ) );
            }

            $meta  = (array) ( $locked->meta ?? [] );
            $claim = $meta[ self::LABEL_CLAIM_META ] ?? null;

            if ( is_array( $claim ) && ! $this->labelClaimIsStale( $claim ) ) {
                throw new InvalidArgumentException( __( 'A label for shipment :id is already being bought.', [ 'id' => $locked->id ] ) );
            }

            $key = is_array( $claim ) && is_string( $claim['key'] ?? null ) && $providerKey === ( $claim['provider'] ?? null )
                ? $claim['key']
                : (string) Str::uuid();

            $meta[ self::LABEL_CLAIM_META ] = [
                'key'        => $key,
                'provider'   => $providerKey,
                'claimed_at' => Carbon::now()->toIso8601String(),
            ];

            $locked->meta = $meta;
            $locked->save();

            $shipment->setRawAttributes( $locked->getAttributes(), true );

            return $key;
        } );
    }

    /**
     * Clears our claim after a failed purchase so the label can be bought
     * again. A claim another request has since taken over is left alone.
     *
     * @since 1.0.0
     *
     * @param  Shipment  $shipment  Shipment.
     * @param  string    $claimKey  Our claim key.
     *
     * @return void
     */
    protected function releaseLabelClaim( Shipment $shipment, string $claimKey ): void
    {
        try {
            DB::transaction( function () use ( $shipment, $claimKey ): void {
                $locked = Shipment::query()->whereKey( $shipment->id )->lockForUpdate()->firstOrFail();
                $meta   = (array) ( $locked->meta ?? [] );

                if ( $claimKey !== ( $meta[ self::LABEL_CLAIM_META ]['key'] ?? null ) ) {
                    return;
                }

                unset( $meta[ self::LABEL_CLAIM_META ] );
                $locked->meta = [] === $meta ? null : $meta;
                $locked->save();

                $shipment->setRawAttributes( $locked->getAttributes(), true );
            } );
        } catch ( Throwable $exception ) {
            // The purchase error matters more; the claim expires on its own.
            report( $exception );
        }
    }

    /**
     * Whether a label claim is old enough to take over.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $claim  `meta.label_purchase`.
     *
     * @return bool
     */
    protected function labelClaimIsStale( array $claim ): bool
    {
        $ttl = max( 1, (int) config( 'artisanpack.ecommerce.fulfillment.label_claim_ttl_minutes', 10 ) );

        try {
            $claimedAt = Carbon::parse( (string) ( $claim['claimed_at'] ?? '' ) );
        } catch ( Throwable ) {
            return true;
        }

        return $claimedAt->lte( Carbon::now()->subMinutes( $ttl ) );
    }

    /**
     * Best-effort void of a label bought while another request finished the
     * purchase first. Failures are logged for reconciliation.
     *
     * @since 1.0.0
     *
     * @param  ShippingLabelProvider  $provider  Provider that sold it.
     * @param  ShippingLabel          $label     The duplicate label.
     * @param  Shipment               $shipment  Shipment.
     *
     * @return void
     */
    protected function voidDuplicateLabel( ShippingLabelProvider $provider, ShippingLabel $label, Shipment $shipment ): void
    {
        try {
            $provider->voidLabel( $label );
        } catch ( Throwable $exception ) {
            Log::channel( 'ecommerce' )->warning( 'Could not void a duplicate shipping label; void it with the carrier.', [
                'shipment_id' => $shipment->id,
                'label_id'    => $label->id,
                'provider'    => $label->providerKey,
                'error'       => $exception->getMessage(),
            ] );
        }
    }
}
