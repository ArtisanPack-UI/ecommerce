<?php

/**
 * FraudProvider contract.
 *
 * Pre-capture fraud assessment adapter. Called by
 * {@see \ArtisanPackUI\Ecommerce\Services\PaymentOrchestrator::finalize()}
 * between the authorize and capture steps to decide whether the payment
 * should proceed, needs a step-up challenge (e.g. 3DS), or must be blocked
 * outright. Engine spec §4.17.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Contracts;

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\ValueObjects\Address;
use ArtisanPackUI\Ecommerce\ValueObjects\FraudDecision;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
interface FraudProvider
{
    /**
     * Machine-readable provider key (e.g. `stripe-radar`, `always-approve`).
     *
     * Matches the key the provider is registered under in
     * {@see \ArtisanPackUI\Ecommerce\Registries\FraudProviderRegistry}.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function key(): string;

    /**
     * Human-readable label displayed to admins in the fraud-provider picker.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function label(): string;

    /**
     * Assesses the fraud risk of a pending payment.
     *
     * Implementations MUST return a {@see FraudDecision} — including for
     * transient errors — rather than throw, so the orchestrator can record
     * the assessment attempt on the order timeline. A provider that cannot
     * reach its upstream API should return an approve verdict with a
     * `provider_error` reason so downstream policy can decide whether
     * fail-open or fail-closed is appropriate.
     *
     * @since 1.0.0
     *
     * @param  Cart            $cart      The cart being checked out.
     * @param  Address         $shipping  The shipping destination.
     * @param  PaymentSession  $session   The authorized (not-yet-captured) session under review.
     *
     * @return FraudDecision
     */
    public function assess( Cart $cart, Address $shipping, PaymentSession $session ): FraudDecision;
}
