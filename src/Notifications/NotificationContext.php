<?php

/**
 * NotificationContext.
 *
 * Builds the render context for notification templates: plain nested
 * arrays of scalars keyed by the roots the catalog declares (`Store`,
 * `Order`, `Customer`, `Shipment`, `Refund`, `Review`, `Product`,
 * `Downloads`, `Licenses`, `License`, `Activation`, `File`,
 * `InventoryItem`). Templates never see models, so the sandbox has no
 * objects whose methods or properties it could reach. Money is
 * pre-formatted for display.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Notifications;

use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\DigitalDownload;
use ArtisanPackUI\Ecommerce\Models\DigitalFile;
use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\Models\LicenseActivation;
use ArtisanPackUI\Ecommerce\Models\LicenseKey;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductReview;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use ArtisanPackUI\Ecommerce\Models\Refund;
use ArtisanPackUI\Ecommerce\Models\Shipment;
use ArtisanPackUI\Ecommerce\Support\MoneyFormatter;
use ArtisanPackUI\Ecommerce\Support\OrderViewToken;
use ArtisanPackUI\Ecommerce\Support\TaxLabel;
use DateTimeInterface;
use Illuminate\Support\Facades\Route;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class NotificationContext
{
    /**
     * `Store`.
     *
     * @since 1.0.0
     *
     * @return array{name: string, url: string, support_email: string|null}
     */
    public function store(): array
    {
        return [
            'name'            => (string) ( config( 'artisanpack.ecommerce.notifications.store_name' ) ?? config( 'app.name', '' ) ),
            'url'             => (string) config( 'app.url', '' ),
            'support_email'   => config( 'artisanpack.ecommerce.notifications.support_email' ) ?? config( 'mail.from.address' ),
            // Per recipient at delivery: their unsubscribe link (opt-out
            // categories), else this store-wide preferences page.
            'preferences_url' => config( 'artisanpack.ecommerce.notifications.preferences_url' ),
        ];
    }

    /**
     * `Order`, with its customer and lines.
     *
     * @since 1.0.0
     *
     * @param  Order  $order  Order.
     *
     * @return array<string, mixed>
     */
    public function order( Order $order ): array
    {
        $currency = (string) $order->currency;
        $address  = (array) ( $order->shipping_address ?? [] );

        return [
            'number'           => $order->order_number,
            'status'           => $order->system_status,
            'placed_at'        => $this->date( $order->placed_at ?? $order->created_at ),
            'email'            => $order->email,
            'currency'         => $currency,
            'subtotal'         => $this->money( (int) $order->subtotal_amount, $currency ),
            'discount'         => $this->money( (int) $order->discount_amount, $currency ),
            'shipping'         => $this->money( (int) $order->shipping_amount, $currency ),
            'tax'              => $this->money( (int) $order->tax_amount, $currency ),
            'tax_label'        => TaxLabel::for(),
            'total'            => $this->money( (int) $order->total_amount, $currency ),
            'shipping_address' => implode( ', ', array_filter( [
                trim( ( $address['first_name'] ?? '' ) . ' ' . ( $address['last_name'] ?? '' ) ),
                $address['company'] ?? null,
                $address['address1'] ?? null,
                $address['address2'] ?? null,
                $address['city'] ?? null,
                $address['region'] ?? null,
                $address['postal_code'] ?? null,
                $address['country_code'] ?? null,
            ], static fn ( mixed $part ): bool => is_string( $part ) && '' !== $part ) ),
            'customer'         => $this->customer( $order->customer, $order->email, $address ),
            // A signed link for guests, who can't sign in to see it (#175).
            'view_url'         => null === $order->customer_id ? $this->viewUrl( $order ) : null,
            'items'            => $order->items->map( fn ( $item ): array => [
                'name'       => (string) ( $item->product_snapshot['name'] ?? '' ),
                'sku'        => $item->product_snapshot['sku'] ?? null,
                'quantity'   => (int) $item->quantity,
                'unit_price' => $this->money( (int) $item->unit_price_amount, (string) $item->unit_price_currency ),
                'total'      => $this->money( (int) $item->total_amount, (string) $item->total_currency ),
            ] )->values()->all(),
        ];
    }

    /**
     * `Customer` (also `Order.customer`).
     *
     * @since 1.0.0
     *
     * @param  Customer|null         $customer  Customer record.
     * @param  string|null           $email     Fallback email (guest orders).
     * @param  array<string, mixed>  $address   Fallback name source.
     *
     * @return array{name: string, first_name: string, email: string|null}
     */
    public function customer( ?Customer $customer, ?string $email = null, array $address = [] ): array
    {
        $first = (string) ( $customer?->first_name ?? $address['first_name'] ?? '' );
        $last  = (string) ( $customer?->last_name ?? $address['last_name'] ?? '' );

        return [
            'name'       => trim( $first . ' ' . $last ),
            'first_name' => $first,
            'email'      => $customer?->email ?? $email,
        ];
    }

    /**
     * `Shipment`.
     *
     * @since 1.0.0
     *
     * @param  Shipment  $shipment  Shipment.
     *
     * @return array<string, string|null>
     */
    public function shipment( Shipment $shipment ): array
    {
        return [
            'carrier'         => $shipment->carrier,
            'service'         => $shipment->service,
            'tracking_number' => $shipment->tracking_number,
            'tracking_url'    => $shipment->tracking_url,
            'shipped_at'      => $this->date( $shipment->shipped_at ),
            'delivered_at'    => $this->date( $shipment->delivered_at ),
        ];
    }

    /**
     * `Refund`.
     *
     * @since 1.0.0
     *
     * @param  Refund  $refund  Refund.
     *
     * @return array<string, string|null>
     */
    public function refund( Refund $refund ): array
    {
        return [
            'amount'     => $this->money( (int) $refund->amount, (string) $refund->currency ),
            'reason'     => $refund->reason,
            'created_at' => $this->date( $refund->created_at ),
        ];
    }

    /**
     * `Review`.
     *
     * @since 1.0.0
     *
     * @param  ProductReview  $review  Review.
     *
     * @return array<string, mixed>
     */
    public function review( ProductReview $review ): array
    {
        return [
            'rating'               => $review->rating,
            'title'                => $review->title,
            'body'                 => $review->body,
            'author_name'          => $review->author_name,
            'is_verified_purchase' => $review->is_verified_purchase,
            'status'               => $review->status,
        ];
    }

    /**
     * `Product`.
     *
     * @since 1.0.0
     *
     * @param  Product|null  $product  Product.
     *
     * @return array{name: string|null, sku: string|null}
     */
    public function product( ?Product $product ): array
    {
        return [ 'name' => $product?->name, 'sku' => $product?->sku ];
    }

    /**
     * `File`.
     *
     * @since 1.0.0
     *
     * @param  DigitalFile  $file  File.
     *
     * @return array{label: string, version: string|null}
     */
    public function file( DigitalFile $file ): array
    {
        return [ 'label' => $file->label, 'version' => $file->version ];
    }

    /**
     * `Downloads` — freshly issued entitlements, whose plain tokens are
     * still in memory, as customer-facing links.
     *
     * @since 1.0.0
     *
     * @param  iterable<DigitalDownload>  $downloads  Issued downloads.
     *
     * @return array<int, array<string, mixed>>
     */
    public function downloads( iterable $downloads ): array
    {
        $rows = [];

        foreach ( $downloads as $download ) {
            $token = (string) $download->plainToken;

            $rows[] = [
                'label'               => $download->file->label,
                'url'                 => $download->file->is_streaming_only ? null : $this->route( 'ecommerce.api.downloads.show', $token ),
                'stream_url'          => $this->route( 'ecommerce.api.downloads.stream', $token ),
                'is_streaming_only'   => $download->file->is_streaming_only,
                'downloads_remaining' => $download->downloads_remaining,
                'expires_at'          => $this->date( $download->expires_at ),
            ];
        }

        return $rows;
    }

    /**
     * `Licenses` — issued keys, in full.
     *
     * @since 1.0.0
     *
     * @param  iterable<LicenseKey>  $licenses  Issued keys.
     *
     * @return array<int, array<string, mixed>>
     */
    public function licenses( iterable $licenses ): array
    {
        $rows = [];

        foreach ( $licenses as $license ) {
            $rows[] = [
                'key'               => $license->key,
                'product'           => $license->orderItem->product_snapshot['name'] ?? null,
                'activations_limit' => $license->activations_limit,
                'expires_at'        => $this->date( $license->expires_at ),
            ];
        }

        return $rows;
    }

    /**
     * `License` — a key identified only by its last group, for security
     * notices.
     *
     * @since 1.0.0
     *
     * @param  LicenseKey  $license  Key.
     *
     * @return array<string, mixed>
     */
    public function license( LicenseKey $license ): array
    {
        return [
            'key_hint'          => substr( $license->key, -5 ),
            'activations_count' => $license->activations_count,
            'activations_limit' => $license->activations_limit,
            'expires_at'        => $this->date( $license->expires_at ),
        ];
    }

    /**
     * `Activation`.
     *
     * @since 1.0.0
     *
     * @param  LicenseActivation  $activation  Activation.
     *
     * @return array{activated_at: string|null, ip_address: string|null}
     */
    public function activation( LicenseActivation $activation ): array
    {
        return [ 'activated_at' => $this->date( $activation->activated_at ), 'ip_address' => $activation->ip_address ];
    }

    /**
     * `InventoryItem`, named after what it stocks.
     *
     * @since 1.0.0
     *
     * @param  InventoryItem  $item   Inventory row.
     * @param  int|null       $level  Current on-hand level, when known.
     *
     * @return array<string, mixed>
     */
    public function inventoryItem( InventoryItem $item, ?int $level = null ): array
    {
        $stockable = $item->stockable;
        $name      = match ( true ) {
            $stockable instanceof ProductVariant => trim( ( $stockable->product?->name ?? '' ) . ' ' . ( $stockable->name ?? '' ) ),
            $stockable instanceof Product        => $stockable->name,
            default                              => '',
        };

        return [
            'sku'       => $stockable?->sku ?? null,
            'name'      => $name,
            'on_hand'   => $level ?? $item->quantity_on_hand,
            'threshold' => $item->low_stock_threshold,
        ];
    }

    /**
     * The product an inventory row stocks (directly or via a variant).
     *
     * @since 1.0.0
     *
     * @param  InventoryItem  $item  Inventory row.
     *
     * @return Product|null
     */
    public function productForInventory( InventoryItem $item ): ?Product
    {
        $stockable = $item->stockable;

        return match ( true ) {
            $stockable instanceof ProductVariant => $stockable->product,
            $stockable instanceof Product        => $stockable,
            default                              => null,
        };
    }

    /**
     * Formats minor units for display in the active locale.
     *
     * @since 1.0.0
     *
     * @param  int     $amount    Minor units.
     * @param  string  $currency  ISO 4217 code.
     *
     * @return string
     */
    public function money( int $amount, string $currency ): string
    {
        return MoneyFormatter::format( $amount, $currency );
    }

    /**
     * A signed link showing `$order`, or null when none can be built (no
     * storefront page configured and the REST API off).
     *
     * @since 1.0.0
     *
     * @param  Order  $order  Order.
     *
     * @return string|null
     */
    protected function viewUrl( Order $order ): ?string
    {
        $template = config( 'artisanpack.ecommerce.checkout.order_view_url' );

        if ( ( ! is_string( $template ) || ! str_contains( $template, '{token}' ) ) && ! Route::has( 'ecommerce.api.order-views.show' ) ) {
            return null;
        }

        return OrderViewToken::url( $order );
    }

    /**
     * ISO 8601, or null.
     *
     * @since 1.0.0
     *
     * @param  DateTimeInterface|null  $date  Date.
     *
     * @return string|null
     */
    protected function date( ?DateTimeInterface $date ): ?string
    {
        return $date?->format( DATE_ATOM );
    }

    /**
     * An absolute URL for a named route taking a token, or null when the
     * route isn't registered (REST disabled).
     *
     * @since 1.0.0
     *
     * @param  string  $name   Route name.
     * @param  string  $token  Token.
     *
     * @return string|null
     */
    protected function route( string $name, string $token ): ?string
    {
        return Route::has( $name ) ? route( $name, [ 'token' => $token ] ) : null;
    }
}
