<?php

/**
 * PendingCartMerge.
 *
 * A login merge that is waiting on the shopper: the guest cart and the
 * account cart are in different currencies, so the storefront asks which
 * {@see CartMergeResolution} to apply. Kept in the session under
 * {@see self::SESSION_KEY} by {@see \ArtisanPackUI\Ecommerce\Services\CurrentCart}.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\ValueObjects;

use ArtisanPackUI\Ecommerce\Exceptions\CartCurrencyMismatchException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class PendingCartMerge
{
    /**
     * Session key the pending merge is stored under.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const SESSION_KEY = 'ecommerce.cart_merge_pending';

    /**
     * @since 1.0.0
     *
     * @param  string  $guestToken       Guest cart token.
     * @param  int     $accountCartId    Account cart id.
     * @param  string  $guestCurrency    Guest cart currency.
     * @param  string  $accountCurrency  Account cart currency.
     */
    public function __construct(
        public readonly string $guestToken,
        public readonly int $accountCartId,
        public readonly string $guestCurrency,
        public readonly string $accountCurrency,
    ) {
    }

    /**
     * Builds the pending merge from the mismatch the merge raised.
     *
     * @since 1.0.0
     *
     * @param  CartCurrencyMismatchException  $exception  Mismatch.
     *
     * @return self
     */
    public static function fromException( CartCurrencyMismatchException $exception ): self
    {
        return new self(
            (string) $exception->guestCart->token,
            (int) $exception->destinationCart->id,
            $exception->guestCurrency(),
            $exception->destinationCurrency(),
        );
    }

    /**
     * Restores a pending merge from its session array, or null when the
     * value isn't one.
     *
     * @since 1.0.0
     *
     * @param  mixed  $data  Stored value.
     *
     * @return self|null
     */
    public static function fromArray( mixed $data ): ?self
    {
        if ( ! is_array( $data ) || ! isset( $data['guest_token'], $data['account_cart_id'], $data['guest_currency'], $data['account_currency'] ) ) {
            return null;
        }

        return new self( (string) $data['guest_token'], (int) $data['account_cart_id'], (string) $data['guest_currency'], (string) $data['account_currency'] );
    }

    /**
     * The choices the storefront can offer.
     *
     * @since 1.0.0
     *
     * @return array<int, CartMergeResolution>
     */
    public function resolutions(): array
    {
        return CartMergeResolution::cases();
    }

    /**
     * Session form. The guest token stays server-side; storefronts render
     * {@see self::toPublicArray()}.
     *
     * @since 1.0.0
     *
     * @return array{guest_token: string, account_cart_id: int, guest_currency: string, account_currency: string}
     */
    public function toArray(): array
    {
        return [
            'guest_token'      => $this->guestToken,
            'account_cart_id'  => $this->accountCartId,
            'guest_currency'   => $this->guestCurrency,
            'account_currency' => $this->accountCurrency,
        ];
    }

    /**
     * What a storefront needs to present the choice.
     *
     * @since 1.0.0
     *
     * @return array{guest_currency: string, account_currency: string, resolutions: array<int, string>}
     */
    public function toPublicArray(): array
    {
        return [
            'guest_currency'   => $this->guestCurrency,
            'account_currency' => $this->accountCurrency,
            'resolutions'      => array_map( static fn ( CartMergeResolution $resolution ): string => $resolution->value, $this->resolutions() ),
        ];
    }
}
