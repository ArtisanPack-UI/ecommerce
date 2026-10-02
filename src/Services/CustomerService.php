<?php

/**
 * CustomerService.
 *
 * Encapsulates the customer-side lookup and linking logic for the hybrid
 * guest-plus-registered flow described in engine spec §3.22:
 *
 * - {@see findOrCreateForEmail()} — returns the row for an email (creating a
 *   guest snapshot on first sighting) and fires
 *   `ap.ecommerce.customer.registered` when the row is new.
 * - {@see linkUser()} — back-fills `customers.user_id` on verified-email
 *   registration and fires `ap.ecommerce.customer.userLinked`.
 * - {@see delete()} — GDPR delete-and-anonymize (parent plan §19.6, engine
 *   issue #142): scrubs the customer's personal data from their orders and
 *   related rows, keeps every money column intact, deletes the customer, and
 *   fires `ap.ecommerce.customer.deleted`.
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

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\CustomerAddress;
use ArtisanPackUI\Ecommerce\Models\CustomerClaimAttempt;
use ArtisanPackUI\Ecommerce\Models\CustomerNote;
use ArtisanPackUI\Ecommerce\Models\CustomerNotificationPreference;
use ArtisanPackUI\Ecommerce\Models\DigitalDownload;
use ArtisanPackUI\Ecommerce\Models\DigitalDownloadEvent;
use ArtisanPackUI\Ecommerce\Models\IdempotencyRecord;
use ArtisanPackUI\Ecommerce\Models\LicenseActivation;
use ArtisanPackUI\Ecommerce\Models\LicenseKey;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderEdit;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\OrderNote;
use ArtisanPackUI\Ecommerce\Models\OrderTimelineEntry;
use ArtisanPackUI\Ecommerce\Models\ProductReview;
use ArtisanPackUI\Ecommerce\Models\PromotionUsage;
use ArtisanPackUI\Ecommerce\Models\Refund;
use ArtisanPackUI\Ecommerce\Models\WebhookDelivery;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class CustomerService
{
    /**
     * The email written onto an anonymized order (`orders.email` is not
     * nullable). The `.invalid` TLD can never receive mail.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const ANONYMIZED_EMAIL = 'anonymized@anonymized.invalid';

    /**
     * The author name written onto an anonymized review
     * (`product_reviews.author_name` is not nullable).
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const ANONYMIZED_NAME = 'Anonymous';

    /**
     * The value free text about the customer is replaced with.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const REDACTED = ActivityLogService::REDACTED;

    /**
     * Order fields the order-edit history snapshots that hold personal data,
     * with the value each is replaced by.
     *
     * @since 1.0.0
     *
     * @var array<string, string|null>
     */
    protected const ORDER_EDIT_PII_FIELDS = [
        'email'            => self::ANONYMIZED_EMAIL,
        'phone'            => null,
        'customer_note'    => null,
        'shipping_address' => null,
        'billing_address'  => null,
    ];

    /**
     * Timeline payload keys holding free text, redacted on anonymized orders
     * (note excerpts, status / edit / cancel / refund reasons, fraud
     * reasons).
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected const TIMELINE_TEXT_KEYS = [
        'excerpt',
        'reason',
        'reasons',
    ];

    /**
     * Keys whose values are redacted in outbound webhook payloads that
     * reference an anonymized order or the deleted customer.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected const WEBHOOK_PII_KEYS = [
        'email',
        'phone',
        'first_name',
        'last_name',
        'company',
        'address1',
        'address2',
        'shipping_address',
        'billing_address',
        'ip_address',
        'user_agent',
        'customer_note',
        'author_name',
        'author_email',
        'excerpt',
        'reason',
        'reasons',
        'body',
    ];

    /**
     * Route names (without the `ecommerce.api.` prefix) whose stored
     * idempotent responses echo the customer's personal data.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected const CUSTOMER_IDEMPOTENT_ROUTES = [
        'customers.update',
        'customers.addresses.store',
        'customers.addresses.update',
        'customers.addresses.destroy',
        'customers.notes.store',
        'customers.notes.destroy',
    ];

    /**
     * @since 1.0.0
     *
     * @param  ActivityLogService  $activity  Activity log.
     */
    public function __construct( protected ActivityLogService $activity )
    {
    }

    /**
     * Returns the customer row for `$email`, creating a guest snapshot on
     * first sighting. Fires `ap.ecommerce.customer.registered` when the row
     * is newly created.
     *
     * Email matching is case-insensitive: the stored `email` is normalized to
     * lowercase.
     *
     * @since 1.0.0
     *
     * @param  string               $email       Email to look up (or create).
     * @param  array<string, mixed> $attributes  Extra column values to seed on create.
     *
     * @return Customer
     */
    public function findOrCreateForEmail( string $email, array $attributes = [] ): Customer
    {
        $normalized = $this->normalizeEmail( $email );

        return DB::transaction( function () use ( $normalized, $attributes ): Customer {
            $existing = Customer::query()
                ->whereRaw( 'LOWER(email) = ?', [ $normalized ] )
                ->lockForUpdate()
                ->first();

            if ( null !== $existing ) {
                return $existing;
            }

            $customer = new Customer( array_merge( $attributes, [ 'email' => $normalized ] ) );
            $customer->save();

            doAction( 'ap.ecommerce.customer.registered', $customer );

            return $customer;
        } );
    }

    /**
     * Back-fills `customers.user_id` on verified-email registration.
     *
     * Idempotent: if the customer is already linked to `$user`, this is a
     * no-op. If the row is linked to a different user, this is also a no-op
     * (spoof-registration guard) — the caller is responsible for creating a
     * fresh customer row under a different email for the new user if needed.
     *
     * Fires `ap.ecommerce.customer.userLinked` when the link is written.
     *
     * @since 1.0.0
     *
     * @param  Authenticatable  $user  Auth user with `email` and a scalar key.
     *
     * @return Customer|null The linked (or already-linked) customer row, or
     *                       null when the user has no email or the row is
     *                       already claimed by a different user.
     */
    public function linkUser( Authenticatable $user ): ?Customer
    {
        $email = $this->extractEmail( $user );
        if ( null === $email ) {
            return null;
        }

        $userId   = (int) $user->getAuthIdentifier();
        $customer = $this->findOrCreateForEmail( $email );

        return DB::transaction( function () use ( $customer, $userId, $user ): ?Customer {
            $locked = Customer::query()->lockForUpdate()->findOrFail( $customer->id );

            if ( null !== $locked->user_id ) {
                return $locked->user_id === $userId ? $locked : null;
            }

            $locked->user_id = $userId;
            $locked->save();

            doAction( 'ap.ecommerce.customer.userLinked', $locked, $user );

            return $locked;
        } );
    }

    /**
     * Deletes `$customer` and anonymizes everything that pointed at them
     * (GDPR erasure, parent plan §19.6). Runs in one transaction with the
     * customer row locked:
     *
     * - The customer's orders — linked by `customer_id`, plus unlinked guest
     *   orders placed under the same email (case-insensitive) — keep every
     *   money column and line item, so revenue, tax, and refund reports are
     *   unchanged. `customer_id`, `phone`, `shipping_address`,
     *   `billing_address`, `ip_address`, `user_agent`, and `customer_note`
     *   are nulled and `email` becomes {@see self::ANONYMIZED_EMAIL}, so a
     *   later claim cannot re-attach them.
     * - Free text on those orders is redacted: order-note bodies, refund and
     *   order-edit reasons, and the `excerpt`, `reason`, and `reasons` values
     *   in their timeline entries. The personal fields inside the order-edit
     *   history (`pre_edit_snapshot`, `diff`) are scrubbed so a later rollback
     *   cannot restore them.
     * - Download events and license activations on those orders lose their
     *   IP address and user agent. Download links and license keys keep
     *   working.
     * - Outbound webhook deliveries that reference those orders or the
     *   customer have the personal values in their payload redacted and their
     *   stored receiver response dropped.
     * - Stored idempotent responses from the customer's own admin routes
     *   (customer update, address and note writes) are deleted.
     * - Addresses, notification preferences, and claim attempts are deleted.
     * - Carts (the customer's, and guest carts under the same email) keep
     *   their rows — they may hold stock reservations — but lose
     *   `customer_id` and `email`.
     * - Reviews (the customer's, and guest reviews under the same email) stay
     *   published but lose `customer_id` and `author_email`, and
     *   `author_name` becomes {@see self::ANONYMIZED_NAME}.
     * - Promotion usages keep their counts and lose `customer_id`.
     * - Customer notes are deleted and the customer's activity entries are
     *   scrubbed through {@see ActivityLogService::scrubCustomer()}; a
     *   `customer.deleted` entry with counts only (no personal data) is
     *   recorded for the audit trail.
     *
     * The linked user account, if any, is left alone — it belongs to the
     * host application.
     *
     * Once the outermost transaction commits, fires
     * `ap.ecommerce.customer.deleted` with the deleted `Customer` (attributes
     * as they were before deletion, so satellites can erase their own
     * copies) and the summary counts.
     *
     * @since 1.0.0
     *
     * @param  Customer  $customer     Customer to delete.
     * @param  int|null  $actorUserId  Acting user id; defaults to the signed-in user.
     *
     * @return void
     */
    public function delete( Customer $customer, ?int $actorUserId = null ): void
    {
        DB::transaction( function () use ( $customer, $actorUserId ): void {
            $locked = Customer::query()->lockForUpdate()->findOrFail( $customer->id );

            $summary = $this->activity->withoutRecording( fn (): array => $this->anonymize( $locked ) );

            $this->activity->scrubCustomer( $locked );

            $this->activity->record( $locked, 'customer.deleted', [
                'anonymized' => true,
                'orders'     => $summary['orders'],
            ], $actorUserId );

            DB::afterCommit( static fn () => doAction( 'ap.ecommerce.customer.deleted', $customer, $summary ) );
        } );
    }

    /**
     * Does the anonymizing writes for {@see delete()}. Must run inside
     * {@see ActivityLogService::withoutRecording()} with the customer row
     * locked.
     *
     * @since 1.0.0
     *
     * @param  Customer  $customer  Customer being deleted (locked).
     *
     * @return array{orders: int, addresses: int, notification_preferences: int, claim_attempts: int, notes: int, carts: int, reviews: int, promotion_usages: int}
     */
    protected function anonymize( Customer $customer ): array
    {
        $email  = $this->normalizeEmail( (string) $customer->email );
        $orders = $this->anonymizeOrders( $customer, $email );
        $notes  = CustomerNote::query()->where( 'customer_id', $customer->id )->count();

        $summary = [
            'orders'                   => count( $orders ),
            'addresses'                => CustomerAddress::query()->where( 'customer_id', $customer->id )->delete(),
            'notification_preferences' => CustomerNotificationPreference::query()->where( 'customer_id', $customer->id )->delete(),
            'claim_attempts'           => CustomerClaimAttempt::query()->where( 'customer_id', $customer->id )->delete(),
            'notes'                    => $notes,
            'carts'                    => Cart::query()
                ->where( fn ( $query ) => $this->ownedOrGuest( $query, $customer, $email, 'email' ) )
                ->update( [ 'customer_id' => null, 'email' => null ] ),
            'reviews'                  => ProductReview::query()
                ->where( fn ( $query ) => $this->ownedOrGuest( $query, $customer, $email, 'author_email' ) )
                ->update( [
                    'customer_id'  => null,
                    'author_name'  => self::ANONYMIZED_NAME,
                    'author_email' => null,
                ] ),
            'promotion_usages'         => PromotionUsage::query()->where( 'customer_id', $customer->id )->update( [ 'customer_id' => null ] ),
        ];

        $this->scrubWebhookDeliveries( $orders, (int) $customer->id );
        $this->forgetIdempotentResponses( (int) $customer->id );

        $customer->delete();

        return $summary;
    }

    /**
     * Anonymizes the customer's orders and the order-side records that hold
     * their personal data. Repeats until no matching order is left, so an
     * order linked to the customer while this runs is not missed.
     *
     * @since 1.0.0
     *
     * @param  Customer  $customer  Customer being deleted (locked).
     * @param  string    $email     The customer's normalized email.
     *
     * @return array<int, int> Ids of every order anonymized.
     */
    protected function anonymizeOrders( Customer $customer, string $email ): array
    {
        $all = [];

        for ( $pass = 0; $pass < 5; $pass++ ) {
            $ids = Order::query()
                ->where( fn ( $query ) => $this->ownedOrGuest( $query, $customer, $email, 'email' ) )
                ->lockForUpdate()
                ->pluck( 'id' )
                ->map( static fn ( $id ): int => (int) $id )
                ->all();

            if ( [] === $ids ) {
                break;
            }

            Order::query()->whereKey( $ids )->update( [
                'customer_id'      => null,
                'email'            => self::ANONYMIZED_EMAIL,
                'phone'            => null,
                'shipping_address' => null,
                'billing_address'  => null,
                'ip_address'       => null,
                'user_agent'       => null,
                'customer_note'    => null,
            ] );

            OrderNote::query()->whereIn( 'order_id', $ids )->update( [ 'body' => self::REDACTED ] );

            DB::table( ( new Refund() )->getTable() )
                ->whereIn( 'order_id', $ids )
                ->whereNotNull( 'reason' )
                ->update( [ 'reason' => self::REDACTED ] );

            $this->scrubOrderTimeline( $ids );
            $this->scrubOrderEdits( $ids );
            $this->scrubDigitalTraces( $ids );

            $all = array_merge( $all, $ids );
        }

        return array_values( array_unique( $all ) );
    }

    /**
     * Matches rows linked to the customer, or unlinked guest rows whose
     * email column matches theirs.
     *
     * @since 1.0.0
     *
     * @param  Builder<Model>  $query        Query to constrain.
     * @param  Customer        $customer     Customer.
     * @param  string          $email        The customer's normalized email.
     * @param  string          $emailColumn  Column holding the guest's email.
     *
     * @return void
     */
    protected function ownedOrGuest( Builder $query, Customer $customer, string $email, string $emailColumn ): void
    {
        $query->where( 'customer_id', $customer->id );

        if ( '' !== $email ) {
            $query->orWhere( function ( Builder $guest ) use ( $email, $emailColumn ): void {
                $guest->whereNull( 'customer_id' )->whereRaw( 'LOWER(' . $emailColumn . ') = ?', [ $email ] );
            } );
        }
    }

    /**
     * Redacts free text in the orders' timeline entries: note excerpts,
     * status / edit / cancel / refund reasons, and fraud reasons. Goes
     * through the base query builder because the timeline is append-only.
     *
     * @since 1.0.0
     *
     * @param  array<int, int>  $orderIds  Order ids.
     *
     * @return void
     */
    protected function scrubOrderTimeline( array $orderIds ): void
    {
        $table = ( new OrderTimelineEntry() )->getTable();

        $rows = DB::table( $table )->whereIn( 'order_id', $orderIds )->get( [ 'id', 'payload' ] );

        foreach ( $rows as $row ) {
            $payload = is_string( $row->payload ) ? json_decode( $row->payload, true ) : null;

            if ( ! is_array( $payload ) ) {
                continue;
            }

            $clean = $this->redactKeys( $payload, self::TIMELINE_TEXT_KEYS, false );

            if ( $clean !== $payload ) {
                DB::table( $table )->where( 'id', $row->id )->update( [ 'payload' => json_encode( $clean ) ] );
            }
        }
    }

    /**
     * Scrubs the reason and the personal order fields from the orders' edit
     * history, so rolling an edit back cannot restore erased values. Goes
     * through the base query builder because the edit history is
     * append-only.
     *
     * @since 1.0.0
     *
     * @param  array<int, int>  $orderIds  Order ids.
     *
     * @return void
     */
    protected function scrubOrderEdits( array $orderIds ): void
    {
        $table = ( new OrderEdit() )->getTable();

        $rows = DB::table( $table )->whereIn( 'order_id', $orderIds )->get( [ 'id', 'reason', 'diff', 'pre_edit_snapshot' ] );

        foreach ( $rows as $row ) {
            $changes  = [];
            $snapshot = is_string( $row->pre_edit_snapshot ) ? json_decode( $row->pre_edit_snapshot, true ) : null;
            $diff     = is_string( $row->diff ) ? json_decode( $row->diff, true ) : null;

            if ( null !== $row->reason && self::REDACTED !== $row->reason ) {
                $changes['reason'] = self::REDACTED;
            }

            if ( is_array( $snapshot ) && is_array( $snapshot['fields'] ?? null ) ) {
                $clean = $snapshot;

                foreach ( self::ORDER_EDIT_PII_FIELDS as $field => $replacement ) {
                    if ( array_key_exists( $field, $clean['fields'] ) ) {
                        $clean['fields'][ $field ] = $replacement;
                    }
                }

                if ( $clean !== $snapshot ) {
                    $changes['pre_edit_snapshot'] = json_encode( $clean );
                }
            }

            if ( is_array( $diff ) && is_array( $diff['fields'] ?? null ) ) {
                $clean = $diff;

                foreach ( self::ORDER_EDIT_PII_FIELDS as $field => $replacement ) {
                    if ( array_key_exists( $field, $clean['fields'] ) ) {
                        $clean['fields'][ $field ] = [ 'before' => $replacement, 'after' => $replacement ];
                    }
                }

                if ( $clean !== $diff ) {
                    $changes['diff'] = json_encode( $clean );
                }
            }

            if ( [] !== $changes ) {
                DB::table( $table )->where( 'id', $row->id )->update( $changes );
            }
        }
    }

    /**
     * Drops the IP address and user agent from download events and license
     * activations on the orders.
     *
     * @since 1.0.0
     *
     * @param  array<int, int>  $orderIds  Order ids.
     *
     * @return void
     */
    protected function scrubDigitalTraces( array $orderIds ): void
    {
        $itemIds = OrderItem::query()->whereIn( 'order_id', $orderIds )->pluck( 'id' )->all();

        if ( [] === $itemIds ) {
            return;
        }

        $downloadIds = DigitalDownload::query()->whereIn( 'order_item_id', $itemIds )->pluck( 'id' )->all();

        if ( [] !== $downloadIds ) {
            DigitalDownloadEvent::query()->whereIn( 'digital_download_id', $downloadIds )->update( [ 'ip_address' => null, 'user_agent' => null ] );
        }

        $licenseIds = LicenseKey::query()->whereIn( 'order_item_id', $itemIds )->pluck( 'id' )->all();

        if ( [] !== $licenseIds ) {
            LicenseActivation::query()->whereIn( 'license_key_id', $licenseIds )->update( [ 'ip_address' => null ] );
        }
    }

    /**
     * Redacts personal values in outbound webhook deliveries that reference
     * the anonymized orders or the customer, and drops the receiver's stored
     * response. The rows stay, so delivery history and counts are kept;
     * `payload_hash` is recomputed for the redacted payload.
     *
     * @since 1.0.0
     *
     * @param  array<int, int>  $orderIds    Anonymized order ids.
     * @param  int              $customerId  Customer id.
     *
     * @return void
     */
    protected function scrubWebhookDeliveries( array $orderIds, int $customerId ): void
    {
        $table  = ( new WebhookDelivery() )->getTable();
        $orders = array_flip( $orderIds );

        DB::table( $table )->select( [ 'id', 'payload' ] )->orderBy( 'id' )->chunk( 500, function ( $rows ) use ( $table, $orders, $customerId ): void {
            foreach ( $rows as $row ) {
                $payload = is_string( $row->payload ) ? json_decode( $row->payload, true ) : null;

                if ( ! is_array( $payload ) || ! $this->referencesSubject( $payload, $orders, $customerId ) ) {
                    continue;
                }

                $clean = $this->redactKeys( $payload, self::WEBHOOK_PII_KEYS, true );

                DB::table( $table )->where( 'id', $row->id )->update( [
                    'payload'       => json_encode( $clean, WebhookDispatcher::JSON_FLAGS ),
                    'payload_hash'  => hash( 'sha256', (string) json_encode( $clean, WebhookDispatcher::JSON_FLAGS ) ),
                    'response_body' => null,
                ] );
            }
        } );
    }

    /**
     * Whether a webhook payload mentions one of the orders or the customer.
     *
     * @since 1.0.0
     *
     * @param  array<array-key, mixed>  $data        Payload (or part of it).
     * @param  array<int, int>          $orders      Order ids, as keys.
     * @param  int                      $customerId  Customer id.
     *
     * @return bool
     */
    protected function referencesSubject( array $data, array $orders, int $customerId ): bool
    {
        $type = $data['type'] ?? null;
        $id   = is_numeric( $data['id'] ?? null ) ? (int) $data['id'] : null;

        if ( null !== $id && ( ( 'order' === $type && isset( $orders[ $id ] ) ) || ( 'customer' === $type && $id === $customerId ) ) ) {
            return true;
        }

        if ( is_numeric( $data['order_id'] ?? null ) && isset( $orders[ (int) $data['order_id'] ] ) ) {
            return true;
        }

        if ( is_numeric( $data['customer_id'] ?? null ) && (int) $data['customer_id'] === $customerId ) {
            return true;
        }

        foreach ( $data as $value ) {
            if ( is_array( $value ) && $this->referencesSubject( $value, $orders, $customerId ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Deletes the stored idempotent responses of the customer's own admin
     * routes (customer update, address and note writes), which echo the
     * customer's personal data. They are identified by route name and by
     * the customer id in the stored response body.
     *
     * Order routes are left alone: their records guard money-moving actions
     * against a replay, and expire after the idempotency TTL.
     *
     * @since 1.0.0
     *
     * @param  int  $customerId  Customer id.
     *
     * @return void
     */
    protected function forgetIdempotentResponses( int $customerId ): void
    {
        $endpoints = array_map( static fn ( string $name ): string => 'ecommerce.api.' . $name, self::CUSTOMER_IDEMPOTENT_ROUTES );

        $ids = [];

        IdempotencyRecord::query()
            ->whereIn( 'endpoint_key', $endpoints )
            ->whereNotNull( 'response_body' )
            ->select( [ 'id', 'response_body' ] )
            ->chunkById( 500, function ( $records ) use ( $customerId, &$ids ): void {
                foreach ( $records as $record ) {
                    $body = json_decode( (string) $record->response_body, true );
                    $data = is_array( $body ) ? ( $body['data'] ?? null ) : null;

                    if ( ! is_array( $data ) ) {
                        continue;
                    }

                    $isCustomer = 'customer' === ( $data['type'] ?? null ) && $customerId === (int) ( $data['id'] ?? 0 );
                    $isOwned    = is_numeric( $data['customer_id'] ?? null ) && $customerId === (int) $data['customer_id'];

                    if ( $isCustomer || $isOwned ) {
                        $ids[] = (int) $record->id;
                    }
                }
            } );

        if ( [] !== $ids ) {
            IdempotencyRecord::query()->whereKey( $ids )->delete();
        }
    }

    /**
     * Replaces the values of `$keys` anywhere in `$data`, keeping nulls and
     * the shape of lists.
     *
     * @since 1.0.0
     *
     * @param  array<array-key, mixed>  $data       Data.
     * @param  array<int, string>       $keys       Keys whose values are personal.
     * @param  bool                     $toNull     Replace structured values (arrays) with null instead of redacting each leaf.
     *
     * @return array<array-key, mixed>
     */
    protected function redactKeys( array $data, array $keys, bool $toNull ): array
    {
        foreach ( $data as $key => $value ) {
            if ( is_string( $key ) && in_array( $key, $keys, true ) ) {
                $data[ $key ] = match ( true ) {
                    null === $value               => null,
                    is_array( $value ) && $toNull => null,
                    is_array( $value )            => array_map( static fn ( mixed $item ): mixed => null === $item ? null : self::REDACTED, $value ),
                    default                       => self::REDACTED,
                };

                continue;
            }

            if ( is_array( $value ) ) {
                $data[ $key ] = $this->redactKeys( $value, $keys, $toNull );
            }
        }

        return $data;
    }

    /**
     * Normalizes an email to lowercase for consistent lookup.
     *
     * @since 1.0.0
     *
     * @param  string  $email
     *
     * @return string
     */
    protected function normalizeEmail( string $email ): string
    {
        return strtolower( trim( $email ) );
    }

    /**
     * Extracts the email from an authenticatable, tolerating missing fields.
     *
     * @since 1.0.0
     *
     * @param  Authenticatable  $user
     *
     * @return string|null
     */
    protected function extractEmail( Authenticatable $user ): ?string
    {
        $email = null;

        if ( isset( $user->email ) && is_string( $user->email ) ) {
            $email = $user->email;
        } elseif ( method_exists( $user, 'getEmailForVerification' ) ) {
            $candidate = $user->getEmailForVerification();
            if ( is_string( $candidate ) ) {
                $email = $candidate;
            }
        }

        if ( null === $email || '' === trim( $email ) ) {
            return null;
        }

        return $email;
    }
}
