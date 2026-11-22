<?php

/**
 * BackInStockService.
 *
 * Back-in-stock subscriptions: a shopper leaves an email on an
 * out-of-stock product (or one of its variants) and hears once when it can
 * be bought again.
 *
 * - {@see self::subscribe()} records a pending subscription (idempotent per
 *   email, product, and variant; emails are lowercased and trimmed) and
 *   links it to the customer record with that email when there is one.
 * - When `ap.ecommerce.inventory.backInStock` fires (available stock rose
 *   from zero or less to above zero, see {@see InventoryService}),
 *   {@see self::notifyRestocked()} sends `inventory.back-in-stock.customer`
 *   through the notification catalog to each pending subscriber of that
 *   product or variant. A variant restock also notifies subscribers of the
 *   product as a whole. The send honours the recipient's `back-in-stock`
 *   preference (and carries the category's signed unsubscribe link);
 *   `notified_at` is set when it is handed off, so each subscription is
 *   notified once.
 * - Subscriptions are personal data: {@see self::exportFor()} and
 *   {@see self::eraseFor()} back the privacy export and customer erasure.
 *
 * Hooks: `ap.ecommerce.backInStock.subscribed`, `.unsubscribed`, and
 * `.notified` (actions, after commit), and `ap.ecommerce.backInStock.shouldNotify`
 * (filter).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.4.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Services;

use ArtisanPackUI\Ecommerce\Inventory\StockLevels;
use ArtisanPackUI\Ecommerce\Models\BackInStockSubscription;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use ArtisanPackUI\Ecommerce\Notifications\NotificationCatalog;
use ArtisanPackUI\Ecommerce\Notifications\NotificationContext;
use ArtisanPackUI\Ecommerce\Notifications\NotificationDispatcher;
use ArtisanPackUI\Ecommerce\Support\AfterCommit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.4.0
 */
class BackInStockService
{
    /**
     * @since 1.4.0
     *
     * @param  NotificationDispatcher  $notifications  Catalog dispatcher.
     * @param  NotificationContext     $context        Context builder.
     * @param  StockLevels             $stock          Stock reads.
     */
    public function __construct(
        protected NotificationDispatcher $notifications,
        protected NotificationContext $context,
        protected StockLevels $stock,
    ) {
    }

    /**
     * Subscribes `$email` to `$product` (or `$variant` of it). Returns the
     * existing pending subscription for the same email, product, and
     * variant instead of creating a second one.
     *
     * @since 1.4.0
     *
     * @param  Product              $product   Product.
     * @param  ProductVariant|null  $variant   Variant, or null for the product as a whole.
     * @param  string               $email     Address to notify.
     * @param  Customer|null        $customer  Customer subscribing (defaults to the record with that email).
     * @param  string|null          $locale    Language to notify in (the customer's preference, else the app locale at delivery).
     *
     * @throws InvalidArgumentException When the email is invalid or the variant belongs to another product.
     *
     * @return BackInStockSubscription
     */
    public function subscribe( Product $product, ?ProductVariant $variant, string $email, ?Customer $customer = null, ?string $locale = null ): BackInStockSubscription
    {
        $email = $this->normalizeEmail( $email );

        if ( '' === $email || false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
            throw new InvalidArgumentException( __( 'Enter a valid email address.' ) );
        }

        if ( null !== $variant && (int) $variant->product_id !== (int) $product->getKey() ) {
            throw new InvalidArgumentException( __( 'That variant is not available for this product.' ) );
        }

        $customer ??= Customer::query()->where( 'email', $email )->first();
        $locale     = null === $locale ? null : mb_substr( trim( $locale ), 0, 20 );

        // Locking the product row serializes concurrent sign-ups for it, so two
        // requests can't both miss the pending row and insert a duplicate.
        [ $subscription, $created ] = DB::transaction( function () use ( $product, $variant, $email, $customer, $locale ): array {
            Product::query()->whereKey( $product->getKey() )->lockForUpdate()->first();

            $existing = $this->pendingFor( $product, $variant, $email )->first();

            if ( null !== $existing ) {
                return [ $existing, false ];
            }

            return [ BackInStockSubscription::query()->create( [
                'email'              => $email,
                'customer_id'        => $customer?->getKey(),
                'product_id'         => $product->getKey(),
                'product_variant_id' => $variant?->getKey(),
                'locale'             => '' === (string) $locale ? null : $locale,
            ] ), true ];
        } );

        if ( ! $created ) {
            return $subscription;
        }

        AfterCommit::action( 'ap.ecommerce.backInStock.subscribed', $subscription, $product, $variant );

        return $subscription;
    }

    /**
     * Cancels a subscription. Notified or already-unsubscribed rows are
     * left as they are.
     *
     * @since 1.4.0
     *
     * @param  BackInStockSubscription  $subscription  Subscription.
     *
     * @return void
     */
    public function unsubscribe( BackInStockSubscription $subscription ): void
    {
        if ( ! $subscription->isPending() ) {
            return;
        }

        $subscription->unsubscribed_at = Carbon::now();
        $subscription->save();

        AfterCommit::action( 'ap.ecommerce.backInStock.unsubscribed', $subscription );
    }

    /**
     * Whether `$email` has a pending subscription to `$product` /
     * `$variant`.
     *
     * @since 1.4.0
     *
     * @param  Product              $product  Product.
     * @param  ProductVariant|null  $variant  Variant, or null for the product as a whole.
     * @param  string               $email    Address.
     *
     * @return bool
     */
    public function isSubscribed( Product $product, ?ProductVariant $variant, string $email ): bool
    {
        return $this->pendingFor( $product, $variant, $this->normalizeEmail( $email ) )->exists();
    }

    /**
     * Notifies the pending subscribers of whatever `$item` stocks, when it
     * can be sold again. Listens to `ap.ecommerce.inventory.backInStock`.
     *
     * @since 1.4.0
     *
     * @param  InventoryItem  $item  Restocked inventory row.
     *
     * @return int Subscriptions notified.
     */
    public function notifyRestocked( InventoryItem $item ): int
    {
        $stockable             = $item->stockable;
        [ $product, $variant ] = match ( true ) {
            $stockable instanceof ProductVariant => [ $stockable->product, $stockable ],
            $stockable instanceof Product        => [ $stockable, null ],
            default                              => [ null, null ],
        };

        if ( ! $product instanceof Product ) {
            return 0;
        }

        // No inventory components (an untracked or missing type): not a restock.
        if ( [] === $this->stock->components( $product, $variant, 1 ) ) {
            return 0;
        }

        $sellable = $this->stock->sellable( $product, $variant );

        if ( null !== $sellable && $sellable < 1 ) {
            return 0;
        }

        $notified = 0;

        BackInStockSubscription::query()
            ->pending()
            ->where( 'product_id', $product->getKey() )
            ->where( static function ( Builder $query ) use ( $variant ): void {
                $query->whereNull( 'product_variant_id' );

                if ( null !== $variant ) {
                    $query->orWhere( 'product_variant_id', $variant->getKey() );
                }
            } )
            ->orderBy( 'id' )
            ->chunkById( 100, function ( $subscriptions ) use ( $product, &$notified ): void {
                foreach ( $subscriptions as $subscription ) {
                    if ( $this->notify( $subscription, $product ) ) {
                        $notified++;
                    }
                }
            } );

        return $notified;
    }

    /**
     * Sends one subscription its notification and marks it notified.
     * Nothing happens (and it stays pending) when the recipient turned
     * back-in-stock notifications off or `ap.ecommerce.backInStock.shouldNotify`
     * says no.
     *
     * @since 1.4.0
     *
     * @param  BackInStockSubscription  $subscription  Pending subscription.
     * @param  Product|null             $product       Its product, when already loaded.
     *
     * @return bool Whether it was notified.
     */
    public function notify( BackInStockSubscription $subscription, ?Product $product = null ): bool
    {
        if ( ! $subscription->isPending() ) {
            return false;
        }

        $product ??= $subscription->product;
        $variant   = null === $subscription->product_variant_id ? null : $subscription->variant;

        if ( null === $product || ! (bool) applyFilters( 'ap.ecommerce.backInStock.shouldNotify', true, $subscription, $product ) ) {
            return false;
        }

        // Claim the row under a lock and hand off in the same transaction, so
        // overlapping restock runs notify each subscriber once, and a row
        // unsubscribed meanwhile is skipped. The notification is queued after
        // commit; when nothing is sent the row stays pending.
        $claimed = DB::transaction( function () use ( $subscription, $product, $variant ): ?BackInStockSubscription {
            $locked = BackInStockSubscription::query()->whereKey( $subscription->getKey() )->lockForUpdate()->first();

            if ( null === $locked || ! $locked->isPending() ) {
                return null;
            }

            $customer  = null === $locked->customer_id ? null : $locked->customer;
            $recipient = null !== $customer && '' !== (string) $customer->email ? $customer : Notification::route( 'mail', $locked->email );

            $sent = $this->notifications->send(
                NotificationCatalog::BACK_IN_STOCK,
                $recipient,
                [
                    'Product'  => $this->context->backInStockProduct( $product, $variant ),
                    'Customer' => $this->context->customer( $customer, $locked->email ),
                ],
                $locked,
                locale: $locked->locale,
            );

            if ( $sent < 1 ) {
                return null;
            }

            $locked->notified_at = Carbon::now();
            $locked->save();

            return $locked;
        } );

        if ( null === $claimed ) {
            return false;
        }

        $subscription->setRawAttributes( $claimed->getAttributes(), true );

        AfterCommit::action( 'ap.ecommerce.backInStock.notified', $subscription, $product, $variant );

        return true;
    }

    /**
     * Every subscription for `$email` (or `$customer`), for a privacy
     * export.
     *
     * @since 1.4.0
     *
     * @param  string         $email     Address.
     * @param  Customer|null  $customer  Customer record, to include rows linked by id.
     *
     * @return array<int, array{product_id: int, product: string|null, product_variant_id: int|null, variant: string|null, email: string, locale: string|null, subscribed_at: string|null, notified_at: string|null, unsubscribed_at: string|null}>
     */
    public function exportFor( string $email, ?Customer $customer = null ): array
    {
        return $this->ownedBy( $email, $customer )
            ->with( [ 'product:id,name', 'variant:id,name' ] )
            ->orderBy( 'id' )
            ->get()
            ->map( static fn ( BackInStockSubscription $subscription ): array => [
                'product_id'         => (int) $subscription->product_id,
                'product'            => $subscription->product?->name,
                'product_variant_id' => null === $subscription->product_variant_id ? null : (int) $subscription->product_variant_id,
                'variant'            => $subscription->variant?->name,
                'email'              => $subscription->email,
                'locale'             => $subscription->locale,
                'subscribed_at'      => $subscription->created_at?->toIso8601String(),
                'notified_at'        => $subscription->notified_at?->toIso8601String(),
                'unsubscribed_at'    => $subscription->unsubscribed_at?->toIso8601String(),
            ] )
            ->values()
            ->all();
    }

    /**
     * Adds the subject's subscriptions to an artisanpack-ui/privacy export
     * (`ap.privacy.exportData`) as `ecommerce.back_in_stock_subscriptions`.
     * The subject is a {@see Customer}, or any model with an `email` (the
     * host's user); its customer record is matched by user id or email.
     *
     * @since 1.4.0
     *
     * @param  array<string, mixed>  $data     Export payload.
     * @param  mixed                 $subject  Data subject.
     *
     * @return array<string, mixed>
     */
    public function privacyExport( array $data, mixed $subject ): array
    {
        if ( ! $subject instanceof Model ) {
            return $data;
        }

        $customer = $subject instanceof Customer ? $subject : null;
        $email    = is_string( $subject->getAttribute( 'email' ) ) ? (string) $subject->getAttribute( 'email' ) : '';

        if ( null === $customer && is_numeric( $subject->getKey() ) ) {
            $customer = Customer::query()->where( 'user_id', (int) $subject->getKey() )->first()
                ?? ( '' === $email ? null : Customer::query()->where( 'email', $this->normalizeEmail( $email ) )->first() );
        }

        if ( '' === $email && null === $customer ) {
            return $data;
        }

        $rows = $this->exportFor( '' === $email ? (string) $customer?->email : $email, $customer );

        if ( [] !== $rows ) {
            $data['ecommerce']                                = (array) ( $data['ecommerce'] ?? [] );
            $data['ecommerce']['back_in_stock_subscriptions'] = $rows;
        }

        return $data;
    }

    /**
     * Deletes every subscription for `$email` (or `$customer`), for
     * erasure.
     *
     * @since 1.4.0
     *
     * @param  string         $email     Address.
     * @param  Customer|null  $customer  Customer record, to include rows linked by id.
     *
     * @return int Rows deleted.
     */
    public function eraseFor( string $email, ?Customer $customer = null ): int
    {
        return $this->ownedBy( $email, $customer )->delete();
    }

    /**
     * Pending subscriptions of `$email` to exactly `$product` / `$variant`.
     *
     * @since 1.4.0
     *
     * @param  Product              $product  Product.
     * @param  ProductVariant|null  $variant  Variant.
     * @param  string               $email    Normalized address.
     *
     * @return Builder<BackInStockSubscription>
     */
    protected function pendingFor( Product $product, ?ProductVariant $variant, string $email ): Builder
    {
        $query = BackInStockSubscription::query()
            ->pending()
            ->where( 'email', $email )
            ->where( 'product_id', $product->getKey() );

        return null === $variant ? $query->whereNull( 'product_variant_id' ) : $query->where( 'product_variant_id', $variant->getKey() );
    }

    /**
     * Subscriptions under `$email` or linked to `$customer`.
     *
     * @since 1.4.0
     *
     * @param  string         $email     Address.
     * @param  Customer|null  $customer  Customer.
     *
     * @return Builder<BackInStockSubscription>
     */
    protected function ownedBy( string $email, ?Customer $customer ): Builder
    {
        $email = $this->normalizeEmail( $email );

        return BackInStockSubscription::query()->where( static function ( Builder $query ) use ( $email, $customer ): void {
            $query->where( 'email', '' === $email ? "\0" : $email );

            if ( null !== $customer ) {
                $query->orWhere( 'customer_id', $customer->getKey() );
            }
        } );
    }

    /**
     * Lowercased, trimmed.
     *
     * @since 1.4.0
     *
     * @param  string  $email  Address.
     *
     * @return string
     */
    protected function normalizeEmail( string $email ): string
    {
        return mb_strtolower( trim( $email ) );
    }
}
