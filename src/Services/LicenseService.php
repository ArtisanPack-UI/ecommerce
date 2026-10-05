<?php

/**
 * LicenseService.
 *
 * Issues, validates, and revokes software license keys (parent plan §5.13,
 * engine spec §3.27).
 *
 * {@see self::validate()} backs `POST license/validate`: the key is looked
 * up and its exact bytes re-checked with `hash_equals()`; each (normalized)
 * fingerprint not seen
 * before takes an activation slot under a row lock (so racing machines
 * can't overshoot `activations_limit`) and fires `ap.ecommerce.license.activated`
 * and {@see LicenseActivated}. The result runs through
 * `ap.ecommerce.license.validating` before it is returned.
 *
 * Which products issue keys is set per product in
 * `products.meta.licensing`: `{ "enabled": true, "activations_limit": 5,
 * "expires_in_days": 365 }` (limit / expiry fall back to
 * `artisanpack.ecommerce.licenses.*`).
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

use ArtisanPackUI\Ecommerce\Events\LicenseActivated;
use ArtisanPackUI\Ecommerce\Events\LicenseDeactivated;
use ArtisanPackUI\Ecommerce\Events\LicenseIssued;
use ArtisanPackUI\Ecommerce\Events\LicenseRevoked;
use ArtisanPackUI\Ecommerce\Models\DigitalFile;
use ArtisanPackUI\Ecommerce\Models\LicenseActivation;
use ArtisanPackUI\Ecommerce\Models\LicenseKey;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Support\AfterCommit;
use ArtisanPackUI\Ecommerce\Support\Timestamp;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class LicenseService
{
    /**
     * Characters used in generated keys (no 0/O or 1/I look-alikes).
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * Issues a license key for `$item`.
     *
     * @since 1.0.0
     *
     * @param  OrderItem               $item              Order line.
     * @param  DigitalFile|null        $file              File the key unlocks, if any.
     * @param  int|null                $activationsLimit  Machines allowed (null = unlimited).
     * @param  DateTimeInterface|null  $expiresAt         Expiry (null = never).
     *
     * @throws RuntimeException When no unique key could be generated.
     *
     * @return LicenseKey
     */
    public function issue( OrderItem $item, ?DigitalFile $file = null, ?int $activationsLimit = null, ?DateTimeInterface $expiresAt = null ): LicenseKey
    {
        $key = LicenseKey::query()->create( [
            'order_item_id'     => $item->id,
            'digital_file_id'   => $file?->id,
            'key'               => $this->uniqueKey(),
            'activations_limit' => $activationsLimit,
            'expires_at'        => $expiresAt,
            'meta'              => [],
        ] );

        AfterCommit::action( 'ap.ecommerce.license.issued', $key, $item );
        Event::dispatch( new LicenseIssued( $key, $item ) );

        return $key;
    }

    /**
     * Issues one key per line of `$order` whose product has licensing
     * enabled and that has no key yet. The line's quantity multiplies the
     * activation limit. Safe to call more than once.
     *
     * @since 1.0.0
     *
     * @param  Order  $order  Paid order.
     *
     * @return array<int, LicenseKey> The newly issued keys.
     */
    public function issueForOrder( Order $order ): array
    {
        $issued = [];

        foreach ( $order->items()->with( 'product' )->get() as $item ) {
            $settings = (array) ( $item->product?->meta['licensing'] ?? [] );

            if ( true !== ( $settings['enabled'] ?? false ) || $item->licenseKeys()->exists() ) {
                continue;
            }

            $limit = $settings['activations_limit'] ?? config( 'artisanpack.ecommerce.licenses.activations_limit', 5 );
            $days  = $settings['expires_in_days'] ?? config( 'artisanpack.ecommerce.licenses.expires_in_days' );

            $issued[] = $this->issue(
                $item,
                null,
                is_numeric( $limit ) && (int) $limit > 0 ? (int) $limit * max( 1, $item->quantity ) : null,
                is_numeric( $days ) && (int) $days > 0 ? now()->addDays( (int) $days ) : null,
            );
        }

        return $issued;
    }

    /**
     * Validates `$key` for the machine identified by `$fingerprint`,
     * activating the machine if it is new and a slot is free.
     *
     * @since 1.0.0
     *
     * @param  string       $key          Key as sent by the customer's app.
     * @param  string       $fingerprint  Machine fingerprint.
     * @param  string|null  $ipAddress    Caller IP (stored on the activation).
     *
     * @return array{valid: bool, expires_at: string|null, product: array{id: int|null, name: string|null}|null, revoked: bool, reason: string|null}
     */
    public function validate( string $key, string $fingerprint, ?string $ipAddress = null ): array
    {
        $normalized  = LicenseKey::normalize( $key );
        $fingerprint = self::normalizeFingerprint( $fingerprint );
        $license     = $this->findByKey( $normalized );

        if ( null === $license ) {
            return $this->result( $key, $fingerprint, null, false, 'not-found' );
        }

        $activation = null;

        $reason = DB::transaction( function () use ( $license, $fingerprint, $ipAddress, &$activation ): ?string {
            /** @var LicenseKey $locked */
            $locked = LicenseKey::query()->lockForUpdate()->findOrFail( $license->id );

            if ( $locked->is_revoked ) {
                return 'revoked';
            }

            if ( $locked->isExpired() ) {
                return 'expired';
            }

            $existing = $locked->activations()->where( 'machine_fingerprint', $fingerprint )->first();

            if ( null !== $existing ) {
                $existing->forceFill( [ 'last_seen_at' => now(), 'ip_address' => $ipAddress ?? $existing->ip_address ] )->save();

                return null;
            }

            if ( ! $locked->hasActivationsRemaining() ) {
                return 'activation-limit-reached';
            }

            $activation = $locked->activations()->create( [
                'machine_fingerprint' => $fingerprint,
                'activated_at'        => now(),
                'last_seen_at'        => now(),
                'ip_address'          => $ipAddress,
            ] );

            $locked->increment( 'activations_count' );

            return null;
        } );

        if ( $activation instanceof LicenseActivation ) {
            AfterCommit::action( 'ap.ecommerce.license.activated', $activation );
            Event::dispatch( new LicenseActivated( $activation ) );
        }

        return $this->result( $key, $fingerprint, $license->refresh(), null === $reason, $reason );
    }

    /**
     * Frees the activation slot `$fingerprint` holds on `$license` (the
     * customer moved to a new machine). Works on revoked and expired keys
     * too, so their records can be tidied. Fires
     * `ap.ecommerce.license.deactivated` and {@see LicenseDeactivated}.
     *
     * @since 1.0.0
     *
     * @param  LicenseKey  $license      Key.
     * @param  string      $fingerprint  Machine fingerprint.
     *
     * @return bool Whether the machine was activated (and now isn't).
     */
    public function deactivate( LicenseKey $license, string $fingerprint ): bool
    {
        $fingerprint = self::normalizeFingerprint( $fingerprint );

        $removed = DB::transaction( function () use ( $license, $fingerprint ): bool {
            /** @var LicenseKey $locked */
            $locked  = LicenseKey::query()->lockForUpdate()->findOrFail( $license->id );
            $deleted = $locked->activations()->where( 'machine_fingerprint', $fingerprint )->delete();

            if ( 0 === $deleted ) {
                return false;
            }

            $locked->forceFill( [ 'activations_count' => max( 0, (int) $locked->activations_count - $deleted ) ] )->save();

            return true;
        } );

        if ( $removed ) {
            $license->refresh();

            AfterCommit::action( 'ap.ecommerce.license.deactivated', $license, $fingerprint );
            Event::dispatch( new LicenseDeactivated( $license, $fingerprint ) );
        }

        return $removed;
    }

    /**
     * Deactivates `$fingerprint` on the license with `$key`, for the public
     * endpoint.
     *
     * @since 1.0.0
     *
     * @param  string  $key          Key as sent by the customer's app.
     * @param  string  $fingerprint  Machine fingerprint.
     *
     * @return array{deactivated: bool, activations_count: int|null, activations_limit: int|null, reason: string|null}
     */
    public function deactivateByKey( string $key, string $fingerprint ): array
    {
        $license = $this->findByKey( $key );

        if ( null === $license ) {
            return [ 'deactivated' => false, 'activations_count' => null, 'activations_limit' => null, 'reason' => 'not-found' ];
        }

        $deactivated = $this->deactivate( $license, $fingerprint );

        return [
            'deactivated'       => $deactivated,
            'activations_count' => (int) $license->activations_count,
            'activations_limit' => null === $license->activations_limit ? null : (int) $license->activations_limit,
            'reason'            => $deactivated ? null : 'not-activated',
        ];
    }

    /**
     * Finds a license by its key, through `key_hash` (keys are stored
     * encrypted). A key hashed under a previous app key is found too and
     * re-hashed under the current one.
     *
     * @since 1.0.0
     *
     * @param  string  $key  Key, as typed.
     *
     * @return LicenseKey|null
     */
    public function findByKey( string $key ): ?LicenseKey
    {
        $normalized = LicenseKey::normalize( $key );
        $license    = LicenseKey::query()->with( 'orderItem' )->whereIn( 'key_hash', LicenseKey::hashCandidates( $normalized ) )->first();

        // The hash decides the match; hash_equals() re-checks the decrypted
        // key in constant time. Guessing is infeasible anyway (25 characters
        // from 32 is ~125 bits) and validation is rate-limited.
        if ( null === $license || ! hash_equals( (string) $license->key, $normalized ) ) {
            return null;
        }

        if ( LicenseKey::hashFor( $normalized ) !== $license->key_hash ) {
            $license->forceFill( [ 'key_hash' => LicenseKey::hashFor( $normalized ) ] )->saveQuietly();
        }

        return $license;
    }

    /**
     * Normalizes a machine fingerprint (trimmed, lower-cased) so the same
     * machine always matches its activation, whatever the column collation.
     *
     * @since 1.0.0
     *
     * @param  string  $fingerprint  Fingerprint as sent.
     *
     * @return string
     */
    public static function normalizeFingerprint( string $fingerprint ): string
    {
        return strtolower( trim( $fingerprint ) );
    }

    /**
     * Revokes `$license`; later validations report `revoked: true`.
     *
     * @since 1.0.0
     *
     * @param  LicenseKey   $license  Key to revoke.
     * @param  string|null  $reason   Why (kept in `meta.revoked_reason`).
     *
     * @return LicenseKey
     */
    public function revoke( LicenseKey $license, ?string $reason = null ): LicenseKey
    {
        if ( $license->is_revoked ) {
            return $license;
        }

        $meta = (array) $license->meta;

        if ( null !== $reason && '' !== $reason ) {
            $meta['revoked_reason'] = $reason;
        }

        $license->forceFill( [ 'is_revoked' => true, 'revoked_at' => now(), 'meta' => $meta ] )->save();

        AfterCommit::action( 'ap.ecommerce.license.revoked', $license, $reason );
        Event::dispatch( new LicenseRevoked( $license, $reason ) );

        return $license;
    }

    /**
     * Generates a key such as `K7QM2-XW9RT-…` (5 groups of 5).
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function generateKey(): string
    {
        $groups = [];

        for ( $group = 0; $group < 5; $group++ ) {
            $chars = '';

            for ( $i = 0; $i < 5; $i++ ) {
                $chars .= self::ALPHABET[ random_int( 0, strlen( self::ALPHABET ) - 1 ) ];
            }

            $groups[] = $chars;
        }

        return implode( '-', $groups );
    }

    /**
     * A generated key no existing row uses.
     *
     * @since 1.0.0
     *
     * @throws RuntimeException When no unique key could be generated.
     *
     * @return string
     */
    protected function uniqueKey(): string
    {
        for ( $attempt = 0; $attempt < 5; $attempt++ ) {
            $key = $this->generateKey();

            if ( ! LicenseKey::query()->whereIn( 'key_hash', LicenseKey::hashCandidates( $key ) )->exists() ) {
                return $key;
            }
        }

        throw new RuntimeException( 'Could not generate a unique license key.' );
    }

    /**
     * Builds (and filters) the validation response.
     *
     * @since 1.0.0
     *
     * @param  string           $key          Key as sent.
     * @param  string           $fingerprint  Fingerprint as sent.
     * @param  LicenseKey|null  $license      Matched key.
     * @param  bool             $valid        Whether the key is valid for this machine.
     * @param  string|null      $reason       Why not, when invalid.
     *
     * @return array{valid: bool, expires_at: string|null, product: array{id: int|null, name: string|null}|null, revoked: bool, reason: string|null}
     */
    protected function result( string $key, string $fingerprint, ?LicenseKey $license, bool $valid, ?string $reason ): array
    {
        $item = $license?->orderItem;

        return (array) applyFilters( 'ap.ecommerce.license.validating', [
            'valid'      => $valid,
            'expires_at' => Timestamp::format( $license?->expires_at ),
            'product'    => null === $item ? null : [
                'id'   => $item->product_id,
                'name' => $item->product_snapshot['name'] ?? null,
            ],
            'revoked'    => (bool) ( $license?->is_revoked ?? false ),
            'reason'     => $valid ? null : $reason,
        ], $key, $fingerprint );
    }
}
